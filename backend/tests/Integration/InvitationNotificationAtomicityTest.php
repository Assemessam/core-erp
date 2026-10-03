<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\DisposableConcurrencyDatabase;

it('physically rolls back business audit and notification after both real inserts and a later exception', function () {
    DisposableConcurrencyDatabase::run(function (): void {
        Mail::fake();
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $org = app(CreateOrganization::class)->handle($owner->id, 'Three-way rollback');
        $role = $org->roles()->create(['name' => 'Accepted grant']);
        $token = bin2hex(random_bytes(32));
        $invitation = $org->invitations()->create([
            'email' => $invitee->email, 'inviter_user_id' => $owner->id,
            'token_hash' => hash('sha256', $token), 'state' => InvitationState::Pending, 'expires_at' => now()->addDay(),
        ]);
        $invitation->roles()->attach($role->id, ['organization_id' => $org->id]);
        $before = $invitation->fresh()->getRawOriginal();
        $real = app(NotificationPublisher::class);
        $publisher = new class($real) implements NotificationPublisher
        {
            public bool $inserted = false;

            public function __construct(private NotificationPublisher $real) {}

            public function publish(#[SensitiveParameter] NotificationDraft $notification): void
            {
                expect(DB::transactionLevel())->toBe(1);
                expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
                expect(DB::table('organization_invitations')->where('id', $notification->payload['invitation_id'])->value('state'))->toBe('accepted');
                expect(DB::table('organization_memberships')->where('id', $notification->payload['membership_id'])->exists())->toBeTrue();
                expect(DB::table('organization_membership_role')->where('organization_membership_id', $notification->payload['membership_id'])->count())->toBe(1);
                expect(DB::table('audit_events')->where('action', 'invitation.accepted')->where('subject_id', $notification->payload['invitation_id'])->count())->toBe(1);
                $this->real->publish($notification);
                $row = DB::table('organization_notifications')->where('organization_id', $notification->organizationId)->sole();
                expect($row->recipient_user_id)->toBe($notification->recipientUserId);
                expect(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))->toEqual($notification->payload);
                $this->inserted = true;
                throw new RuntimeException('Deliberate failure after real Audit and Notification inserts');
            }
        };
        app()->instance(NotificationPublisher::class, $publisher);
        expect(fn () => app(AcceptInvitation::class)->handle($invitee->id, $invitee->email, true, $invitation->id, $token))
            ->toThrow(RuntimeException::class, 'Deliberate failure after real Audit and Notification inserts');
        expect($publisher->inserted)->toBeTrue();
        expect(DB::transactionLevel())->toBe(0);
        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        expect($invitation->fresh()->getRawOriginal() === $before)->toBeTrue();
        expect($org->memberships()->where('user_id', $invitee->id)->exists())->toBeFalse();
        expect(DB::table('organization_membership_role')->where('organization_id', $org->id)->exists())->toBeFalse();
        expect($invitation->roles()->pluck('roles.id')->all())->toBe([$role->id]);
        expect(DB::table('audit_events')->where('organization_id', $org->id)->where('action', 'invitation.accepted')->exists())->toBeFalse();
        expect(DB::table('organization_notifications')->where('organization_id', $org->id)->exists())->toBeFalse();
        expect(DB::table('audit_events')->where('organization_id', $org->id)->where('action', 'organization.created')->count())->toBe(1);
        DB::transaction(fn () => null);
        Mail::assertNothingSent();
    });
})->group('integration');
