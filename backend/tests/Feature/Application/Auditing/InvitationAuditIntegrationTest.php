<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RevokeInvitation;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Infrastructure\Mail\OrganizationInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LifecycleAuditAssertions as AuditFacts;

beforeEach(function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->owner = User::factory()->create();
    $this->invitee = User::factory()->create();
    $this->org = app(CreateOrganization::class)->handle($this->owner->id, 'Invitation audit');
    $this->first = $this->org->roles()->create(['name' => 'First']);
    $this->second = $this->org->roles()->create(['name' => 'Second']);
    $this->token = bin2hex(random_bytes(32));
    // Raw pending fixture isolates acceptance/revocation from issuance.
    $this->invite = $this->org->invitations()->create([
        'email' => $this->invitee->email, 'inviter_user_id' => $this->owner->id,
        'token_hash' => hash('sha256', $this->token), 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(),
    ]);
    $this->invite->roles()->attach([$this->second->id, $this->first->id], ['organization_id' => $this->org->id]);
});

it('records issuance from persisted grants and canonical persisted UTC expiration', function () {
    $user = User::factory()->create();
    $this->actingAs($this->invitee);
    $this->travelTo(now()->setMicrosecond(123456));
    config(['app.timezone' => 'Africa/Cairo']);
    $invite = app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $user->email, [$this->second->id, $this->first->id, $this->second->id]);
    $persisted = $invite->fresh();
    AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.created', 'invitation', $invite->id, null, [
        'state' => 'pending', 'expires_at' => $persisted->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        'role_ids' => AuditFacts::sorted([$this->first->id, $this->second->id]),
    ]);
    expect($invite->roles()->count())->toBe(2);
    Mail::assertSent(OrganizationInvitationMail::class, 1);
});

it('records replacement as exactly two facts with the final replacement ID', function () {
    $old = $this->invite;
    $new = app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $this->invitee->email, [$this->second->id]);
    expect($old->fresh()->state)->toBe(InvitationState::Revoked);
    expect($new->state)->toBe(InvitationState::Pending);
    AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.revoked', 'invitation', $old->id,
        ['state' => 'pending'], ['state' => 'revoked', 'replacement_invitation_id' => $new->id, 'reason' => 'replaced']);
    AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.created', 'invitation', $new->id, null,
        ['state' => 'pending', 'expires_at' => $new->fresh()->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'role_ids' => [$this->second->id]]);
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'like', 'invitation.%')->count())->toBe(2);
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $old->id, $this->token))->toThrow(InvitationRejected::class, 'revoked');
    Mail::assertSent(OrganizationInvitationMail::class, 1);
});

it('records revoke once and uses fresh persisted state for repeated non-pending no-ops', function (InvitationState $state) {
    $stale = $this->invite;
    if ($state !== InvitationState::Pending) {
        DB::table('organization_invitations')->where('id', $stale->id)->update(['state' => $state->value]);
    }
    app(RevokeInvitation::class)->handle($this->owner->id, $this->org->id, $stale->id);
    app(RevokeInvitation::class)->handle($this->owner->id, $this->org->id, $stale->id);
    $events = DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'invitation.revoked');
    expect($events->count())->toBe($state === InvitationState::Pending ? 1 : 0);
    if ($state === InvitationState::Pending) {
        AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.revoked', 'invitation', $stale->id,
            ['state' => 'pending'], ['state' => 'revoked']);
    }
})->with([InvitationState::Pending, InvitationState::Revoked, InvitationState::Accepted]);

it('attributes roleless delegated issuance and revocation to the explicit caller', function () {
    $recruiter = User::factory()->create();
    $member = $this->org->memberships()->create(['user_id' => $recruiter->id]);
    $this->first->permissions()->sync(['members.invite']);
    $member->roles()->attach($this->first->id, ['organization_id' => $this->org->id]);
    $this->actingAs($this->owner);
    $invite = app(CreateInvitation::class)->handle($recruiter->id, $this->org->id, User::factory()->create()->email, []);
    AuditFacts::fact($this->org->id, $recruiter->id, 'invitation.created', 'invitation', $invite->id, null,
        ['state' => 'pending', 'expires_at' => $invite->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'role_ids' => []]);
    // Persisted inviter is deliberately different; it is not the revocation actor.
    $invite->update(['inviter_user_id' => $this->owner->id]);
    app(RevokeInvitation::class)->handle($recruiter->id, $this->org->id, $invite->id);
    AuditFacts::fact($this->org->id, $recruiter->id, 'invitation.revoked', 'invitation', $invite->id,
        ['state' => 'pending'], ['state' => 'revoked']);
});

