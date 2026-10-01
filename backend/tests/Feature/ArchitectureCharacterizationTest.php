<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Identity\Infrastructure\Fortify\CreateNewUser;
use App\Modules\Identity\Infrastructure\Fortify\ResetUserPassword;
use App\Modules\Identity\Presentation\Http\Responses\LoginResponse;
use App\Modules\Identity\Presentation\Http\Responses\PasswordResetLinkResponse;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

it('resolves organization policy and identity factory mappings', function () {
    expect(Gate::getPolicyFor(Organization::class))->toBeInstanceOf(OrganizationPolicy::class);
    expect(User::factory())->toBeInstanceOf(UserFactory::class);
    expect(UserFactory::new()->make())->toBeInstanceOf(User::class);
    expect(config('auth.providers.users.model'))->toBe(User::class);
});

it('resolves the configured Fortify actions and response adapters', function () {
    foreach ([
        CreatesNewUsers::class => CreateNewUser::class,
        ResetsUserPasswords::class => ResetUserPassword::class,
        LoginResponseContract::class => LoginResponse::class,
        SuccessfulPasswordResetLinkRequestResponse::class => PasswordResetLinkResponse::class,
        FailedPasswordResetLinkRequestResponse::class => PasswordResetLinkResponse::class,
    ] as $contract => $implementation) {
        expect(app($contract))->toBeInstanceOf($implementation);
    }
});

it('resolves persisted identity through authentication and organization relationships', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Identity wiring');
    $membership = $organization->memberships()->create(['user_id' => $member->id]);

    expect(Auth::createUserProvider('users')->retrieveById($owner->id))
        ->toBeInstanceOf(User::class)->id->toBe($owner->id);
    expect($organization->owner)->toBeInstanceOf(User::class)->id->toBe($owner->id);
    expect($membership->user)->toBeInstanceOf(User::class)->id->toBe($member->id);
});

it('returns the unchanged public current-user resource before verification', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->getJson('/api/v1/me')->assertOk()->assertExactJson(['data' => [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'email_verified' => false,
    ]]);
});

it('returns message-only denials before validating organization and RBAC payloads', function (string $actorKind, int $status) {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Private workspace');
    $role = $organization->roles()->create(['name' => 'Private role']);

    if ($actorKind !== 'guest') {
        $actor = $actorKind === 'unverified'
            ? User::factory()->unverified()->create()
            : User::factory()->create();
        if ($actorKind === 'member' || $actorKind === 'unverified') {
            $organization->memberships()->create(['user_id' => $actor->id]);
        }
        $this->actingAs($actor);
    }

    $base = '/api/v1/organizations/'.$organization->id;
    foreach ([
        ['patch', '', false],
        ['get', '/roles', false],
        ['get', '/permissions', false],
        ['post', '/roles', true],
        ['patch', '/roles/'.$role->id, true],
    ] as [$method, $path, $ownerOnly]) {
        $response = $this->{$method.'Json'}($base.$path, ['name' => '', 'permissions' => ['unknown']])
            ->assertStatus($status)->assertExactJsonStructure(['message']);
        expect($response->json('message'))->toBeString()->not->toBeEmpty();

        // These messages are application-owned; framework denial wording is not pinned.
        if ($actorKind === 'member') {
            $response->assertExactJson(['message' => $ownerOnly
                ? 'Only the organization owner may manage roles.'
                : 'You do not have permission for this action.']);
        }
    }

    expect($organization->fresh()->name)->toBe('Private workspace');
    expect($role->fresh()->name)->toBe('Private role');
})->with([
    'guest' => ['guest', 401],
    'unverified member' => ['unverified', 403],
    'non-member' => ['outsider', 404],
    'member without permission' => ['member', 403],
]);

it('keeps missing and policy-hidden organizations as message-only 404 responses', function () {
    $organization = app(CreateOrganization::class)->handle(User::factory()->create(), 'Hidden');
    $this->actingAs(User::factory()->create());

    foreach ([$organization->id, '01AAAAAAAAAAAAAAAAAAAAAAAA'] as $id) {
        $response = $this->getJson('/api/v1/organizations/'.$id)
            ->assertNotFound()->assertExactJsonStructure(['message']);
        expect($response->json('message'))->toBeString()->not->toBeEmpty();
    }
    // Current body differences are recorded in the validation document, not made a contract.
});

it('binds nested roles within the route organization before request validation', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Local');
    $other = app(CreateOrganization::class)->handle($owner, 'Other');
    $local = $organization->roles()->create(['name' => 'Local role']);
    $foreign = $other->roles()->create(['name' => 'Foreign role']);
    $this->actingAs($owner);
    $base = '/api/v1/organizations/'.$organization->id.'/roles/';

    foreach ([$foreign->id, '01AAAAAAAAAAAAAAAAAAAAAAAA'] as $id) {
        $response = $this->patchJson($base.$id, ['name' => '', 'permissions' => ['unknown']])
            ->assertNotFound()->assertExactJsonStructure(['message']);
        expect($response->json('message'))->toBeString()->not->toBeEmpty();
    }
    $this->patchJson($base.$local->id, ['name' => 'Changed', 'permissions' => []])
        ->assertOk()->assertExactJson(['data' => ['id' => $local->id, 'name' => 'Changed', 'permissions' => []]]);
    expect($foreign->fresh()->name)->toBe('Foreign role');
});

it('returns field validation errors and the application duplicate-name message without mutation', function () {
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner, 'Validation');
    $role = $organization->roles()->create(['name' => 'Editors']);
    $this->actingAs($owner);
    $url = '/api/v1/organizations/'.$organization->id.'/roles';

    $invalid = $this->postJson($url, ['name' => '', 'permissions' => ['unknown']])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'permissions.0'])
        ->assertExactJsonStructure(['message', 'errors' => ['name', 'permissions.0']]);
    expect($invalid->json('message'))->toBeString()->not->toBeEmpty();

    $message = 'A role with this name already exists in this organization.';
    $this->postJson($url, ['name' => ' eDITORS ', 'permissions' => []])
        ->assertUnprocessable()->assertExactJson(['message' => $message, 'errors' => ['name' => [$message]]]);
    expect($organization->roles()->count())->toBe(1);
    expect($role->fresh()->name)->toBe('Editors');
});
