<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use Illuminate\Support\Facades\Gate;

it('evaluates membership ownership and permissions from explicit persisted identities', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Access');
    $membership = $organization->memberships()->create(['user_id' => $member->id]);
    $access = app(OrganizationAccess::class);

    foreach (['view', 'update', 'viewRoles', 'manageRoles'] as $ability) {
        expect($access->$ability($owner->id, $organization->id)->outcome)->toBe(AccessDecision::ALLOWED);
        expect($access->$ability($outsider->id, $organization->id)->outcome)->toBe(AccessDecision::HIDDEN);
        expect($access->$ability($owner->id, '01AAAAAAAAAAAAAAAAAAAAAAAA')->outcome)->toBe(AccessDecision::HIDDEN);
    }
    expect($organization->roles()->count())->toBe(0);
    expect($access->view($member->id, $organization->id)->outcome)->toBe(AccessDecision::ALLOWED);
    expect($access->update($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    expect($access->viewRoles($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    expect($access->manageRoles($member->id, $organization->id)->message)->toBe('Only the organization owner may manage roles.');

    $editor = $organization->roles()->create(['name' => 'Editor']);
    $editor->permissions()->sync(['organizations.update']);
    $reader = $organization->roles()->create(['name' => 'Reader']);
    $reader->permissions()->sync(['roles.view']);
    app(AssignMembershipRole::class)->handle($membership, $editor);
    app(AssignMembershipRole::class)->handle($membership, $reader);
    $membership->load('roles.permissions');
    expect($access->update($member->id, $organization->id)->outcome)->toBe(AccessDecision::ALLOWED);
    expect($access->viewRoles($member->id, $organization->id)->outcome)->toBe(AccessDecision::ALLOWED);
    expect($access->manageRoles($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);

    $editor->permissions()->detach();
    expect($access->update($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    $membership->roles()->detach($reader);
    expect($access->viewRoles($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    $membership->user_id = $owner->id;
    $organization->owner_user_id = $member->id;
    expect($access->manageRoles($member->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    $membership->delete();
    expect($access->view($member->id, $organization->id)->outcome)->toBe(AccessDecision::HIDDEN);
});

it('keeps policy and evaluator outcomes in parity for every current ability', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Parity');
    $organization->memberships()->create(['user_id' => $member->id]);
    $access = app(OrganizationAccess::class);

    foreach ([$owner, $member, $outsider] as $actor) {
        foreach (['view', 'update', 'viewRoles', 'manageRoles'] as $ability) {
            $decision = $access->$ability($actor->id, $organization->id);
            $response = Gate::forUser($actor)->inspect($ability, $organization);
            expect($response->allowed())->toBe($decision->outcome === AccessDecision::ALLOWED);
            if ($decision->outcome === AccessDecision::HIDDEN) {
                expect($response->status())->toBe(404);
            }
            if ($decision->outcome === AccessDecision::FORBIDDEN) {
                expect($response->status())->toBeNull(); // Laravel's default forbidden response.
                expect($response->message())->toBe($ability === 'manageRoles'
                    ? 'Only the organization owner may manage roles.'
                    : 'You do not have permission for this action.');
            }
        }
    }
});

it('uses fresh persisted ownership and still requires the owner membership', function () {
    $owner = User::factory()->create();
    $successor = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Ownership');
    $membership = $organization->memberships()->create(['user_id' => $successor->id]);
    $organization->load('owner', 'memberships');
    // Change a separate model so the original organization and its relationships remain stale.
    $organization->fresh()->update(['owner_user_id' => $successor->id]);
    $access = app(OrganizationAccess::class);
    expect($access->manageRoles($owner->id, $organization->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    expect($access->manageRoles($successor->id, $organization->id)->outcome)->toBe(AccessDecision::ALLOWED);
    expect(Gate::forUser($owner)->inspect('manageRoles', $organization)->denied())->toBeTrue();
    expect(Gate::forUser($successor)->inspect('manageRoles', $organization)->allowed())->toBeTrue();

    // Only possible transiently inside this rollback transaction: deferred FK forbids committing it.
    $membership->delete();
    foreach (['view', 'update', 'viewRoles', 'manageRoles'] as $ability) {
        expect($access->$ability($successor->id, $organization->id)->outcome)->toBe(AccessDecision::HIDDEN);
    }
});
