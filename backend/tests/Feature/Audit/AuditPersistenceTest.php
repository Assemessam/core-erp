<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditActor;
use App\Modules\Audit\Application\Data\AuditSubject;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;
use App\Modules\Audit\Infrastructure\Persistence\DatabaseAuditRecorder;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuditFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = app(CreateOrganization::class)->handle($this->owner->id, 'Audit fixture');
    $this->actor = User::factory()->create();
    $this->entry = AuditFixtures::entry([
        'organizationId' => $this->organization->id,
        'subject' => new AuditSubject(AuditSubjectType::Organization, $this->organization->id),
        'actor' => AuditActor::user($this->actor->id),
    ]);
});

it('binds the public recorder and inserts deliberate columns inside the caller transaction', function () {
    $recorder = app(AuditRecorder::class);
    expect($recorder)->toBeInstanceOf(DatabaseAuditRecorder::class);
    $level = DB::transactionLevel();
    $recorder->record($this->entry);
    expect(DB::transactionLevel())->toBe($level);
    $row = DB::table('audit_events')->where('organization_id', $this->organization->id)->sole();
    expect($row->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
    expect($row->actor_type)->toBe('user');
    expect($row->actor_user_id)->toBe($this->actor->id);
    expect($row->action)->toBe('organization.renamed');
    expect($row->subject_type)->toBe('organization');
    expect($row->subject_id)->toBe($this->organization->id);
    expect(json_decode($row->before, true))->toBe(['name' => 'Old']);
    expect(json_decode($row->after, true))->toBe(['name' => 'New']);
    expect($row->payload_version)->toBe(1);
    expect($row->created_at)->not->toBeNull();
    expect(array_keys((array) $row))->not->toContain('updated_at', 'deleted_at', 'metadata');
});

it('rolls back a successful audit insertion when the enclosing workflow fails', function () {
    $count = DB::table('audit_events')->count();
    expect(fn () => DB::transaction(function (): void {
        DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
        app(AuditRecorder::class)->record($this->entry);
        expect(DB::table('audit_events')->where('organization_id', $this->organization->id)->count())->toBe(1);
        throw new RuntimeException('Workflow rejected');
    }))->toThrow(RuntimeException::class, 'Workflow rejected');
    expect(DB::table('audit_events')->count())->toBe($count);
    expect($this->organization->fresh()->name)->toBe('Audit fixture');
});

it('fails safely and rolls back earlier writes when audit persistence rejects a foreign actor', function () {
    $missingId = DB::table('users')->max('id') + 10000;
    $entry = AuditFixtures::entry([
        'organizationId' => $this->organization->id, 'actor' => AuditActor::user($missingId),
        'subject' => new AuditSubject(AuditSubjectType::Organization, $this->organization->id),
        'after' => ['name' => 'sensitive-business-name'],
    ]);
    $count = DB::table('audit_events')->count();
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        DB::transaction(function () use ($entry): void {
            DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
            app(AuditRecorder::class)->record($entry);
        });
        $this->fail('Expected FK rejection.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->category)->toBe('persistence_failed');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Audit recording failed.');
        expect((string) $failure)->not->toContain('sensitive-business-name')->not->toContain('insert into');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
    expect(DB::table('audit_events')->count())->toBe($count);
    expect($this->organization->fresh()->name)->toBe('Audit fixture');
});

it('rejects unsafe payloads before any audit insert and leaves earlier transaction writes rolled back', function () {
    $count = DB::table('audit_events')->count();
    expect(fn () => DB::transaction(function (): void {
        DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Changed']);
        app(AuditRecorder::class)->record(AuditFixtures::entry([
            'organizationId' => $this->organization->id,
            'subject' => new AuditSubject(AuditSubjectType::Organization, $this->organization->id),
            'after' => ['name' => 'New', 'token_hash' => 'never-persist-this'],
        ]));
    }))->toThrow(AuditWriteFailed::class);
    expect(DB::table('audit_events')->count())->toBe($count);
    expect($this->organization->fresh()->name)->toBe('Audit fixture');
});

it('records system actors and independently scopes facts to the supplied tenant', function () {
    $other = app(CreateOrganization::class)->handle($this->owner->id, 'Other tenant');
    app(AuditRecorder::class)->record($this->entry);
    app(AuditRecorder::class)->record(AuditFixtures::entry([
        'organizationId' => $other->id,
        'subject' => new AuditSubject(AuditSubjectType::Organization, $other->id),
        'actor' => AuditActor::system(),
    ]));
    $first = DB::table('audit_events')->where('organization_id', $this->organization->id)->sole();
    $second = DB::table('audit_events')->where('organization_id', $other->id)->sole();
    expect($first->actor_user_id)->toBe($this->actor->id);
    expect($second->actor_type)->toBe('system');
    expect($second->actor_user_id)->toBeNull();
    expect($first->id)->not->toBe($second->id);
});

it('leaves existing organization workflows uninstrumented in checkpoint B', function () {
    expect(DB::table('audit_events')->where('organization_id', $this->organization->id)->count())->toBe(0);
});
