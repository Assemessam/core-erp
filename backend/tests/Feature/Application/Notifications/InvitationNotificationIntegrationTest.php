<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use App\Modules\Organization\Infrastructure\Mail\OrganizationInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\LifecycleAuditAssertions as AuditFacts;

beforeEach(function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $this->owner = User::factory()->create();
    $this->invitee = User::factory()->create();
    $this->org = app(CreateOrganization::class)->handle($this->owner->id, 'Acceptance notification');
    $this->ownerMembership = $this->org->memberships()->where('user_id', $this->owner->id)->sole();
    $this->first = $this->org->roles()->create(['name' => 'First']);
    $this->second = $this->org->roles()->create(['name' => 'Second']);
    $this->token = bin2hex(random_bytes(32));
    $this->invitation = $this->org->invitations()->create([
        'email' => $this->invitee->email, 'inviter_user_id' => $this->owner->id,
        'token_hash' => hash('sha256', $this->token), 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(),
    ]);
    $this->invitation->roles()->attach([$this->second->id, $this->first->id], ['organization_id' => $this->org->id]);
    $this->url = '/api/v1/organizations/'.$this->org->id.'/notifications';
});

it('persists the exact unread owner notification with roles audit and database time through direct acceptance', function () {
    $this->actingAs($this->owner)->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    $serverBefore = DB::selectOne('SELECT clock_timestamp() AS time')->time;
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        // A different ambient actor must not select either the acceptance actor or recipient.
        $this->actingAs(User::factory()->create());
        $result = app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invitation->id, $this->token);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect($result->id)->toBe($this->org->id);
    expect($this->invitation->fresh()->state)->toBe(InvitationState::Accepted);
    $membership = $this->org->memberships()->where('user_id', $this->invitee->id)->sole();
    $roles = AuditFacts::sorted([$this->first->id, $this->second->id]);
    expect(AuditFacts::sorted($membership->roles()->pluck('roles.id')->all()))->toBe($roles);
    AuditFacts::fact($this->org->id, $this->invitee->id, 'invitation.accepted', 'invitation', $this->invitation->id,
        ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => $membership->id, 'user_id' => $this->invitee->id, 'role_ids' => $roles]);

    $row = DB::table('organization_notifications')->where('organization_id', $this->org->id)->sole();
    expect(array_keys((array) $row))->toEqualCanonicalizing([
        'id', 'organization_id', 'recipient_user_id', 'recipient_membership_id', 'type', 'payload_version', 'payload', 'title', 'body', 'target_type', 'target_id', 'read_at', 'created_at',
    ]);
    expect($row->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
    expect($row->organization_id)->toBe($this->org->id);
    expect($row->recipient_user_id)->toBe($this->owner->id)->not->toBe($this->invitee->id);
    expect($row->recipient_membership_id)->toBe($this->ownerMembership->id)->not->toBe($membership->id);
    expect($row->type)->toBe('organization.invitation_accepted');
    expect($row->payload_version)->toBe(1);
    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toEqual(['invitation_id' => $this->invitation->id, 'membership_id' => (string) $membership->id, 'accepted_user_id' => $this->invitee->id]);
    expect(array_keys($payload))->toEqualCanonicalizing(['invitation_id', 'membership_id', 'accepted_user_id']);
    expect($payload['membership_id'])->toBe((string) $membership->id);
    expect($payload['accepted_user_id'])->toBe($this->invitee->id);
    expect($row->title)->toBe('Invitation accepted');
    expect($row->body)->toBe('User #'.$this->invitee->id.' accepted an invitation and joined the organization.');
    expect($row->target_type)->toBe('organization.users');
    expect($row->target_id)->toBeNull();
    expect($row->read_at)->toBeNull();
    expect(DB::selectOne('SELECT created_at >= ?::timestamptz AND created_at <= clock_timestamp() AS valid FROM organization_notifications WHERE id = ?', [$serverBefore, $row->id])->valid)->toBeTrue();
    $writes = array_values(array_filter($queries, fn ($query) => str_starts_with($query['query'], 'update "organization_invitations"')
        || str_starts_with($query['query'], 'insert into "audit_events"') || str_starts_with($query['query'], 'insert into "organization_notifications"')));
    expect($writes)->toHaveCount(3);
    expect($writes[0]['query'])->toStartWith('update "organization_invitations"');
    expect($writes[1]['query'])->toStartWith('insert into "audit_events"');
    expect($writes[2]['query'])->toStartWith('insert into "organization_notifications"')->not->toContain('"created_at"', '"read_at"');
    Mail::assertNothingSent();

    $createdAt = (new DateTimeImmutable($row->created_at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    $response = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertExactJson([
        'data' => [[
            'id' => $row->id, 'type' => $row->type, 'payload_version' => 1, 'title' => $row->title, 'body' => $row->body,
            'target' => ['type' => 'organization.users', 'id' => null], 'read_at' => null, 'created_at' => $createdAt,
        ]],
        'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25],
    ]);
    expect($response->json('data.0'))->not->toHaveKeys(['payload', 'organization_id', 'recipient_user_id', 'recipient_membership_id']);
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
    $this->actingAs($this->invitee)->getJson($this->url)->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25]]);
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    $this->postJson($this->url.'/'.$row->id.'/read')->assertNotFound()->assertExactJson(['message' => 'Notification not found.']);
    expect(DB::table('organization_notifications')->where('id', $row->id)->value('read_at'))->toBeNull();
});

