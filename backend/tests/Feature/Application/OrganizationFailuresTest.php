<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('assigns within a tenant and rejects incompatible records before creating a pivot', function () {
    $organization = app(CreateOrganization::class)->handle(User::factory()->create()->id, 'Local');
    $other = app(CreateOrganization::class)->handle(User::factory()->create()->id, 'Other');
    $membership = $organization->memberships()->sole();
    $local = $organization->roles()->create(['name' => 'Local']);
    $foreign = $other->roles()->create(['name' => 'Foreign']);
    $assign = app(AssignMembershipRole::class);
    $assign->handle($membership, $local);
    $assign->handle($membership, $local);
    expect($membership->roles()->pluck('roles.id')->all())->toBe([$local->id]);

    expect(fn () => $assign->handle($membership, $foreign))->toThrow(CrossOrganizationRoleAssignment::class);
    expect(DB::table('organization_membership_role')->where('role_id', $foreign->id)->exists())->toBeFalse();

    // A dirty attribute can fool scalar comparison, but never the persisted composite FKs.
    $foreign->organization_id = $organization->id;
    expect(fn () => DB::transaction(fn () => $assign->handle($membership, $foreign)))->toThrow(QueryException::class);
    expect($membership->roles()->pluck('roles.id')->all())->toBe([$local->id]);
});

it('exposes a specific application conflict for PostgreSQL duplicate names without partial mutation', function (bool $update) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'Conflict');
    $save = app(SaveRole::class);
    $save->handle($owner->id, $organization, null, 'Existing');
    $role = $save->handle($owner->id, $organization, null, 'Stable', PermissionKey::RolesView);

    expect(fn () => $save->handle($owner->id, $organization, $update ? $role : null, ' eXISTING ', PermissionKey::OrganizationsUpdate))
        ->toThrow(function (RoleNameConflict $exception) {
            expect($exception->getMessage())->toBe('A role with this name already exists in this organization.');
            expect($exception->getPrevious())->toBeInstanceOf(QueryException::class);
        });
    expect($role->fresh()->name)->toBe('Stable');
    expect($role->permissions()->pluck('key')->all())->toBe(['roles.view']);
    expect($organization->roles()->count())->toBe(2);
})->with(['create' => false, 'update' => true]);

it('requires enum instances for direct writes and rejects invalid permission inputs without mutation', function (mixed $invalid) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'Typed permissions');
    $save = app(SaveRole::class);
    $role = $save->handle($owner->id, $organization, null, 'Stable', PermissionKey::RolesView);

    foreach ([null, $role] as $target) {
        expect(fn () => $save->handle($owner->id, $organization, $target, 'Invalid', PermissionKey::OrganizationsUpdate, $invalid))
            ->toThrow(TypeError::class);
    }
    expect($organization->roles()->count())->toBe(1);
    expect($role->fresh()->name)->toBe('Stable');
    expect($role->permissions()->pluck('key')->all())->toBe(['roles.view']);
})->with([
    'unknown key' => ['undefined.permission'],
    'unconverted known string' => ['roles.view'],
    'array instead of enum' => [['roles.view']],
    'null' => [null],
]);

it('maps a database name conflict to the unchanged HTTP field error', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'HTTP conflict');
    app(SaveRole::class)->handle($owner->id, $organization, null, 'Existing');
    // Test-only adapter route bypasses the preflight name validator so the real DB conflict is reached.
    Route::post('/api/test-role-conflict', fn () => app(SaveRole::class)->handle($owner->id, $organization, null, ' existing '));
    $message = 'A role with this name already exists in this organization.';
    $this->postJson('/api/test-role-conflict')->assertUnprocessable()
        ->assertExactJson(['message' => $message, 'errors' => ['name' => [$message]]]);
    expect($organization->roles()->count())->toBe(1);
});

it('maps the domain assignment failure to its existing field validation representation', function () {
    $organization = app(CreateOrganization::class)->handle(User::factory()->create()->id, 'Local');
    $other = app(CreateOrganization::class)->handle(User::factory()->create()->id, 'Other');
    $membership = $organization->memberships()->sole();
    $foreign = $other->roles()->create(['name' => 'Foreign']);
    // Assignment remains internal; no production membership-management endpoint is introduced.
    Route::post('/api/test-role-assignment', fn () => app(AssignMembershipRole::class)->handle($membership, $foreign));
    $message = 'The role must belong to the membership organization.';
    $this->postJson('/api/test-role-assignment')->assertUnprocessable()
        ->assertExactJson(['message' => $message, 'errors' => ['role' => [$message]]]);
    expect($membership->roles()->count())->toBe(0);
});

it('rejects unknown HTTP permission keys with the existing validation envelope before mutation', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'HTTP validation');
    $message = 'The selected permissions.0 is invalid.';
    $this->actingAs($owner)->postJson('/api/v1/organizations/'.$organization->id.'/roles', [
        'name' => 'Invalid', 'permissions' => ['unknown'],
    ])->assertUnprocessable()->assertExactJson(['message' => $message, 'errors' => ['permissions.0' => [$message]]]);
    expect($organization->roles()->count())->toBe(0);
});
