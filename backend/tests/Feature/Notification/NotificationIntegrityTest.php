<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NotificationFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $owner = User::factory()->create();
    $this->organization = OrganizationFixtures::unaudited($owner->id, 'Notification integrity');
    $this->recipient = User::factory()->create();
    $this->membership = $this->organization->memberships()->create(['user_id' => $this->recipient->id]);
    $this->notificationValues = [
        'id' => (string) Str::ulid(), 'organization_id' => $this->organization->id,
        'recipient_user_id' => $this->recipient->id, 'recipient_membership_id' => $this->membership->id,
        'type' => 'organization.invitation_accepted', 'payload_version' => 1,
        'payload' => json_encode(NotificationFixtures::draft()->payload, JSON_THROW_ON_ERROR),
        'title' => 'Invitation accepted', 'body' => 'User #29 accepted an invitation and joined the organization.',
        'target_type' => 'organization.users', 'target_id' => null,
    ];
});

it('rejects invalid raw structural data independently of Application validation', function (array $overrides, string $constraint) {
    try {
        DB::transaction(fn () => DB::table('organization_notifications')->insert(array_replace($this->notificationValues, $overrides)));
        test()->fail('Expected structural rejection.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe('23514');
        expect($failure->getMessage())->toContain($constraint);
    }
})->with([
    'malformed ULID' => [['id' => 'not-a-ulid'], 'notifications_id_check'],
    'overflowing ULID' => [['id' => '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'], 'notifications_id_check'],
    'malformed organization' => [['organization_id' => 'not-a-ulid'], 'notifications_organization_id_check'],
    'zero user' => [['recipient_user_id' => 0], 'notifications_recipient_user_check'],
    'negative user' => [['recipient_user_id' => -1], 'notifications_recipient_user_check'],
    'zero membership' => [['recipient_membership_id' => 0], 'notifications_recipient_membership_check'],
    'negative membership' => [['recipient_membership_id' => -1], 'notifications_recipient_membership_check'],
    'unstable type' => [['type' => 'App\\Models\\Notification'], 'notifications_type_check'],
    'blank type' => [['type' => ''], 'notifications_type_check'],
    'type with whitespace' => [['type' => 'organization. invitation_accepted'], 'notifications_type_check'],
    'zero version' => [['payload_version' => 0], 'notifications_payload_version_check'],
    'negative version' => [['payload_version' => -1], 'notifications_payload_version_check'],
    'JSON number' => [['payload' => '42'], 'notifications_payload_object_check'],
    'JSON string' => [['payload' => '"value"'], 'notifications_payload_object_check'],
    'JSON list' => [['payload' => '[]'], 'notifications_payload_object_check'],
    'JSON null' => [['payload' => 'null'], 'notifications_payload_object_check'],
    'oversized JSON' => [['payload' => json_encode(['x' => str_repeat('a', 8192)], JSON_THROW_ON_ERROR)], 'notifications_payload_size_check'],
    'empty title' => [['title' => ''], 'notifications_title_check'],
    'whitespace title' => [['title' => " \t\n"], 'notifications_title_check'],
    'empty body' => [['body' => ''], 'notifications_body_check'],
    'whitespace body' => [['body' => " \t\n"], 'notifications_body_check'],
    'unknown target' => [['target_type' => 'organization'], 'notifications_target_check'],
    'URL target' => [['target_type' => 'https://example.test'], 'notifications_target_check'],
    'users with ID' => [['target_id' => '47'], 'notifications_target_check'],
    'users with empty ID' => [['target_id' => ''], 'notifications_target_check'],
    'null type with ID' => [['target_type' => null, 'target_id' => '47'], 'notifications_target_check'],
    'read before creation' => [['created_at' => '2026-10-03 12:00:00+00', 'read_at' => '2026-10-03 11:59:59.999999+00'], 'notifications_read_at_check'],
]);

it('enforces required columns and bounded text sizes', function (array $overrides, string $sqlState) {
    try {
        DB::transaction(fn () => DB::table('organization_notifications')->insert(array_replace($this->notificationValues, $overrides)));
        test()->fail('Expected storage rejection.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe($sqlState);
    }
})->with([
    'missing recipient membership' => [['recipient_membership_id' => null], '23502'],
    'missing payload' => [['payload' => null], '23502'],
    'missing title' => [['title' => null], '23502'],
    'missing body' => [['body' => null], '23502'],
    'long title' => [['title' => str_repeat('a', 161)], '22001'],
    'long body' => [['body' => str_repeat('a', 1001)], '22001'],
    'long type' => [['type' => 'organization.'.str_repeat('a', 81)], '22001'],
    'malformed JSON' => [['payload' => '{'], '22P02'],
]);

it('enforces both live foreign keys', function (string $reference) {
    $values = array_replace($this->notificationValues, $reference === 'organization'
        ? ['organization_id' => (string) Str::ulid()]
        : ['recipient_user_id' => DB::table('users')->max('id') + 10000]);
    try {
        DB::transaction(fn () => DB::table('organization_notifications')->insert($values));
        test()->fail('Expected missing reference rejection.');
    } catch (QueryException $failure) {
        expect($failure->getCode())->toBe('23503');
        expect($failure->getMessage())->toContain($reference === 'organization'
            ? 'organization_notifications_organization_id_foreign'
            : 'organization_notifications_recipient_user_id_foreign');
    }
})->with(['organization', 'recipient']);

it('uses the exact canonical JSONB byte boundary', function () {
    // PostgreSQL renders {"x": "..."}: nine bytes around the ASCII value.
    $values = array_replace($this->notificationValues, ['payload' => json_encode(['x' => str_repeat('a', 8183)], JSON_THROW_ON_ERROR)]);
    DB::table('organization_notifications')->insert($values);
    expect(DB::selectOne('SELECT octet_length(payload::text) AS bytes FROM organization_notifications WHERE id = ?', [$values['id']])->bytes)->toBe(8192);
    $values['id'] = (string) Str::ulid();
    $values['payload'] = json_encode(['x' => str_repeat('a', 8184)], JSON_THROW_ON_ERROR);
    expect(fn () => DB::transaction(fn () => DB::table('organization_notifications')->insert($values)))->toThrow(QueryException::class, 'notifications_payload_size_check');
});

it('supports no target and read timestamps at or after creation', function (string $readAt) {
    DB::table('organization_notifications')->insert(array_replace($this->notificationValues, [
        'target_type' => null, 'target_id' => null,
        'created_at' => '2026-10-03 12:00:00+00', 'read_at' => $readAt,
    ]));
    expect(DB::table('organization_notifications')->where('id', $this->notificationValues['id'])->sole()->target_type)->toBeNull();
})->with(['2026-10-03 12:00:00+00', '2026-10-03 12:00:00.000001+00']);

it('retains a positive historical membership scope without a live membership FK', function () {
    DB::table('organization_notifications')->insert(array_replace($this->notificationValues, ['recipient_membership_id' => PHP_INT_MAX]));
    $this->membership->delete();
    expect(DB::table('organization_notifications')->where('id', $this->notificationValues['id'])->sole()->recipient_membership_id)->toBe(PHP_INT_MAX);
});

it('cascades organization or recipient deletion for user-facing history', function (string $reference) {
    DB::table('organization_notifications')->insert($this->notificationValues);
    if ($reference === 'organization') {
        DB::table('organizations')->where('id', $this->organization->id)->delete();
    } else {
        DB::table('users')->where('id', $this->recipient->id)->delete();
    }
    expect(DB::table('organization_notifications')->where('id', $this->notificationValues['id'])->count())->toBe(0);
})->with(['organization', 'recipient']);

it('installs only the era-scoped indexes and explicit storage columns', function () {
    $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'organization_notifications')->pluck('indexdef', 'indexname');
    expect($indexes)->toHaveCount(4);
    expect($indexes['notifications_recipient_chronology_index'])->toContain('(organization_id, recipient_user_id, recipient_membership_id, created_at DESC, id DESC)');
    expect($indexes['notifications_recipient_unread_index'])->toContain('(organization_id, recipient_user_id, recipient_membership_id)', 'WHERE (read_at IS NULL)');
    expect($indexes['notifications_recipient_user_index'])->toContain('(recipient_user_id)');
    expect(Schema::getColumnListing('organization_notifications'))->toBe([
        'id', 'organization_id', 'recipient_user_id', 'recipient_membership_id', 'type', 'payload_version',
        'payload', 'title', 'body', 'target_type', 'target_id', 'read_at', 'created_at',
    ]);
    $column = DB::selectOne("SELECT data_type, datetime_precision, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'organization_notifications' AND column_name = 'created_at'");
    expect($column->data_type)->toBe('timestamp with time zone');
    expect($column->datetime_precision)->toBe(6);
    expect($column->column_default)->toBe('clock_timestamp()');
    $foreignKeys = DB::select("SELECT pg_get_constraintdef(oid) AS definition, confdeltype, confupdtype FROM pg_constraint WHERE conrelid = 'organization_notifications'::regclass AND contype = 'f'");
    expect($foreignKeys)->toHaveCount(2);
    foreach ($foreignKeys as $foreignKey) {
        expect($foreignKey->confdeltype)->toBe('c');
        expect($foreignKey->confupdtype)->toBe('r');
        expect($foreignKey->definition)->not->toContain('recipient_membership_id');
    }
});

it('uses physical database recording time instead of transaction start time', function () {
    DB::select('SELECT pg_sleep(0.01)');
    DB::table('organization_notifications')->insert($this->notificationValues);
    expect(DB::selectOne('SELECT created_at > transaction_timestamp() AS later FROM organization_notifications WHERE id = ?', [$this->notificationValues['id']])->later)->toBeTrue();
});

it('mechanically rolls back and reapplies only the new notification migration', function () {
    // Test DDL and removed rows are restored by the Feature test's outer rollback.
    $migration = require database_path('migrations/2026_10_03_000001_create_organization_notifications_table.php');
    DB::table('organization_notifications')->insert($this->notificationValues);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $migration->down();
    expect(Schema::hasTable('organization_notifications'))->toBeFalse();
    expect(Schema::hasTable('audit_events'))->toBeTrue();
    $migration->up();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    expect(Schema::hasTable('organization_notifications'))->toBeTrue();
    expect(DB::table('organization_notifications')->where('id', $this->notificationValues['id'])->exists())->toBeFalse();
    DB::table('organization_notifications')->insert($this->notificationValues);
    expect(fn () => DB::transaction(fn () => DB::table('organization_notifications')->insert(array_replace($this->notificationValues, [
        'id' => (string) Str::ulid(), 'recipient_membership_id' => 0,
    ]))))->toThrow(QueryException::class, 'notifications_recipient_membership_check');
    expect(DB::table('pg_indexes')->where('tablename', 'organization_notifications')->count())->toBe(4);
});