it('records one acceptance after membership grants and accepted state and rejects replay without history', function () {
    $this->actingAs($this->owner);
    $result = app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invite->id, $this->token);
    expect($result->id)->toBe($this->org->id);
    $member = $this->org->memberships()->where('user_id', $this->invitee->id)->sole();
    $ids = AuditFacts::sorted($member->roles()->pluck('roles.id')->all());
    expect($ids)->toBe(AuditFacts::sorted([$this->first->id, $this->second->id]));
    AuditFacts::fact($this->org->id, $this->invitee->id, 'invitation.accepted', 'invitation', $this->invite->id,
        ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => $member->id, 'user_id' => $member->user_id, 'role_ids' => $ids]);
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invite->id, $this->token))->toThrow(InvitationRejected::class, 'already been accepted');
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->count())->toBe(2);
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->count())->toBe(1);
});

it('produces no acceptance fact on rejected identity credentials or fresh state', function (string $case) {
    $token = $this->token;
    $email = $this->invitee->email;
    $verified = true;
    if ($case === 'invalid') {
        $token = str_repeat('0', 64);
    } elseif ($case === 'wrong email') {
        $email = $this->owner->email;
    } elseif ($case === 'unverified') {
        $verified = false;
    } elseif ($case === 'expired') {
        $this->invite->update(['expires_at' => now()->subDay()]);
    } elseif (in_array($case, ['revoked', 'accepted'], true)) {
        DB::table('organization_invitations')->where('id', $this->invite->id)->update(['state' => $case]);
    } else {
        $this->org->memberships()->create(['user_id' => $this->invitee->id, 'status' => $case === 'suspended member' ? MembershipStatus::Suspended : MembershipStatus::Active]);
    }
    $count = DB::table('audit_events')->count();
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $email, $verified, $this->invite->id, $token))->toThrow(InvitationRejected::class);
    expect(DB::table('audit_events')->count())->toBe($count);
})->with(['invalid', 'wrong email', 'unverified', 'expired', 'revoked', 'accepted', 'active member', 'suspended member']);

it('rolls back issuance or replacement on either required audit failure and discards its mail callback', function (string $operation, int $failAt) {
    $email = $operation === 'new' ? User::factory()->create()->email : $this->invitee->email;
    $count = DB::table('audit_events')->count();
    $beforeInvitations = DB::table('organization_invitations')->count();
    $beforeGrants = DB::table('organization_invitation_role')->count();
    $oldHash = $this->invite->token_hash;
    $real = app(AuditRecorder::class);
    $recorder = new class($real, $failAt) implements AuditRecorder
    {
        public int $calls = 0;

        public function __construct(private AuditRecorder $real, private int $failAt) {}

        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            $this->calls++;
            if ($this->calls === $this->failAt) {
                throw new AuditWriteFailed('persistence_failed');
            }
            $this->real->record($entry);
        }
    };
    $this->app->instance(AuditRecorder::class, $recorder);
    expect(fn () => app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $email, [$this->second->id]))->toThrow(AuditWriteFailed::class);
    expect($recorder->calls)->toBe($failAt);
    expect(DB::table('audit_events')->count())->toBe($count);
    expect(DB::table('organization_invitations')->count())->toBe($beforeInvitations);
    expect(DB::table('organization_invitation_role')->count())->toBe($beforeGrants);
    expect($this->invite->fresh()->state)->toBe(InvitationState::Pending);
    expect(hash_equals($oldHash, $this->invite->fresh()->token_hash))->toBeTrue();
    DB::transaction(fn () => null); // A later successful transaction must not release abandoned mail.
    Mail::assertNothingSent();
    // Old credential still accepts after the failed replacement; restore only this test's binding.
    $this->app->instance(AuditRecorder::class, $real);
    app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invite->id, $this->token);
})->with(['new' => ['new', 1], 'replacement revocation insert' => ['replacement', 1], 'replacement creation insert' => ['replacement', 2]]);

