<?php

use App\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RenameOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Application\Queries\ListOrganizations;
use Illuminate\Support\Facades\DB;

it('lists the explicit users owned and member organizations in name order without ambient authentication', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $create = app(CreateOrganization::class);
    $zulu = $create->handle($actor, 'Zulu');
    $alpha = $create->handle($other, 'Alpha');
    $alpha->memberships()->create(['user_id' => $actor->id]);
    $create->handle($other, 'Unrelated');

    expect(auth()->check())->toBeFalse();
    $query = new ListOrganizations;
    expect($query->handle($actor->id)->modelKeys())->toBe([$alpha->id, $zulu->id]);

    $this->actingAs($other);
    expect($query->handle($actor->id)->modelKeys())->toBe([$alpha->id, $zulu->id]);
});

it('returns no organizations for an explicit user with no memberships despite an authenticated owner', function () {
    $owner = User::factory()->create();
    app(CreateOrganization::class)->handle($owner, 'Owned');
    $nonMember = User::factory()->create();
    $this->actingAs($owner);

    expect((new ListOrganizations)->handle($nonMember->id))->toBeEmpty();
});

it('uses the session actor rather than a client-supplied list user identifier', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($actor, 'Visible');
    app(CreateOrganization::class)->handle($other, 'Hidden');

    $this->actingAs($actor)->getJson('/api/v1/organizations?user_id='.$other->id)
        ->assertOk()->assertExactJson(['data' => [['id' => $organization->id, 'name' => 'Visible']]]);
});

it('renames the supplied model without changing ownership memberships roles or permission grants', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Original');
    $member = $organization->memberships()->create(['user_id' => User::factory()->create()->id]);
    $role = app(SaveRole::class)->handle($organization->owner_user_id, $organization, null, 'Editor', ['organizations.update', 'roles.view']);
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
    $organization = app(CreateOrganization::class)->handle($owner, 'Unchanged');

    $this->actingAs($owner)->patchJson('/api/v1/organizations/'.$organization->id, ['name' => $name])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect($organization->fresh()->name)->toBe('Unchanged');
})->with(['empty' => '', 'too long' => str_repeat('a', 256)]);