it('notifies the persisted owner rather than the authorized delegated inviter or accepting actor and excludes credentials', function () {
    $inviter = User::factory()->create();
    $member = $this->org->memberships()->create(['user_id' => $inviter->id]);
    $this->first->permissions()->sync(['members.invite']);
    $member->roles()->attach($this->first->id, ['organization_id' => $this->org->id]);
    $invitee = User::factory()->create();
    $invitation = app(CreateInvitation::class)->handle($inviter->id, $this->org->id, $invitee->email, []);
    expect($invitation->inviter_user_id)->toBe($inviter->id);
    $mail = Mail::sent(OrganizationInvitationMail::class)->sole();
    parse_str(parse_url($mail->invitationUrl, PHP_URL_FRAGMENT), $fragment);
    expect(DB::table('organization_notifications')->where('organization_id', $this->org->id)->count())->toBe(0);
    Mail::fake();
    $this->actingAs($invitee)->postJson('/api/v1/invitations/'.$invitation->id.'/accept', [
        'token' => $fragment['token'], 'recipient_user_id' => $inviter->id, 'recipient_membership_id' => $member->id,
        'organization_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'read_at' => '2099-01-01',
    ])->assertOk()->assertJsonPath('data.id', $this->org->id);
    $acceptedMembership = $this->org->memberships()->where('user_id', $invitee->id)->sole();
    $row = DB::table('organization_notifications')->where('organization_id', $this->org->id)->sole();
    expect($row->organization_id)->toBe($this->org->id);
    expect($row->recipient_user_id)->toBe($this->owner->id)->not->toBeIn([$inviter->id, $invitee->id]);
    expect($row->recipient_membership_id)->toBe($this->ownerMembership->id)->not->toBeIn([$member->id, $acceptedMembership->id]);
    expect(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))->toEqual([
        'invitation_id' => $invitation->id, 'membership_id' => (string) $acceptedMembership->id, 'accepted_user_id' => $invitee->id,
    ]);
    $persistedContent = $row->payload.$row->title.$row->body.$row->target_type.($row->target_id ?? '');
    foreach ([$fragment['token'], $invitation->token_hash, $invitation->email, $invitee->email, $this->owner->email, $inviter->email, $mail->invitationUrl] as $sensitive) {
        // Avoid dumping credential-bearing actual values on a test failure.
        expect(str_contains($persistedContent, $sensitive))->toBeFalse();
    }
    expect($row->read_at)->toBeNull();
    Mail::assertNothingSent();
});

