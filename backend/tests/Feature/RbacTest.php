<?php

use App\Actions\AssignMembershipRole;
use App\Actions\CreateOrganization;
use App\Actions\SaveRole;
use App\Enums\PermissionKey;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function rbacOrganization(?User $owner = null): Organization
{
    return app(CreateOrganization::class)->handle($owner ?? User::factory()->create(), 'RBAC');
}

it('requires verified authentication on all RBAC endpoints', function () {
    $organization = rbacOrganization();
    $role = $organization->roles()->create(['name' => 'Viewer']);
    $base = '/api/v1/organizations/'.$organization->id;
    foreach ([['get', '/roles'], ['get', '/permissions'], ['post', '/roles'], ['patch', '/roles/'.$role->id]] as [$method, $path]) {
        $this->{$method.'Json'}($base.$path)->assertUnauthorized();
    }
    $this->actingAs(User::factory()->unverified()->create());
    foreach ([['get', '/roles'], ['get', '/permissions'], ['post', '/roles'], ['patch', '/roles/'.$role->id]] as [$method, $path]) {
        $this->{$method.'Json'}($base.$path)->assertForbidden();
    }
});

it('lets the owner create and edit roles with stable minimal representations', function () {
    $organization = rbacOrganization();
    $this->actingAs($organization->owner);
    $base = '/api/v1/organizations/'.$organization->id;
    $response = $this->postJson($base.'/roles', ['name' => ' Editors ', 'permissions' => ['organizations.update']])->assertCreated();
    $role = $organization->roles()->sole();
    $response->assertExactJson(['data' => ['id' => $role->id, 'name' => 'Editors', 'permissions' => ['organizations.update']]]);
    $this->patchJson($base.'/roles/'.$role->id, ['name' => 'Readers', 'permissions' => ['roles.view']])
        ->assertOk()->assertExactJson(['data' => ['id' => $role->id, 'name' => 'Readers', 'permissions' => ['roles.view']]]);
    $this->patchJson($base.'/roles/'.$role->id, ['name' => 'Readers', 'permissions' => []])->assertOk();
    expect($role->permissions()->count())->toBe(0);
    $this->getJson($base.'/permissions')->assertExactJson(['data' => [
        ['key' => 'organizations.update', 'label' => 'Update organization details'],
        ['key' => 'roles.view', 'label' => 'View roles and permissions'],
    ]]);
    $this->getJson($base.'/roles')->assertExactJson([
        'data' => [['id' => $role->id, 'name' => 'Readers', 'permissions' => []]],
        'meta' => ['can_manage' => true],
    ]);
});

