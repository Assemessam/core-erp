<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Mail\OrganizationInvitationMail;
use Illuminate\Support\Facades\Mail;

function usersOrganization(?User $owner = null): Organization
{
    return app(CreateOrganization::class)->handle(($owner ?? User::factory()->create())->id, 'Users');
}

function issuedInvitation(Organization $organization, User $invitee, array $roles = []): array
{
    test()->actingAs($organization->owner)->postJson('/api/v1/organizations/'.$organization->id.'/invitations', ['email' => $invitee->email, 'roles' => $roles])->assertCreated();
    $invitation = $organization->invitations()->where('state', InvitationState::Pending)->where('email', $invitee->email)->sole();
    $mail = Mail::sent(OrganizationInvitationMail::class)->last();
    parse_str(parse_url($mail->invitationUrl, PHP_URL_FRAGMENT), $fragment);

    return [$invitation, $fragment['token']];
}

beforeEach(function () {
    // MailFake forwards the transport accessor to its manager's default mailer.
    config(['mail.default' => 'smtp']);
    Mail::fake();
});

it('authenticates and hides organization users endpoints before validation', function () {
    $org = usersOrganization();
    $member = $org->memberships()->sole();
    $invite = $org->invitations()->create(['email' => 'invited@example.test', 'inviter_user_id' => $org->owner_user_id, 'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addDay()]);
    $base = '/api/v1/organizations/'.$org->id;
    $routes = [['get', '/members'], ['get', '/invitations'], ['post', '/invitations'], ['delete', '/invitations/'.$invite->id], ['put', '/members/'.$member->id.'/roles'], ['post', '/members/'.$member->id.'/suspend'], ['post', '/members/'.$member->id.'/activate'], ['delete', '/members/'.$member->id]];
    foreach ($routes as [$verb, $url]) {
        $this->{$verb.'Json'}($base.$url)->assertUnauthorized();
    }
    $this->actingAs(User::factory()->unverified()->create());
    foreach ($routes as [$verb, $url]) {
        $this->{$verb.'Json'}($base.$url)->assertForbidden();
    }
    $this->actingAs(User::factory()->create());
    foreach ($routes as [$verb, $url]) {
        $this->{$verb.'Json'}($base.$url)->assertNotFound();
    }
    $ordinary = User::factory()->create();
    $org->memberships()->create(['user_id' => $ordinary->id]);
    $this->actingAs($ordinary);
    foreach ($routes as [$verb, $url]) {
        $this->{$verb.'Json'}($base.$url)->assertForbidden();
    }
});

it('normalizes invitation addresses and sends only a hashed expiring credential', function () {
    $org = usersOrganization();
    $this->actingAs($org->owner)->postJson('/api/v1/organizations/'.$org->id.'/invitations', ['email' => ' ALICE@EXAMPLE.TEST ', 'roles' => []])->assertCreated()->assertJsonPath('data.email', 'alice@example.test');
    $invite = $org->invitations()->sole();
    $mail = Mail::sent(OrganizationInvitationMail::class)->sole();
    parse_str(parse_url($mail->invitationUrl, PHP_URL_FRAGMENT), $fragment);
    expect($fragment['token'])->toMatch('/^[a-f0-9]{64}$/');
    expect($invite->token_hash)->toBe(hash('sha256', $fragment['token']));
    expect($invite->expires_at->diffInSeconds(now()->addDays(7)))->toBeLessThan(5);
    expect(json_encode($invite->getAttributes()))->not->toContain($fragment['token']);
    $result = $this->getJson('/api/v1/organizations/'.$org->id.'/invitations')->assertOk();
    expect($result->json('data.0'))->toHaveKeys(['id', 'email', 'state', 'expires_at', 'roles'])->not->toHaveKeys(['token', 'token_hash']);
    expect($mail->render())->toContain('Accept invitation');
});

it('rejects invitations for active and suspended existing memberships', function (MembershipStatus $status) {
    $org = usersOrganization();
    $user = User::factory()->create();
    $org->memberships()->create(['user_id' => $user->id, 'status' => $status]);
    $this->actingAs($org->owner)->postJson('/api/v1/organizations/'.$org->id.'/invitations', ['email' => $user->email, 'roles' => []])->assertUnprocessable()->assertJsonPath('reason', 'member_exists');
    expect($org->invitations()->count())->toBe(0);
    Mail::assertNothingSent();
})->with([MembershipStatus::Active, MembershipStatus::Suspended]);

it('accepts once atomically with selected roles and rejects replay', function () {
    $org = usersOrganization();
    $user = User::factory()->create();
    $role = app(SaveRole::class)->handle($org->owner_user_id, $org, null, 'Reader', PermissionKey::MembersView);
    [$invite, $token] = issuedInvitation($org, $user, [$role->id]);
    $this->actingAs($user)->postJson('/api/v1/invitations/'.$invite->id.'/accept', ['token' => $token])->assertOk()->assertJsonPath('data.id', $org->id);
    expect($invite->fresh()->state)->toBe(InvitationState::Accepted);
    $membership = $org->memberships()->where('user_id', $user->id)->sole();
    expect($membership->roles()->pluck('roles.id')->all())->toBe([$role->id]);
    $this->postJson('/api/v1/invitations/'.$invite->id.'/accept', ['token' => $token])->assertUnprocessable()->assertJsonPath('reason', 'accepted');
    expect($org->memberships()->where('user_id', $user->id)->count())->toBe(1);
    $this->getJson('/api/v1/organizations/'.$org->id.'/members')->assertOk();
});

it('rejects wrong identity invalid revoked expired and unverified acceptance', function (string $case, int $status, ?string $reason) {
    $org = usersOrganization();
    $user = User::factory()->create();
    [$invite, $token] = issuedInvitation($org, $user);
    if ($case === 'wrong') {
        $user = User::factory()->create();
    }
    if ($case === 'unverified') {
        $user->forceFill(['email_verified_at' => null])->save();
    }
    if ($case === 'invalid') {
        $token = str_repeat('a', 64);
    }
    if ($case === 'revoked') {
        $invite->update(['state' => InvitationState::Revoked]);
    }
    if ($case === 'expired') {
        $invite->update(['expires_at' => now()->subSecond()]);
    }
    $response = $this->actingAs($user)->postJson('/api/v1/invitations/'.$invite->id.'/accept', ['token' => $token])->assertStatus($status);
    if ($reason) {
        $response->assertJsonPath('reason', $reason);
    }
    expect($org->memberships()->count())->toBe(1);
})->with([['wrong', 422, 'email_mismatch'], ['unverified', 403, null], ['invalid', 422, 'invalid'], ['revoked', 422, 'revoked'], ['expired', 422, 'expired']]);

it('reinvites by revoking the previous credential and revocation is repeatable', function () {
    $org = usersOrganization();
    $user = User::factory()->create();
    [$old, $oldToken] = issuedInvitation($org, $user);
    [$new, $newToken] = issuedInvitation($org, $user);
    expect($old->fresh()->state)->toBe(InvitationState::Revoked);
    expect($oldToken)->not->toBe($newToken);
    $this->actingAs($user)->postJson('/api/v1/invitations/'.$old->id.'/accept', ['token' => $oldToken])->assertUnprocessable()->assertJsonPath('reason', 'revoked');
    $this->actingAs($org->owner);
    for ($i = 0; $i < 2; $i++) {
        $this->deleteJson('/api/v1/organizations/'.$org->id.'/invitations/'.$new->id)->assertNoContent();
    }
    $this->actingAs($user)->postJson('/api/v1/invitations/'.$new->id.'/accept', ['token' => $newToken])->assertUnprocessable()->assertJsonPath('reason', 'revoked');
});

it('revoking an accepted invitation never removes its membership', function () {
    $org = usersOrganization();
    $user = User::factory()->create();
    [$invite, $token] = issuedInvitation($org, $user);
    $this->actingAs($user)->postJson('/api/v1/invitations/'.$invite->id.'/accept', ['token' => $token])->assertOk();
    $this->actingAs($org->owner)->deleteJson('/api/v1/organizations/'.$org->id.'/invitations/'.$invite->id)->assertNoContent();
    expect($org->memberships()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($invite->fresh()->state)->toBe(InvitationState::Accepted);
});

it('suspends immediately restores retained grants and removes only membership', function () {
    $org = usersOrganization();
    $user = User::factory()->create();
    $member = $org->memberships()->create(['user_id' => $user->id]);
    $role = app(SaveRole::class)->handle($org->owner_user_id, $org, null, 'Reader', PermissionKey::RolesView, PermissionKey::MembersView);
    app(AssignMembershipRole::class)->handle($member, $role);
    $base = '/api/v1/organizations/'.$org->id;
    $this->actingAs($org->owner)->postJson($base.'/members/'.$member->id.'/suspend')->assertNoContent();
    expect($member->fresh()->status)->toBe(MembershipStatus::Suspended);
    expect($member->roles()->count())->toBe(1);
    $this->actingAs($user)->getJson('/api/v1/organizations')->assertExactJson(['data' => []]);
    foreach (['', '/roles', '/members', '/users-access'] as $path) {
        $this->getJson($base.$path)->assertNotFound();
    }
    $this->actingAs($org->owner)->postJson($base.'/members/'.$member->id.'/activate')->assertNoContent();
    $this->actingAs($user)->getJson($base.'/roles')->assertOk();
    $this->getJson('/api/v1/organizations')->assertJsonCount(1, 'data');
    $this->actingAs($org->owner)->deleteJson($base.'/members/'.$member->id)->assertNoContent();
    expect($member->fresh())->toBeNull();
    expect($user->fresh())->not->toBeNull();
    expect($role->fresh())->not->toBeNull();
    expect($member->roles()->count())->toBe(0);
    $this->actingAs($user)->getJson($base)->assertNotFound();
});

it('protects owner membership and lists deliberate identity fields', function () {
    $org = usersOrganization();
    $member = $org->memberships()->sole();
    $base = '/api/v1/organizations/'.$org->id;
    $this->actingAs($org->owner)->postJson($base.'/members/'.$member->id.'/suspend')->assertUnprocessable();
    $this->deleteJson($base.'/members/'.$member->id)->assertUnprocessable();
    $data = $this->getJson($base.'/members')->assertOk()->json('data.0');
    expect(array_keys($data))->toBe(['id', 'user_id', 'name', 'email', 'status', 'is_owner', 'roles']);
    expect($data['is_owner'])->toBeTrue();
});

it('rejects foreign nested resources and roles even for an owner of both tenants', function () {
    $org = usersOrganization();
    $other = usersOrganization($org->owner);
    $user = User::factory()->create();
    [$invite] = issuedInvitation($other, $user);
    $member = $other->memberships()->sole();
    $foreignRole = $other->roles()->create(['name' => 'Foreign']);
    $base = '/api/v1/organizations/'.$org->id;
    $this->actingAs($org->owner);
    $this->deleteJson($base.'/invitations/'.$invite->id)->assertNotFound();
    foreach (['suspend', 'activate'] as $command) {
        $this->postJson($base.'/members/'.$member->id.'/'.$command)->assertNotFound();
    }
    $this->deleteJson($base.'/members/'.$member->id)->assertNotFound();
    $this->putJson($base.'/members/'.$member->id.'/roles', ['roles' => []])->assertNotFound();
    $this->postJson($base.'/invitations', ['email' => $user->email, 'roles' => [$foreignRole->id]])->assertUnprocessable();
    $this->putJson($base.'/members/'.$org->memberships()->sole()->id.'/roles', ['roles' => [$foreignRole->id]])->assertUnprocessable();
    expect($org->invitations()->count())->toBe(0);
});

it('delegates view and roleless invitations without lifecycle or role escalation', function () {
    $org = usersOrganization();
    $user = User::factory()->create();
    $member = $org->memberships()->create(['user_id' => $user->id]);
    $role = app(SaveRole::class)->handle($org->owner_user_id, $org, null, 'Recruiter', PermissionKey::MembersView, PermissionKey::MembersInvite);
    app(AssignMembershipRole::class)->handle($member, $role);
    $invitee = User::factory()->create();
    [$privileged] = issuedInvitation($org, $invitee, [$role->id]);
    $base = '/api/v1/organizations/'.$org->id;
    $this->actingAs($user)->getJson($base.'/members')->assertOk();
    $this->getJson($base.'/invitations')->assertOk();
    $this->postJson($base.'/invitations', ['email' => 'new@example.test', 'roles' => []])->assertCreated();
    $this->postJson($base.'/invitations', ['email' => 'escalate@example.test', 'roles' => [$role->id]])->assertForbidden();
    $this->postJson($base.'/invitations', ['email' => $invitee->email, 'roles' => []])->assertForbidden();
    $this->deleteJson($base.'/invitations/'.$privileged->id)->assertForbidden();
    $this->putJson($base.'/members/'.$member->id.'/roles', ['roles' => [$role->id]])->assertForbidden();
    $this->postJson($base.'/members/'.$member->id.'/suspend')->assertForbidden();
    $this->postJson($base.'/members/'.$member->id.'/activate')->assertForbidden();
    $this->deleteJson($base.'/members/'.$member->id)->assertForbidden();
    expect($privileged->fresh()->state)->toBe(InvitationState::Pending);
});

it('never accepts guest or spoofed identity payload and hides unknown credentials', function () {
    $org = usersOrganization();
    $invited = User::factory()->create();
    [$invitation, $token] = issuedInvitation($org, $invited);
    auth()->forgetGuards();
    $this->postJson('/api/v1/invitations/'.$invitation->id.'/accept', ['token' => $token])->assertUnauthorized();
    $bob = User::factory()->create();
    $this->actingAs($bob)->postJson('/api/v1/invitations/'.$invitation->id.'/accept', ['token' => $token, 'email' => $invited->email, 'user_id' => $invited->id, 'verified' => true])->assertUnprocessable()->assertJsonPath('reason', 'email_mismatch');
    $unknown = $this->postJson('/api/v1/invitations/01AAAAAAAAAAAAAAAAAAAAAAAA/accept', ['token' => $token])->assertUnprocessable()->json();
    $invalid = $this->postJson('/api/v1/invitations/'.$invitation->id.'/accept', ['token' => str_repeat('0', 64)])->assertUnprocessable()->json();
    expect($unknown)->toBe($invalid);
    expect($org->memberships()->count())->toBe(1);
});

it('allows repeatable roleless delegated revocation and rejects malformed role selection', function () {
    $org = usersOrganization();
    $recruiter = User::factory()->create();
    $member = $org->memberships()->create(['user_id' => $recruiter->id]);
    $role = app(SaveRole::class)->handle($org->owner_user_id, $org, null, 'Invite only', PermissionKey::MembersInvite);
    app(AssignMembershipRole::class)->handle($member, $role);
    $this->actingAs($recruiter);
    $base = '/api/v1/organizations/'.$org->id;
    $this->getJson($base.'/members')->assertForbidden();
    $created = $this->postJson($base.'/invitations', ['email' => 'roleless@example.test', 'roles' => []])->assertCreated();
    $this->deleteJson($base.'/invitations/'.$created->json('data.id'))->assertNoContent();
    $this->deleteJson($base.'/invitations/'.$created->json('data.id'))->assertNoContent();
    $this->actingAs($org->owner);
    foreach ([['roles' => 'invalid'], ['roles' => [$role->id, $role->id]], ['roles' => ['not-ulid']], ['email' => 'invalid']] as $invalid) {
        $this->postJson($base.'/invitations', array_replace(['email' => 'valid@example.test', 'roles' => []], $invalid))->assertUnprocessable();
    }
});