it('leaves exactly one notification on HTTP and direct invitation replay', function () {
    $this->actingAs($this->invitee)->postJson('/api/v1/invitations/'.$this->invitation->id.'/accept', ['token' => $this->token])->assertOk();
    $row = (array) DB::table('organization_notifications')->where('organization_id', $this->org->id)->sole();
    $this->postJson('/api/v1/invitations/'.$this->invitation->id.'/accept', ['token' => $this->token])->assertUnprocessable()->assertJsonPath('reason', 'accepted');
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invitation->id, $this->token))->toThrow(InvitationRejected::class);
    expect((array) DB::table('organization_notifications')->where('organization_id', $this->org->id)->sole())->toBe($row);
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->count())->toBe(1);
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'invitation.accepted')->count())->toBe(1);
});

it('propagates required publisher failure and rolls back acceptance grants and the already inserted audit', function () {
    $publisher = new class implements NotificationPublisher
    {
        public int $calls = 0;

        public function publish(#[SensitiveParameter] NotificationDraft $notification): void
        {
            $this->calls++;
            expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
            expect(DB::table('organization_invitations')->where('id', $notification->payload['invitation_id'])->value('state'))->toBe('accepted');
            expect(DB::table('organization_memberships')->where('id', $notification->payload['membership_id'])->exists())->toBeTrue();
            expect(DB::table('organization_membership_role')->where('organization_membership_id', $notification->payload['membership_id'])->count())->toBe(2);
            expect(DB::table('audit_events')->where('subject_id', $notification->payload['invitation_id'])->where('action', 'invitation.accepted')->count())->toBe(1);
            throw new NotificationWriteFailed('persistence_failed');
        }
    };
    $this->app->instance(NotificationPublisher::class, $publisher);
    $before = $this->invitation->fresh()->getRawOriginal();
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    try {
        app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invitation->id, $this->token);
        $this->fail('Expected required publication failure.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->getMessage())->toBe('Notification publication failed.');
        expect($failure->getPrevious())->toBeNull();
        foreach ([$this->token, $this->invitation->token_hash, $this->invitee->email, $this->owner->email] as $sensitive) {
            expect(str_contains((string) $failure, $sensitive))->toBeFalse();
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
    expect($publisher->calls)->toBe(1);
    expect($this->invitation->fresh()->getRawOriginal() === $before)->toBeTrue();
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->exists())->toBeFalse();
    expect(DB::table('organization_membership_role')->where('organization_id', $this->org->id)->exists())->toBeFalse();
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'invitation.accepted')->exists())->toBeFalse();
    expect(DB::table('organization_notifications')->where('organization_id', $this->org->id)->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('rolls back a real acceptance grant when assignment fails before either required fact', function () {
    $this->app->instance(AssignMembershipRole::class, new class extends AssignMembershipRole
    {
        public function handle(OrganizationMembership $membership, Role $role): void
        {
            parent::handle($membership, $role);
            expect(DB::table('organization_membership_role')->where('organization_membership_id', $membership->id)->count())->toBe(1);
            throw new RuntimeException('Deliberate acceptance grant failure');
        }
    });
    expect(fn () => app(AcceptInvitation::class)->handle($this->invitee->id, $this->invitee->email, true, $this->invitation->id, $this->token))
        ->toThrow(RuntimeException::class, 'Deliberate acceptance grant failure');
    expect($this->invitation->fresh()->state)->toBe(InvitationState::Pending);
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->exists())->toBeFalse();
    expect(DB::table('organization_membership_role')->where('organization_id', $this->org->id)->exists())->toBeFalse();
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->where('action', 'invitation.accepted')->exists())->toBeFalse();
    expect(DB::table('organization_notifications')->where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('rejects missing or malformed acceptance tokens before publication', function (array $input) {
    $this->actingAs($this->invitee)->postJson('/api/v1/invitations/'.$this->invitation->id.'/accept', $input)->assertUnprocessable()->assertJsonValidationErrors('token');
    expect($this->invitation->fresh()->state)->toBe(InvitationState::Pending);
    expect($this->org->memberships()->where('user_id', $this->invitee->id)->exists())->toBeFalse();
    expect(DB::table('organization_notifications')->where('organization_id', $this->org->id)->exists())->toBeFalse();
})->with(['missing' => [[]], 'malformed' => [['token' => ['invalid']]]]);
