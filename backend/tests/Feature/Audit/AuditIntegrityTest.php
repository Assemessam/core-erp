<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = app(CreateOrganization::class)->handle($this->owner->id, 'Audit integrity');
    // Separate from owner to prove audit's actor FK, not the pre-existing ownership FK.
    $this->actor = User::factory()->create();
    $this->auditValues = [
        'id' => (string) Str::ulid(), 'organization_id' => $this->organization->id,
        'actor_type' => 'user', 'actor_user_id' => $this->actor->id,
        'action' => 'organization.renamed', 'subject_type' => 'organization',
        'subject_id' => $this->organization->id, 'before' => '{"name":"Old"}',
        'after' => '{"name":"New"}', 'payload_version' => 1,
    ];
});

it('permits insertion but rejects statement-level mutation including zero matching rows', function (string $sql) {
    DB::table('audit_events')->insert($this->auditValues);
    try {
        DB::transaction(fn () => DB::statement($sql));
        $this->fail('Expected append-only rejection.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe('55000');
        expect($failure->getMessage())->toContain('audit_events is append-only');
    }
    expect(DB::table('audit_events')->where('id', $this->auditValues['id'])->count())->toBe(1);
})->with([
    'update' => 'UPDATE audit_events SET action = action',
    'delete' => 'DELETE FROM audit_events',
    'empty update' => 'UPDATE audit_events SET action = action WHERE false',
    'empty delete' => 'DELETE FROM audit_events WHERE false',
    'truncate' => 'TRUNCATE TABLE audit_events',
]);

it('rejects invalid raw structural data independently of Application validation', function (array $overrides, string $constraint) {
    try {
        DB::transaction(fn () => DB::table('audit_events')->insert(array_replace($this->auditValues, $overrides)));
        $this->fail('Expected structural rejection.');
    } catch (QueryException $failure) {
        expect($failure->getMessage())->toContain($constraint);
    }
})->with([
    'missing user' => [['actor_user_id' => null], 'audit_events_actor_check'],
    'system with user' => [['actor_type' => 'system'], 'audit_events_actor_check'],
    'unknown actor' => [['actor_type' => 'robot'], 'audit_events_actor_check'],
    'zero actor' => [['actor_user_id' => 0], 'audit_events_actor_check'],
    'negative actor' => [['actor_user_id' => -1], 'audit_events_actor_check'],
    'bad ULID' => [['id' => '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'], 'audit_events_id_check'],
    'invalid action' => [['action' => 'forged action'], 'audit_events_action_check'],
    'unknown subject' => [['subject_type' => 'App\\Models\\Role'], 'audit_events_subject_type_check'],
    'malformed subject ID' => [['subject_id' => 'malformed'], 'audit_events_subject_id_check'],
    'zero membership ID' => [['subject_type' => 'membership', 'subject_id' => '0'], 'audit_events_subject_id_check'],
    'overflowing membership ID' => [['subject_type' => 'membership', 'subject_id' => '9223372036854775808'], 'audit_events_subject_id_check'],
    'nonpositive version' => [['payload_version' => 0], 'audit_events_payload_version_check'],
    'scalar before' => [['before' => '42'], 'audit_events_snapshots_check'],
    'string after' => [['after' => '"value"'], 'audit_events_snapshots_check'],
    'list before' => [['before' => '[]'], 'audit_events_snapshots_check'],
    'list after' => [['after' => '[1,2]'], 'audit_events_snapshots_check'],
    'JSON null' => [['before' => 'null'], 'audit_events_snapshots_check'],
    'no snapshots' => [['before' => null, 'after' => null], 'audit_events_snapshots_check'],
    'oversized JSON' => [['after' => json_encode(['name' => str_repeat('a', 65536)], JSON_THROW_ON_ERROR)], 'audit_events_payload_size_check'],
    'combined JSON size' => [['before' => json_encode(['name' => str_repeat('a', 32768)], JSON_THROW_ON_ERROR), 'after' => json_encode(['name' => str_repeat('a', 32768)], JSON_THROW_ON_ERROR)], 'audit_events_payload_size_check'],
]);

it('enforces tenant and actor references without any polymorphic subject FK', function (string $reference) {
    $values = $this->auditValues;
    if ($reference === 'organization') {
        $values['organization_id'] = (string) Str::ulid();
    } else {
        $values['actor_user_id'] = DB::table('users')->max('id') + 10000;
    }
    try {
        DB::transaction(fn () => DB::table('audit_events')->insert($values));
        $this->fail('Expected missing reference rejection.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe('23503');
        expect($failure->getMessage())->toContain($reference === 'organization' ? 'audit_events_organization_id_foreign' : 'audit_events_actor_user_id_foreign');
    }
})->with(['organization', 'actor']);

it('allows system actors and retains subjects that no longer exist', function () {
    DB::table('audit_events')->insert(array_replace($this->auditValues, [
        'actor_type' => 'system', 'actor_user_id' => null, 'subject_type' => 'membership', 'subject_id' => '9223372036854775807',
    ]));
    $row = DB::table('audit_events')->where('id', $this->auditValues['id'])->sole();
    expect($row->actor_user_id)->toBeNull();
    expect($row->subject_id)->toBe('9223372036854775807');
});

it('restricts referenced organization and actor deletion without changing history', function (string $reference) {
    DB::table('audit_events')->insert($this->auditValues);
    try {
        DB::transaction(fn () => $reference === 'organization'
            ? DB::table('organizations')->where('id', $this->organization->id)->delete()
            : DB::table('users')->where('id', $this->actor->id)->delete());
        $this->fail('Expected audit reference restriction.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe('23001');
        expect($failure->getMessage())->toContain($reference === 'organization' ? 'audit_events_organization_id_foreign' : 'audit_events_actor_user_id_foreign');
    }
    expect(DB::table('audit_events')->where('id', $this->auditValues['id'])->sole()->actor_user_id)->toBe($this->actor->id);
})->with(['organization', 'actor']);

it('uses real database recording time rather than the start of a waiting transaction', function () {
    DB::select('SELECT pg_sleep(0.01)');
    DB::table('audit_events')->insert($this->auditValues);
    $row = DB::selectOne('SELECT created_at > transaction_timestamp() AS later FROM audit_events WHERE id = ?', [$this->auditValues['id']]);
    expect($row->later)->toBeTrue();
});

it('installs only the justified indexes and statement-level append-only triggers', function () {
    $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'audit_events')->pluck('indexdef', 'indexname');
    expect($indexes)->toHaveCount(5);
    expect($indexes['audit_events_organization_chronology_index'])->toContain('(organization_id, created_at DESC, id DESC)');
    expect($indexes['audit_events_organization_action_index'])->toContain('(organization_id, action, created_at DESC, id DESC)');
    expect($indexes['audit_events_organization_subject_index'])->toContain('(organization_id, subject_type, subject_id, created_at DESC, id DESC)');
    expect($indexes['audit_events_actor_user_id_index'])->toContain('(actor_user_id)');
    $triggers = DB::select("SELECT pg_get_triggerdef(oid) AS definition FROM pg_trigger WHERE tgrelid = 'audit_events'::regclass AND NOT tgisinternal");
    expect($triggers)->toHaveCount(2);
    foreach ($triggers as $trigger) {
        expect($trigger->definition)->toContain('FOR EACH STATEMENT');
    }
    $columns = DB::select("SELECT data_type, datetime_precision, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'audit_events' AND column_name = 'created_at'");
    expect($columns[0]->data_type)->toBe('timestamp with time zone');
    expect($columns[0]->datetime_precision)->toBe(6);
    expect($columns[0]->column_default)->toBe('clock_timestamp()');
});

it('mechanically rolls back and reapplies audit schema including function and triggers', function () {
    // DDL and any removed rows are restored by this Feature test's outer rollback.
    // No triggers are disabled and no audit deletion operation is used for fixture cleanup.
    $migration = require database_path('migrations/2026_10_01_000002_create_audit_events_table.php');
    DB::table('audit_events')->insert($this->auditValues);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $migration->down();
    expect(Schema::hasTable('audit_events'))->toBeFalse();
    expect(DB::selectOne("SELECT to_regprocedure('reject_audit_events_mutation()') AS function")->function)->toBeNull();
    $migration->up();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    expect(Schema::hasTable('audit_events'))->toBeTrue();
    expect(DB::table('audit_events')->count())->toBe(0);
    DB::table('audit_events')->insert($this->auditValues);
    expect(fn () => DB::transaction(fn () => DB::statement('DELETE FROM audit_events WHERE false')))->toThrow(QueryException::class);
});
