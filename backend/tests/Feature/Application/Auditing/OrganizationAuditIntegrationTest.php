<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @param array<string, mixed>|null $before
 * @param  array<string, mixed>|null  $after
 */
function assertOrganizationAuditFact(string $tenant, int $actor, string $action, string $subjectType, string $subjectId, ?array $before, ?array $after): void
{
    $row = DB::table('audit_events')->where('organization_id', $tenant)->where('action', $action)->where('subject_id', $subjectId)->sole();
    expect(array_keys((array) $row))->toEqualCanonicalizing([
        'id', 'organization_id', 'actor_type', 'actor_user_id', 'action', 'subject_type',
        'subject_id', 'before', 'after', 'payload_version', 'created_at',
    ]);
    expect($row->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
    expect($row->organization_id)->toBe($tenant);
    expect($row->actor_type)->toBe('user');
    expect($row->actor_user_id)->toBe($actor);
    expect($row->action)->toBe($action);
    expect($row->subject_type)->toBe($subjectType);
    expect($row->subject_id)->toBe($subjectId);
    expect($row->before === null ? null : json_decode($row->before, true, flags: JSON_THROW_ON_ERROR))->toEqual($before);
    expect($row->after === null ? null : json_decode($row->after, true, flags: JSON_THROW_ON_ERROR))->toEqual($after);
    expect($row->payload_version)->toBe(1);
    expect($row->created_at)->toBeString()->not->toBeEmpty();
    expect((new DateTimeImmutable($row->created_at))->getTimestamp())->toBeGreaterThan(0);
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->organization = app(CreateOrganization::class)->handle($this->owner->id, 'Original');
    // Raw role fixture isolates the transition under test; organization setup uses the real command.
    $this->role = $this->organization->roles()->create(['name' => 'Original role']);
    $this->role->permissions()->sync(['roles.view']);
});

it('records creation with the explicit owner and persisted bootstrap membership exactly once', function () {
    $this->actingAs($this->other);
    $org = app(CreateOrganization::class)->handle($this->owner->id, 'Explicit owner');
    $membership = $org->memberships()->sole();
    expect($membership->user_id)->toBe($this->owner->id);
    expect(DB::table('audit_events')->where('organization_id', $org->id)->count())->toBe(1);
    assertOrganizationAuditFact($org->id, $this->owner->id, 'organization.created', 'organization', $org->id, null, [
        'name' => 'Explicit owner', 'owner_user_id' => $this->owner->id, 'owner_membership_id' => $membership->id,
    ]);
});

it('records rename from fresh locked state and safely synchronizes the supplied instance', function () {
    $supplied = $this->organization;
    DB::table('organizations')->where('id', $supplied->id)->update(['name' => 'Persisted B']);
    $supplied->owner_user_id = $this->other->id;
    $this->actingAs($this->other);
    $result = app(RenameOrganization::class)->handle($this->owner->id, $supplied, 'Requested C');
    expect($result)->toBe($supplied);
    expect($supplied->name)->toBe('Requested C');
    expect($supplied->owner_user_id)->toBe($this->owner->id);
    expect($supplied->isDirty())->toBeFalse();
    expect($supplied->fresh()->name)->toBe('Requested C');
    assertOrganizationAuditFact($supplied->id, $this->owner->id, 'organization.renamed', 'organization', $supplied->id,
        ['name' => 'Persisted B'], ['name' => 'Requested C']);
});

it('uses the authorized delegated actor rather than the owner or ambient session', function () {
    $member = $this->organization->memberships()->create(['user_id' => $this->other->id]);
    $this->role->permissions()->sync(['organizations.update']);
    $member->roles()->attach($this->role->id, ['organization_id' => $this->organization->id]);
    $this->actingAs($this->owner);
    app(RenameOrganization::class)->handle($this->other->id, $this->organization, 'Delegated');
    assertOrganizationAuditFact($this->organization->id, $this->other->id, 'organization.renamed', 'organization', $this->organization->id,
        ['name' => 'Original'], ['name' => 'Delegated']);
});

it('does not record a rename no-op even when the supplied name is stale', function () {
    DB::table('organizations')->where('id', $this->organization->id)->update(['name' => 'Already current']);
    $before = DB::table('audit_events')->count();
    $result = app(RenameOrganization::class)->handle($this->owner->id, $this->organization, 'Already current');
    expect($result)->toBe($this->organization);
    expect($result->name)->toBe('Already current');
    expect(DB::table('audit_events')->count())->toBe($before);
});