it('validates names keys duplicates and spoofed fields without partial writes', function () {
    $organization = rbacOrganization();
    $this->actingAs($organization->owner);
    $base = '/api/v1/organizations/'.$organization->id.'/roles';
    $role = $organization->roles()->create(['name' => 'Editors']);
    foreach ([
        ['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 81)], ['name' => ['invalid']],
        ['name' => ' eDiToRs '], ['permissions' => ['invented.manage']],
        ['permissions' => ['roles.view', 'roles.view']], ['permissions' => 'roles.view'], ['permissions' => ['key' => 'roles.view']],
        ['organization_id' => rbacOrganization()->id], ['id' => $role->id], ['owner_user_id' => 99],
    ] as $invalid) {
        $this->postJson($base, array_replace(['name' => 'Valid', 'permissions' => []], $invalid))->assertUnprocessable();
    }
    $this->postJson($base, ['name' => 'Missing permissions'])->assertUnprocessable();
    $this->patchJson($base.'/'.$role->id, ['name' => 'Changed', 'permissions' => ['unknown']])->assertUnprocessable();
    expect($role->fresh()->name)->toBe('Editors');
    expect($organization->roles()->count())->toBe(1);
    $other = rbacOrganization($organization->owner);
    $this->postJson('/api/v1/organizations/'.$other->id.'/roles', ['name' => 'Editors', 'permissions' => []])->assertCreated();
    $second = $organization->roles()->create(['name' => 'Second']);
    $this->patchJson($base.'/'.$second->id, ['name' => 'EDITORS', 'permissions' => []])->assertUnprocessable();
});

it('hides unrelated tenants and foreign role identifiers and forbids unprivileged members', function () {
    $organization = rbacOrganization();
    $other = rbacOrganization($organization->owner);
    $foreign = $other->roles()->create(['name' => 'Secret']);
    $role = $organization->roles()->create(['name' => 'Local']);
    $base = '/api/v1/organizations/'.$organization->id;
    $this->actingAs($organization->owner);
    $this->getJson($base.'/roles')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Local');
    $this->patchJson($base.'/roles/'.$foreign->id, ['name' => 'Stolen', 'permissions' => []])->assertNotFound();
    $member = User::factory()->create();
    $organization->memberships()->create(['user_id' => $member->id]);
    foreach ([$member, User::factory()->create()] as $actor) {
        $this->actingAs($actor);
        $status = $actor->is($member) ? 403 : 404;
        foreach ([['get', '/roles'], ['get', '/permissions'], ['post', '/roles'], ['patch', '/roles/'.$role->id]] as [$method, $path]) {
            $this->{$method.'Json'}($base.$path, ['name' => '', 'permissions' => ['unknown']])->assertStatus($status);
        }
    }
    expect($foreign->fresh()->name)->toBe('Secret');
});

it('unions multiple membership roles without granting authority in another organization', function () {
    $organization = rbacOrganization();
    $other = rbacOrganization();
    $user = User::factory()->create();
    $membership = $organization->memberships()->create(['user_id' => $user->id]);
    $otherMembership = $other->memberships()->create(['user_id' => $user->id]);
    $editor = app(SaveRole::class)->handle($organization, null, 'Editor', ['organizations.update']);
    $reader = app(SaveRole::class)->handle($organization, null, 'Reader', ['roles.view']);
    expect($membership->hasPermission($organization, PermissionKey::OrganizationsUpdate))->toBeFalse();
    app(AssignMembershipRole::class)->handle($membership, $editor);
    expect($membership->hasPermission($organization, PermissionKey::OrganizationsUpdate))->toBeTrue();
    expect($membership->hasPermission($organization, PermissionKey::RolesView))->toBeFalse();
    app(AssignMembershipRole::class)->handle($membership, $reader);
    app(AssignMembershipRole::class)->handle($membership, $reader);
    expect($membership->roles()->count())->toBe(2);
    expect($membership->hasPermission($organization, PermissionKey::RolesView))->toBeTrue();
    expect($membership->hasPermission($other, PermissionKey::OrganizationsUpdate))->toBeFalse();
    expect($otherMembership->hasPermission($other, PermissionKey::OrganizationsUpdate))->toBeFalse();
    $this->actingAs($user);
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Allowed'])->assertOk();
    $this->patchJson('/api/v1/organizations/'.$other->id, ['name' => 'Denied'])->assertForbidden();
    $this->getJson('/api/v1/organizations/'.$organization->id.'/roles')->assertOk()->assertJsonPath('meta.can_manage', false);
    $this->getJson('/api/v1/organizations/'.$organization->id.'/permissions')->assertOk();
    $this->postJson('/api/v1/organizations/'.$organization->id.'/roles', ['name' => 'Escalate', 'permissions' => []])->assertForbidden();
    $this->patchJson('/api/v1/organizations/'.$organization->id.'/roles/'.$reader->id, ['name' => 'Escalate', 'permissions' => []])->assertForbidden();
    // A previously loaded relationship must not preserve revoked authority.
    $membership->load('roles.permissions');
    $editor->permissions()->detach();
    expect($membership->hasPermission($organization, PermissionKey::OrganizationsUpdate))->toBeFalse();
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Revoked'])->assertForbidden();
    expect($organization->fresh()->owner_user_id)->not->toBe($user->id);
});

it('rejects cross-organization role assignment in the domain and database', function () {
    $organization = rbacOrganization();
    $other = rbacOrganization();
    $membership = $organization->memberships()->sole();
    $foreign = $other->roles()->create(['name' => 'Foreign']);
    expect(fn () => app(AssignMembershipRole::class)->handle($membership, $foreign))->toThrow(ValidationException::class);
    foreach ([$organization->id, $other->id] as $tenant) {
        expect(fn () => DB::transaction(fn () => DB::table('organization_membership_role')->insert([
            'organization_id' => $tenant, 'organization_membership_id' => $membership->id, 'role_id' => $foreign->id,
        ])))->toThrow(QueryException::class);
    }
    expect($membership->roles()->count())->toBe(0);
});

it('enforces RBAC uniqueness references and application-defined keys in PostgreSQL', function () {
    $organization = rbacOrganization();
    $membership = $organization->memberships()->sole();
    $role = app(SaveRole::class)->handle($organization, null, 'Editors', ['organizations.update']);
    app(AssignMembershipRole::class)->handle($membership, $role);
    $missing = '01AAAAAAAAAAAAAAAAAAAAAAAA';
    foreach ([
        ['roles', ['id' => $missing, 'organization_id' => $organization->id, 'name' => ' eDITORS ']],
        ['roles', ['id' => $missing, 'organization_id' => $missing, 'name' => 'Invalid']],
        ['roles', ['id' => $missing, 'organization_id' => $organization->id, 'name' => '   ']],
        ['role_permission', ['role_id' => $role->id, 'permission_key' => 'organizations.update']],
        ['role_permission', ['role_id' => $role->id, 'permission_key' => 'unknown']],
        ['role_permission', ['role_id' => $missing, 'permission_key' => 'roles.view']],
        ['permissions', ['key' => 'injected.admin']],
        ['organization_membership_role', ['organization_id' => $organization->id, 'organization_membership_id' => $membership->id, 'role_id' => $role->id]],
        ['organization_membership_role', ['organization_id' => $organization->id, 'organization_membership_id' => 99999999, 'role_id' => $role->id]],
        ['organization_membership_role', ['organization_id' => $organization->id, 'organization_membership_id' => $membership->id, 'role_id' => $missing]],
    ] as [$table, $values]) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert($values)))->toThrow(QueryException::class);
    }
    // Tenant references cannot be moved underneath existing assignments.
    $other = rbacOrganization();
    expect(fn () => DB::transaction(fn () => DB::table('roles')->where('id', $role->id)->update(['organization_id' => $other->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('organization_memberships')->where('id', $membership->id)->update(['organization_id' => $other->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => Permission::query()->whereKey('organizations.update')->delete()))->toThrow(QueryException::class);
});

it('cascades dependent links intentionally without deleting permissions or unrelated roles', function () {
    $organization = rbacOrganization();
    $role = app(SaveRole::class)->handle($organization, null, 'Disposable', ['roles.view']);
    $member = $organization->memberships()->create(['user_id' => User::factory()->create()->id]);
    app(AssignMembershipRole::class)->handle($member, $role);
    $member->delete();
    expect(DB::table('organization_membership_role')->where('role_id', $role->id)->count())->toBe(0);
    app(AssignMembershipRole::class)->handle($organization->memberships()->sole(), $role);
    $role->delete();
    expect(DB::table('organization_membership_role')->where('role_id', $role->id)->count())->toBe(0);
    expect(DB::table('role_permission')->where('role_id', $role->id)->count())->toBe(0);
    $role = app(SaveRole::class)->handle($organization, null, 'Cascade', ['roles.view']);
    app(AssignMembershipRole::class)->handle($organization->memberships()->sole(), $role);
    $organization->delete();
    DB::statement('SET CONSTRAINTS organizations_owner_membership_foreign IMMEDIATE');
    expect(DB::table('roles')->where('id', $role->id)->exists())->toBeFalse();
    expect(DB::table('organization_membership_role')->where('role_id', $role->id)->exists())->toBeFalse();
    expect(Permission::query()->count())->toBe(count(PermissionKey::cases()));
});

it('preserves pre-RBAC ownership across migration rollback and reapply without owner roles', function () {
    $migration = require database_path('migrations/2026_09_30_000002_create_rbac_tables.php');
    $migration->down();
    $organization = rbacOrganization();
    $ownerId = $organization->owner_user_id;
    $migration->up();
    expect($organization->fresh()->owner_user_id)->toBe($ownerId);
    expect($organization->roles()->count())->toBe(0);
    $membership = $organization->memberships()->sole();
    foreach (PermissionKey::cases() as $permission) {
        expect($membership->hasPermission($organization, $permission))->toBeTrue();
    }
    expect($membership->roles()->count())->toBe(0);
    $this->actingAs($organization->owner);
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Still owned'])->assertOk();
    $this->postJson('/api/v1/organizations/'.$organization->id.'/roles', ['name' => 'First', 'permissions' => []])->assertCreated();
});

it('rolls back the entire role mutation on a permission write failure and handles duplicate races', function () {
    $organization = rbacOrganization();
    $role = app(SaveRole::class)->handle($organization, null, 'Stable', ['roles.view']);
    expect(fn () => app(SaveRole::class)->handle($organization, $role, 'Changed', ['unknown']))->toThrow(QueryException::class);
    expect($role->fresh()->name)->toBe('Stable');
    expect($role->permissions()->pluck('key')->all())->toBe(['roles.view']);
    expect(fn () => app(SaveRole::class)->handle($organization, null, 'stable', []))->toThrow(ValidationException::class);
    expect($organization->roles()->count())->toBe(1);
});

it('never interprets a role name as ownership and does not trust spoofed in-memory tenant identity', function () {
    $organization = rbacOrganization();
    $other = rbacOrganization();
    $user = User::factory()->create();
    $membership = $organization->memberships()->create(['user_id' => $user->id]);
    $role = $organization->roles()->create(['name' => 'Owner']);
    app(AssignMembershipRole::class)->handle($membership, $role);
    expect($membership->hasPermission($organization, PermissionKey::OrganizationsUpdate))->toBeFalse();
    $membership->user_id = $organization->owner_user_id;
    expect($membership->hasPermission($organization, PermissionKey::OrganizationsUpdate))->toBeFalse();
    $membership->organization_id = $other->id;
    expect($membership->hasPermission($other, PermissionKey::RolesView))->toBeFalse();
    $this->actingAs($user);
    $this->postJson('/api/v1/organizations/'.$organization->id.'/roles', ['name' => 'Escalation', 'permissions' => []])->assertForbidden();
    expect($organization->fresh()->owner_user_id)->not->toBe($user->id);
    expect(Permission::query()->orderBy('key')->pluck('key')->all())
        ->toBe(array_column(PermissionKey::cases(), 'value'));
});