it('rolls back pending revocation when audit recording fails', function () {
    $before = DB::table('audit_events')->count();
    $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
    {
        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            throw new AuditWriteFailed('persistence_failed');
        }
    });
    expect(fn () => app(RevokeInvitation::class)->handle($this->owner->id, $this->org->id, $this->invite->id))->toThrow(AuditWriteFailed::class);
    expect($this->invite->fresh()->state)->toBe(InvitationState::Pending);
    expect(DB::table('audit_events')->count())->toBe($before);
});

it('rolls back accepted membership grants and state on failure before or after a real audit insert', function (bool $insertFirst) {
    $before = DB::table('audit_events')->count();
    $real = app(AuditRecorder::class);
    $recorder = new class($real, $insertFirst) implements AuditRecorder
    {
        public function __construct(private AuditRecorder $real, private bool $insertFirst) {}

        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            expect(DB::table('organization_invitations')->where('id', $entry->subject->id)->value('state'))->toBe('accepted');
            expect(DB::table('organization_memberships')->where('id', $entry->after['membership_id'])->exists())->toBeTrue();
            expect(DB::table('organization_membership_role')->where('organization_membership_id', $entry->after['membership_id'])->count())->toBe(2);
            if ($this->insertFirst) {
                $this->real->record($entry);
                expect(DB::table('audit_events')->where('subject_id', $entry->subject->id)->where('action', 'invitation.accepted')->count())->toBe(1);
                throw new RuntimeException('Deliberate failure after acceptance audit insert');
            }
            throw new AuditWriteFailed('persistence_failed');
        }
    };
    $this->app->instance(AuditRecorder::class, $recorder);
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invite->id, $this->token))
        ->toThrow($insertFirst ? RuntimeException::class : AuditWriteFailed::class);
    expect($this->invite->fresh()->state)->toBe(InvitationState::Pending);
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->exists())->toBeFalse();
    expect(DB::table('organization_membership_role')->where('organization_id', $this->org->id)->exists())->toBeFalse();
    expect(DB::table('audit_events')->count())->toBe($before);
})->with([false, true]);

it('excludes credentials email and URLs from exact invitation audit snapshots', function () {
    $created = app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $this->invitee->email, []);
    $mail = Mail::sent(OrganizationInvitationMail::class)->sole();
    parse_str(parse_url($mail->invitationUrl, PHP_URL_FRAGMENT), $fragment);
    app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $created->id, $fragment['token']);
    $member = $this->org->memberships()->where('user_id', $this->invitee->id)->sole();
    AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.revoked', 'invitation', $this->invite->id,
        ['state' => 'pending'], ['state' => 'revoked', 'reason' => 'replaced', 'replacement_invitation_id' => $created->id]);
    AuditFacts::fact($this->org->id, $this->owner->id, 'invitation.created', 'invitation', $created->id, null,
        ['state' => 'pending', 'expires_at' => $created->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'role_ids' => []]);
    AuditFacts::fact($this->org->id, $this->invitee->id, 'invitation.accepted', 'invitation', $created->id,
        ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => $member->id, 'user_id' => $member->user_id, 'role_ids' => []]);
    $rows = DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'like', 'invitation.%')->get();
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        $json = ($row->before ?? '').($row->after ?? '');
        foreach ([$this->token, $fragment['token'], $this->invite->token_hash, $created->token_hash, $this->invitee->email, $this->owner->email, $mail->invitationUrl] as $sensitive) {
            expect(str_contains($json, $sensitive))->toBeFalse();
        }
    }
});

it('keeps invitation identity and credentials out of audit failure traces even when PHP includes arguments', function (bool $accepting) {
    $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
    {
        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            throw new AuditWriteFailed('persistence_failed');
        }
    });
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        if ($accepting) {
            app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invite->id, $this->token);
        } else {
            app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $this->invitee->email, []);
        }
        $this->fail('Expected safe audit failure.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->getMessage())->toBe('Audit recording failed.');
        expect($failure->getPrevious())->toBeNull();
        foreach ([$this->invitee->email, $this->owner->email, $this->token, $this->invite->token_hash] as $sensitive) {
            expect(str_contains((string) $failure, $sensitive))->toBeFalse();
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
})->with(['issuance' => false, 'acceptance' => true]);
