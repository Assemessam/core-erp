<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;

it('protects direct rename using the explicit actor without HTTP or ambient authority', function (string $actorKind, string $outcome) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Original');
    $actor = $actorKind === 'owner' ? $owner : User::factory()->create();
    if (in_array($actorKind, ['member', 'editor'])) {
        $membership = $organization->memberships()->create(['user_id' => $actor->id]);
        if ($actorKind === 'editor') {
            $role = $organization->roles()->create(['name' => 'Editor']);
            $role->permissions()->sync(['organizations.update']);
            app(AssignMembershipRole::class)->handle($membership, $role);
        }
    }
    // An unrelated ambient owner cannot upgrade the explicit actor.
    $this->actingAs($owner);
    $rename = app(RenameOrganization::class);
    if ($outcome === AccessDecision::ALLOWED) {
        $rename->handle($actor->id, $organization, 'Renamed');
        expect($organization->fresh()->name)->toBe('Renamed');
    } else {
        expect(fn () => $rename->handle($actor->id, $organization, 'Denied'))
            ->toThrow(function (AccessDenied $exception) use ($outcome) {
                expect($exception->decision->outcome)->toBe($outcome);
            });
        expect($organization->fresh()->name)->toBe('Original');
    }
})->with([
    'owner' => ['owner', AccessDecision::ALLOWED],
    'editor' => ['editor', AccessDecision::ALLOWED],
    'ordinary member' => ['member', AccessDecision::FORBIDDEN],
    'non-member' => ['outsider', AccessDecision::HIDDEN],
]);

it('rechecks direct rename after permission role and membership revocation despite stale or spoofed objects', function () {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Original');
    $membership = $organization->memberships()->create(['user_id' => $actor->id]);
    $role = $organization->roles()->create(['name' => 'Editor']);
    $role->permissions()->sync(['organizations.update']);
    app(AssignMembershipRole::class)->handle($membership, $role);
    $membership->load('roles.permissions');
    $organization->load('memberships.roles.permissions');
    $rename = app(RenameOrganization::class);
    expect(auth()->check())->toBeFalse();
    $rename->handle($actor->id, $organization, 'Allowed');

    $role->permissions()->detach();
    expect(fn () => $rename->handle($actor->id, $organization, 'Denied'))->toThrow(AccessDenied::class);
    $role->permissions()->sync(['organizations.update']);
    $membership->roles()->detach();
    expect(fn () => $rename->handle($actor->id, $organization, 'Denied'))->toThrow(AccessDenied::class);
    $membership->user_id = $owner->id;
    $organization->owner_user_id = $actor->id;
    expect(fn () => $rename->handle($actor->id, $organization, 'Spoofed'))->toThrow(AccessDenied::class);
    $membership->delete();
    expect(fn () => $rename->handle($actor->id, $organization, 'Deleted member'))
        ->toThrow(function (AccessDenied $exception) {
            expect($exception->decision->outcome)->toBe(AccessDecision::HIDDEN);
        });
    expect($organization->fresh()->name)->toBe('Allowed');
    expect($organization->fresh()->owner_user_id)->toBe($owner->id);
});

it('never lets permission or ownership in another organization authorize a direct rename', function () {
    $actor = User::factory()->create();
    $owned = app(CreateOrganization::class)->handle($actor, 'Owned');
    $other = app(CreateOrganization::class)->handle(User::factory()->create(), 'Other');
    $role = $owned->roles()->create(['name' => 'Editor']);
    $role->permissions()->sync(['organizations.update']);
    app(AssignMembershipRole::class)->handle($owned->memberships()->sole(), $role);
    $rename = app(RenameOrganization::class);
    expect(fn () => $rename->handle($actor->id, $other, 'Hidden'))
        ->toThrow(function (AccessDenied $exception) {
            expect($exception->decision->outcome)->toBe(AccessDecision::HIDDEN);
        });
    $other->memberships()->create(['user_id' => $actor->id]);
    expect(fn () => $rename->handle($actor->id, $other, 'Forbidden'))
        ->toThrow(function (AccessDenied $exception) {
            expect($exception->decision->outcome)->toBe(AccessDecision::FORBIDDEN);
        });
    expect($other->fresh()->name)->toBe('Other');
});

