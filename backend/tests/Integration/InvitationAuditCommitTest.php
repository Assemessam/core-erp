<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\DisposableConcurrencyDatabase;
use Tests\Support\LifecycleAuditAssertions as AuditFacts;

it('discards after-commit invitation mail on physical rollback including failed replacement audit insertion', function (bool $replacement) {
    DisposableConcurrencyDatabase::run(function () use ($replacement): void {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $org = app(CreateOrganization::class)->handle($owner->id, 'Commit rollback');
        $old = $replacement ? $org->invitations()->create([
            'email' => $invitee->email, 'inviter_user_id' => $owner->id,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))), 'expires_at' => now()->addDay(),
        ]) : null;
        $real = app(AuditRecorder::class);
        app()->instance(AuditRecorder::class, new class($real, $replacement ? 2 : 1) implements AuditRecorder
        {
            private int $calls = 0;

            public function __construct(private AuditRecorder $real, private int $failAt) {}

            public function record(#[SensitiveParameter] AuditEntry $entry): void
            {
                if (++$this->calls === $this->failAt) {
                    throw new AuditWriteFailed('persistence_failed');
                }
                $this->real->record($entry);
            }
        });
        expect(fn () => app(CreateInvitation::class)->handle($owner->id, $org->id, $invitee->email, []))->toThrow(AuditWriteFailed::class);
        expect(DB::transactionLevel())->toBe(0);
        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        expect($org->invitations()->count())->toBe($replacement ? 1 : 0);
        if ($old !== null) {
            expect($old->fresh()->state)->toBe(InvitationState::Pending);
        }
        expect(DB::table('audit_events')->where('organization_id', $org->id)->where('action', 'like', 'invitation.%')->count())->toBe(0);
        DB::transaction(fn () => null);
        Mail::assertNothingSent();
    });
})->with(['issuance' => false, 'replacement' => true])->group('integration');

it('keeps physically committed invitation and audit history when effective SMTP transport fails with the existing safe 503', function () {
    DisposableConcurrencyDatabase::run(function (): void {
        config(['organization.invitation_mailer' => 'smtp', 'mail.mailers.smtp.url' => null]);
        Mail::forgetMailers();
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        $org = app(CreateOrganization::class)->handle($owner->id, 'SMTP commit');
        $transport = new class extends SmtpTransport
        {
            public int $attempts = 0;

            public function send(#[SensitiveParameter] RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->attempts++;
                expect(DB::transactionLevel())->toBe(0);
                expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
                expect(DB::table('audit_events')->where('action', 'invitation.created')->count())->toBe(1);
                throw new TransportException('Deliberate SMTP transport failure');
            }
        };
        Mail::driver('smtp')->setSymfonyTransport($transport);
        test()->actingAs($owner)->postJson('/api/v1/organizations/'.$org->id.'/invitations', [
            'email' => $invitee->email, 'roles' => [],
        ])->assertStatus(503)->assertExactJson(['message' => 'Invitation saved, but email could not be sent. Refresh the list and reinvite to send a new link.']);
        expect($transport->attempts)->toBe(1);
        $invitation = $org->invitations()->sole();
        expect($invitation->state)->toBe(InvitationState::Pending);
        AuditFacts::fact($org->id, $owner->id, 'invitation.created', 'invitation', $invitation->id, null, [
            'state' => 'pending', 'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'role_ids' => [],
        ]);
    });
})->group('integration');