it('records role creation after grants with sorted unique persisted permission keys', function () {
    $this->actingAs($this->other);
    $role = app(SaveRole::class)->handle($this->owner->id, $this->organization, null, '  Editors  ',
        PermissionKey::RolesView, PermissionKey::OrganizationsUpdate, PermissionKey::RolesView);
    expect($role->relationLoaded('permissions'))->toBeTrue();
    expect($role->permissions)->toHaveCount(2);
    assertOrganizationAuditFact($this->organization->id, $this->owner->id, 'role.created', 'role', $role->id, null,
        ['name' => 'Editors', 'permissions' => ['organizations.update', 'roles.view']]);
});

it('records only meaningfully changed role fields', function (string $name, array $permissions, array $before, array $after) {
    $role = app(SaveRole::class)->handle($this->owner->id, $this->organization, $this->role, $name, ...$permissions);
    assertOrganizationAuditFact($this->organization->id, $this->owner->id, 'role.updated', 'role', $role->id, $before, $after);
})->with([
    'name only' => ['Renamed', [PermissionKey::RolesView], ['name' => 'Original role'], ['name' => 'Renamed']],
    'permissions only' => ['Original role', [PermissionKey::OrganizationsUpdate], ['permissions' => ['roles.view']], ['permissions' => ['organizations.update']]],
    'both' => ['Renamed', [PermissionKey::OrganizationsUpdate], ['name' => 'Original role', 'permissions' => ['roles.view']], ['name' => 'Renamed', 'permissions' => ['organizations.update']]],
    'remove all permissions' => ['Original role', [], ['permissions' => ['roles.view']], ['permissions' => []]],
]);

it('captures role before-state from the locked database row rather than stale loaded attributes or grants', function () {
    $this->role->load('permissions');
    DB::table('roles')->where('id', $this->role->id)->update(['name' => 'Persisted B']);
    $this->role->permissions()->sync(['members.view']);
    $role = app(SaveRole::class)->handle($this->owner->id, $this->organization, $this->role, 'Requested C', PermissionKey::OrganizationsUpdate);
    assertOrganizationAuditFact($this->organization->id, $this->owner->id, 'role.updated', 'role', $role->id,
        ['name' => 'Persisted B', 'permissions' => ['members.view']], ['name' => 'Requested C', 'permissions' => ['organizations.update']]);
});

it('does not record a role update with the same trimmed name and normalized permission set', function () {
    $this->role->permissions()->sync(['roles.view', 'organizations.update']);
    $before = DB::table('audit_events')->count();
    $role = app(SaveRole::class)->handle($this->owner->id, $this->organization, $this->role, ' Original role ',
        PermissionKey::RolesView, PermissionKey::OrganizationsUpdate, PermissionKey::RolesView);
    expect($role->name)->toBe('Original role');
    expect($role->permissions)->toHaveCount(2);
    expect(DB::table('audit_events')->count())->toBe($before);
});

it('leaves no successful fact on duplicate role creation or update', function (bool $update) {
    $this->organization->roles()->create(['name' => 'Taken']);
    $before = DB::table('audit_events')->count();
    expect(fn () => app(SaveRole::class)->handle($this->owner->id, $this->organization, $update ? $this->role : null, ' tAKEN ', PermissionKey::OrganizationsUpdate))
        ->toThrow(RoleNameConflict::class);
    expect(DB::table('audit_events')->count())->toBe($before);
    expect($this->role->fresh()->name)->toBe('Original role');
    expect($this->role->permissions()->pluck('key')->all())->toBe(['roles.view']);
    expect($this->organization->roles()->count())->toBe(2);
})->with(['create' => false, 'update' => true]);

