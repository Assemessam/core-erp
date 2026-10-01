<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Application\Queries\ListOrganizations;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

it('lists the explicit users owned and member organizations in name order without ambient authentication', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $create = app(CreateOrganization::class);
    $zulu = $create->handle($actor->id, 'Zulu');
    $alpha = $create->handle($other->id, 'Alpha');
    $alpha->memberships()->create(['user_id' => $actor->id]);
    $create->handle($other->id, 'Unrelated');

    expect(auth()->check())->toBeFalse();
    $query = new ListOrganizations;
    expect($query->handle($actor->id)->modelKeys())->toBe([$alpha->id, $zulu->id]);

    $this->actingAs($other);
    expect($query->handle($actor->id)->modelKeys())->toBe([$alpha->id, $zulu->id]);
});

it('returns no organizations for an explicit user with no memberships despite an authenticated owner', function () {
    $owner = User::factory()->create();
    app(CreateOrganization::class)->handle($owner->id, 'Owned');
    $nonMember = User::factory()->create();
    $this->actingAs($owner);

    expect((new ListOrganizations)->handle($nonMember->id))->toBeEmpty();
});

it('creates for the explicit owner ID despite a different authenticated session', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($other);

    $organization = app(CreateOrganization::class)->handle($owner->id, 'Explicit owner');

    expect($organization->owner_user_id)->toBe($owner->id);
    expect(OrganizationMembership::query()->where('organization_id', $organization->id)->pluck('user_id')->all())
        ->toBe([$owner->id]);
});

it('uses the session actor rather than a client-supplied list user identifier', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($actor->id, 'Visible');
    app(CreateOrganization::class)->handle($other->id, 'Hidden');

    $this->actingAs($actor)->getJson('/api/v1/organizations?user_id='.$other->id)
        ->assertOk()->assertExactJson(['data' => [['id' => $organization->id, 'name' => 'Visible']]]);
});

it('renames the supplied model without changing ownership memberships roles or permission grants', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'Original');
    $member = $organization->memberships()->create(['user_id' => User::factory()->create()->id]);
    $role = app(SaveRole::class)->handle($organization->owner_user_id, $organization, null, 'Editor', PermissionKey::OrganizationsUpdate, PermissionKey::RolesView);
    app(AssignMembershipRole::class)->handle($member, $role);
    $memberships = $organization->memberships()->orderBy('id')->get()->toArray();
    $roles = $organization->roles()->with('permissions')->orderBy('id')->get()->toArray();
    $assignments = DB::table('organization_membership_role')->where('organization_id', $organization->id)->get()->toArray();

    // Explicit actor authorization works without an HTTP request or ambient login.
    expect(auth()->check())->toBeFalse();
    $result = app(RenameOrganization::class)->handle($owner->id, $organization, 'Renamed');

    expect($result)->toBe($organization);
    expect($organization->fresh()->name)->toBe('Renamed');
    expect($organization->fresh()->owner_user_id)->toBe($owner->id);
    expect($organization->memberships()->orderBy('id')->get()->toArray())->toBe($memberships);
    expect($organization->roles()->with('permissions')->orderBy('id')->get()->toArray())->toBe($roles);
    expect(DB::table('organization_membership_role')->where('organization_id', $organization->id)->get()->toArray())
        ->toEqual($assignments);
});

it('keeps rename input validation at the HTTP boundary', function (string $name) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'Unchanged');

    $this->actingAs($owner)->patchJson('/api/v1/organizations/'.$organization->id, ['name' => $name])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($organization->fresh()->name)->toBe('Unchanged');
})->with(['empty' => '', 'too long' => str_repeat('a', 256)]);
