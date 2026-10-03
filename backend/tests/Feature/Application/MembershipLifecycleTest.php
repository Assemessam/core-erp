<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Application\Commands\ActivateMembership;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RemoveMembership;
use App\Modules\Organization\Application\Commands\RevokeInvitation;
use App\Modules\Organization\Application\Commands\SuspendMembership;
use App\Modules\Organization\Application\Commands\SyncMembershipRoles;
use App\Modules\Organization\Application\Exceptions\InvitationDeliveryFailed;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Infrastructure\Mail\InvitationDelivery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    // MailFake forwards the transport accessor to its manager's default mailer.
    config(['mail.default' => 'smtp']);
    Mail::fake();
});

it('authorizes all new direct writes without ambient authentication', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Direct');
    $member = $org->memberships()->create(['user_id' => User::factory()->create()->id]);
    $this->actingAs($owner);
    foreach ([SuspendMembership::class, ActivateMembership::class, RemoveMembership::class] as $command) {
        expect(fn () => app($command)->handle($member->user_id, $org->id, $member->id))->toThrow(AccessDenied::class);
    }
    expect(fn () => app(SyncMembershipRoles::class)->handle($member->user_id, $org->id, $member->id, []))->toThrow(AccessDenied::class);
    expect(fn () => app(CreateInvitation::class)->handle($member->user_id, $org->id, 'new@example.test', []))->toThrow(AccessDenied::class);
    expect(fn () => app(RevokeInvitation::class)->handle($member->user_id, $org->id, 'missing'))->toThrow(AccessDenied::class);
    expect($member->fresh()->status)->toBe(MembershipStatus::Active);
});

it('rejects foreign direct resources even when the actor owns both organizations', function () {
    $owner = User::factory()->create();
    $a = app(CreateOrganization::class)->handle($owner->id, 'A');
    $b = app(CreateOrganization::class)->handle($owner->id, 'B');
    $membership = $b->memberships()->sole();
    $invitation = app(CreateInvitation::class)->handle($owner->id, $b->id, 'new@example.test', []);
    foreach ([SuspendMembership::class, ActivateMembership::class, RemoveMembership::class] as $command) {
        expect(fn () => app($command)->handle($owner->id, $a->id, $membership->id))->toThrow(AccessDenied::class);
    }
    expect(fn () => app(SyncMembershipRoles::class)->handle($owner->id, $a->id, $membership->id, []))->toThrow(AccessDenied::class);
    expect(fn () => app(RevokeInvitation::class)->handle($owner->id, $a->id, $invitation->id))->toThrow(AccessDenied::class);
});

it('rolls back membership role grants and state after a late acceptance failure', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Atomic');
    $role = $org->roles()->create(['name' => 'Selected']);
    $token = bin2hex(random_bytes(32));
    $invitation = $org->invitations()->create(['email' => $user->email, 'inviter_user_id' => $owner->id, 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', $token)]);
    $invitation->roles()->attach($role->id, ['organization_id' => $org->id]);
    DB::listen(function (QueryExecuted $query) use ($invitation): void {
        if (str_starts_with($query->sql, 'update "organization_invitations"') && in_array($invitation->id, $query->bindings, true)) {
            throw new RuntimeException('Injected final state failure');
        }
    });
    expect(fn () => app(AcceptInvitation::class)->handle($user->id, $user->email, true, $invitation->id, $token))->toThrow(RuntimeException::class, 'Injected final state failure');
    expect($org->memberships()->where('user_id', $user->id)->exists())->toBeFalse();
    expect(DB::table('organization_membership_role')->where('role_id', $role->id)->exists())->toBeFalse();
    expect($invitation->fresh()->state)->toBe(InvitationState::Pending);
    expect(DB::table('audit_events')->where('organization_id', $org->id)->where('action', 'invitation.accepted')->exists())->toBeFalse();
    expect(DB::table('organization_notifications')->where('organization_id', $org->id)->exists())->toBeFalse();
});

it('rolls back role replacement on assignment failure', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Atomic roles');
    $member = $org->memberships()->sole();
    $old = $org->roles()->create(['name' => 'Old']);
    $new = $org->roles()->create(['name' => 'New']);
    app(AssignMembershipRole::class)->handle($member, $old);
    DB::listen(function (QueryExecuted $query) use ($new): void {
        if (str_starts_with($query->sql, 'insert into "organization_membership_role"') && in_array($new->id, $query->bindings, true)) {
            throw new RuntimeException('Assignment failed');
        }
    });
    expect(fn () => app(SyncMembershipRoles::class)->handle($owner->id, $org->id, $member->id, [$new->id]))->toThrow(RuntimeException::class);
    expect($member->roles()->pluck('roles.id')->all())->toBe([$old->id]);
});

it('checks verified identity on direct acceptance and rejects duplicate suspended membership', function () {
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Identity');
    $token = bin2hex(random_bytes(32));
    $invite = $org->invitations()->create(['email' => $user->email, 'inviter_user_id' => $owner->id, 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', $token)]);
    expect(fn () => app(AcceptInvitation::class)->handle($user->id, $user->email, false, $invite->id, $token))->toThrow(InvitationRejected::class, 'Verify your email');
    $org->memberships()->create(['user_id' => $user->id, 'status' => MembershipStatus::Suspended]);
    expect(fn () => app(AcceptInvitation::class)->handle($user->id, $user->email, true, $invite->id, $token))->toThrow(InvitationRejected::class, 'A membership already exists');
    expect($org->memberships()->where('user_id', $user->id)->count())->toBe(1);
});

it('rolls back reinvite rotation and sends no mail if selected grant persistence fails', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Reinvite atomicity');
    $old = app(CreateInvitation::class)->handle($owner->id, $org->id, 'invitee@example.test', []);
    $role = $org->roles()->create(['name' => 'Selected']);
    Mail::fake();
    DB::listen(function (QueryExecuted $query) use ($role): void {
        if (str_starts_with($query->sql, 'insert into "organization_invitation_role"') && in_array($role->id, $query->bindings, true)) {
            throw new RuntimeException('Invitation grant failed');
        }
    });
    expect(fn () => app(CreateInvitation::class)->handle($owner->id, $org->id, 'invitee@example.test', [$role->id]))->toThrow(RuntimeException::class, 'Invitation grant failed');
    expect($old->fresh()->state)->toBe(InvitationState::Pending);
    expect($org->invitations()->count())->toBe(1);
    Mail::assertNothingSent();
});

it('sanitizes mail failures without retaining transport credentials in exception traces', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Delivery failure');
    $invite = $org->invitations()->create(['email' => 'delivery@example.test', 'inviter_user_id' => $owner->id, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', 'delivery')]);
    $secret = bin2hex(random_bytes(32));
    Mail::shouldReceive('driver')->with('smtp')->andThrow(new RuntimeException('Sensitive transport body '.$secret));
    try {
        app(InvitationDelivery::class)->send($invite, $org->name, $secret);
        $this->fail('Expected sanitized delivery failure');
    } catch (InvitationDeliveryFailed $failure) {
        expect($failure->getPrevious())->toBeNull();
        expect((string) $failure)->not->toContain($secret);
        expect($failure->getMessage())->toContain('reinvite');
    }
});