it('rolls back each command on audit failure including a real successful insert followed by failure', function (string $operation, bool $insertFirst) {
    $org = $this->organization;
    $counts = [DB::table('organizations')->count(), DB::table('organization_memberships')->count(), DB::table('roles')->count(), DB::table('role_permission')->count(), DB::table('audit_events')->count()];
    $real = app(AuditRecorder::class);
    $recorder = new class($real, $insertFirst) implements AuditRecorder
    {
        public ?AuditEntry $observed = null;

        public function __construct(private AuditRecorder $real, private bool $insertFirst) {}

        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            $this->observed = $entry;
            expect(DB::transactionLevel())->toBeGreaterThan(1);
            expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
            // The command has already written the business result before invoking us.
            $table = $entry->subject->type->value === 'organization' ? 'organizations' : 'roles';
            expect(DB::table($table)->where('id', $entry->subject->id)->value('name'))->toBe($entry->after['name']);
            if ($table === 'roles') {
                expect(DB::table('role_permission')->where('role_id', $entry->subject->id)->pluck('permission_key')->all())->toBe(['organizations.update']);
            }
            if ($this->insertFirst) {
                $this->real->record($entry);
                expect(DB::table('audit_events')->where('organization_id', $entry->organizationId)->where('action', $entry->action->value)->where('subject_id', $entry->subject->id)->count())->toBe(1);
                throw new RuntimeException('Deliberate failure after real audit insertion');
            }
            throw new AuditWriteFailed('persistence_failed');
        }
    };
    $this->app->instance(AuditRecorder::class, $recorder);
    $invoke = fn () => match ($operation) {
        'create organization' => app(CreateOrganization::class)->handle($this->owner->id, 'Failed organization'),
        'rename' => app(RenameOrganization::class)->handle($this->owner->id, $org, 'Failed rename'),
        'create role' => app(SaveRole::class)->handle($this->owner->id, $org, null, 'Failed role', PermissionKey::OrganizationsUpdate),
        'update role' => app(SaveRole::class)->handle($this->owner->id, $org, $this->role, 'Failed role', PermissionKey::OrganizationsUpdate),
    };
    expect($invoke)->toThrow($insertFirst ? RuntimeException::class : AuditWriteFailed::class,
        $insertFirst ? 'Deliberate failure after real audit insertion' : 'Audit recording failed.');
    expect($recorder->observed)->toBeInstanceOf(AuditEntry::class);
    expect([DB::table('organizations')->count(), DB::table('organization_memberships')->count(), DB::table('roles')->count(), DB::table('role_permission')->count(), DB::table('audit_events')->count()])->toBe($counts);
    expect($org->fresh()->name)->toBe('Original');
    expect($org->name)->toBe('Original');
    expect($this->role->fresh()->name)->toBe('Original role');
    expect($this->role->permissions()->pluck('key')->all())->toBe(['roles.view']);
})->with(['create organization', 'rename', 'create role', 'update role'])->with(['before insertion' => false, 'after real insertion' => true]);

it('does not emit facts for direct unauthorized or cross-tenant writes despite dirty actor attributes', function () {
    $before = DB::table('audit_events')->count();
    $this->actingAs($this->owner);
    $this->organization->owner_user_id = $this->other->id;
    expect(fn () => app(RenameOrganization::class)->handle($this->other->id, $this->organization, 'Denied'))->toThrow(AccessDenied::class);
    expect(fn () => app(SaveRole::class)->handle($this->other->id, $this->organization, null, 'Denied'))->toThrow(AccessDenied::class);
    $foreignOrg = app(CreateOrganization::class)->handle($this->other->id, 'Other tenant');
    $foreignRole = $foreignOrg->roles()->create(['name' => 'Foreign']);
    $foreignRole->organization_id = $this->organization->id;
    expect(fn () => app(SaveRole::class)->handle($this->owner->id, $this->organization, $foreignRole, 'Stolen'))->toThrow(ModelNotFoundException::class);
    expect(DB::table('audit_events')->count())->toBe($before + 1);
    expect($this->organization->fresh()->name)->toBe('Original');
    expect($foreignRole->fresh()->name)->toBe('Foreign');
});

it('uses HTTP actor context and ignores client audit attribution fields', function () {
    $before = DB::table('audit_events')->count();
    $this->actingAs($this->owner)->patchJson('/api/v1/organizations/'.$this->organization->id, [
        'name' => 'Rejected', 'owner_user_id' => $this->other->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
    expect(DB::table('audit_events')->count())->toBe($before);
    $this->actingAs($this->owner)->patchJson('/api/v1/organizations/'.$this->organization->id, [
        'name' => 'HTTP rename', 'actor_user_id' => $this->other->id,
        'subject_id' => 'forged', 'organization_id' => 'forged',
    ])->assertOk()->assertExactJson(['data' => ['id' => $this->organization->id, 'name' => 'HTTP rename']]);
    assertOrganizationAuditFact($this->organization->id, $this->owner->id, 'organization.renamed', 'organization', $this->organization->id,
        ['name' => 'Original'], ['name' => 'HTTP rename']);
});

it('restricts hard deletion of an organization created by the audited command', function () {
    expect(fn () => DB::transaction(fn () => $this->organization->delete()))->toThrow(function (QueryException $failure) {
        expect($failure->getCode())->toBe('23001');
        expect($failure->getMessage())->toContain('audit_events_organization_id_foreign');
    });
    expect($this->organization->fresh())->not->toBeNull();
    expect(DB::table('audit_events')->where('organization_id', $this->organization->id)->count())->toBe(1);
});