it('protects direct role creation and update with owner-only persisted authority', function (string $actorKind, string $outcome) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Roles');
    $actor = $actorKind === 'owner' ? $owner : User::factory()->create();
    if (in_array($actorKind, ['member', 'permissions', 'other owner'])) {
        $membership = $organization->memberships()->create(['user_id' => $actor->id]);
        if ($actorKind === 'permissions') {
            $grant = $organization->roles()->create(['name' => 'Owner']);
            $grant->permissions()->sync(['organizations.update', 'roles.view']);
            app(AssignMembershipRole::class)->handle($membership, $grant);
        }
    }
    if (in_array($actorKind, ['other owner', 'outsider'])) {
        app(CreateOrganization::class)->handle($actor, 'Elsewhere');
    }
    $role = $organization->roles()->create(['name' => 'Existing']);
    $role->permissions()->sync(['roles.view']);
    $roleCount = $organization->roles()->count();
    $save = app(SaveRole::class);
    $this->actingAs($owner);

    foreach ([null, $role] as $target) {
        if ($outcome === AccessDecision::ALLOWED) {
            $saved = $save->handle($actor->id, $organization, $target, $target === null ? 'Created' : 'Updated', PermissionKey::OrganizationsUpdate);
            expect($saved->permissions->pluck('key')->all())->toBe(['organizations.update']);
            expect($saved->fresh()->name)->toBe($target === null ? 'Created' : 'Updated');
        } else {
            // Neither a dirty owner field nor cached relationship data can grant role administration.
            $organization->load('memberships.roles.permissions');
            $organization->owner_user_id = $actor->id;
            expect(fn () => $save->handle($actor->id, $organization, $target, 'Denied'))
                ->toThrow(function (AccessDenied $exception) use ($outcome) {
                    expect($exception->decision->outcome)->toBe($outcome);
                });
        }
    }
    if ($outcome !== AccessDecision::ALLOWED) {
        expect($organization->roles()->count())->toBe($roleCount);
        expect($role->fresh()->name)->toBe('Existing');
        expect($role->permissions()->pluck('key')->all())->toBe(['roles.view']);
        expect($organization->fresh()->owner_user_id)->toBe($owner->id);
    }
})->with([
    'owner' => ['owner', AccessDecision::ALLOWED],
    'member' => ['member', AccessDecision::FORBIDDEN],
    'both available permissions' => ['permissions', AccessDecision::FORBIDDEN],
    'owner elsewhere with membership here' => ['other owner', AccessDecision::FORBIDDEN],
    'owner elsewhere without membership here' => ['outsider', AccessDecision::HIDDEN],
]);

it('rejects a foreign role in direct writes before changing any role or grant', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Here');
    $other = app(CreateOrganization::class)->handle($owner, 'Elsewhere');
    $foreign = $other->roles()->create(['name' => 'Foreign']);
    $save = app(SaveRole::class);
    expect(fn () => $save->handle($owner->id, $organization, $foreign, 'Stolen'))
        ->toThrow(function (AccessDenied $exception) {
            expect($exception->decision->outcome)->toBe(AccessDecision::HIDDEN);
        });
    // Spoofing the in-memory tenant cannot evade the existing scoped, locked database lookup.
    $foreign->organization_id = $organization->id;
    expect(fn () => $save->handle($owner->id, $organization, $foreign, 'Stolen'))
        ->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh()->name)->toBe('Foreign');
    expect($organization->roles()->count())->toBe(0);
});

it('translates direct application denials through the HTTP boundary without exposing internals', function (string $operation, bool $member, int $status, string $message) {
    $actor = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle(User::factory()->create(), 'Original');
    if ($member) {
        $organization->memberships()->create(['user_id' => $actor->id]);
    }
    // Test-only route deliberately skips Policy/Form Request to exercise Application denial rendering.
    Route::post('/api/test-organization-denial', function () use ($actor, $organization, $operation) {
        if ($operation === 'rename') {
            app(RenameOrganization::class)->handle($actor->id, $organization, 'Denied');
        } else {
            app(SaveRole::class)->handle($actor->id, $organization, null, 'Denied');
        }
    });
    $this->postJson('/api/test-organization-denial')->assertStatus($status)->assertExactJson(['message' => $message]);
    expect($organization->fresh()->name)->toBe('Original');
    expect($organization->roles()->count())->toBe(0);
})->with([
    'rename hidden' => ['rename', false, 404, 'Not Found'],
    'rename forbidden' => ['rename', true, 403, 'You do not have permission for this action.'],
    'role hidden' => ['role', false, 404, 'Not Found'],
    'role forbidden' => ['role', true, 403, 'Only the organization owner may manage roles.'],
]);
