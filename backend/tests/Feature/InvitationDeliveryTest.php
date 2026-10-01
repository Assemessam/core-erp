<?php

use App\Modules\Organization\Application\Exceptions\InvitationDeliveryFailed;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use App\Modules\Organization\Infrastructure\Mail\InvitationDelivery;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

// Resolve real Laravel mailers; Mail::fake() would hide transport selection.
beforeEach(function () {
    config(['organization.invitation_mailer' => 'smtp', 'mail.mailers.smtp.url' => null]);
    Mail::forgetMailers();
    $this->app->instance(LoggerInterface::class, Mockery::spy(LoggerInterface::class));
    Event::fake([MessageSending::class, MessageSent::class]);
    $this->invitation = (new OrganizationInvitation)->forceFill([
        'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'email' => 'invitee@example.test',
    ]);
    $this->token = bin2hex(random_bytes(32));
});

function assertInvitationTransportRejected(OrganizationInvitation $invitation, string $token): void
{
    try {
        app(InvitationDelivery::class)->send($invitation, 'Transport security', $token);
        test()->fail('Expected unsafe invitation transport to be rejected.');
    } catch (InvitationDeliveryFailed $failure) {
        expect($failure->getMessage())->toBe('Invitation saved, but email could not be sent. Refresh the list and reinvite to send a new link.');
        expect($failure->getPrevious())->toBeNull();
        expect((string) $failure)->not->toContain($token);
    }
    Event::assertNotDispatched(MessageSending::class);
    Event::assertNotDispatched(MessageSent::class);
    app(LoggerInterface::class)->shouldNotHaveReceived('debug');
    app(LoggerInterface::class)->shouldNotHaveReceived('log');
}

it('rejects an SMTP-named mailer whose URL resolves to logging before any send', function () {
    $cached = Mail::driver('smtp');
    expect($cached->getSymfonyTransport())->toBeInstanceOf(SmtpTransport::class);
    config(['mail.mailers.smtp.url' => 'log://localhost']);
    Mail::purge('smtp');
    $resolved = Mail::driver('smtp');
    expect(config('mail.mailers.smtp.transport'))->toBe('smtp');
    expect($resolved)->not->toBe($cached);
    expect($resolved->getSymfonyTransport())->toBeInstanceOf(LogTransport::class);

    assertInvitationTransportRejected($this->invitation, $this->token);
});

it('refuses log mailers for invitation credentials', function () {
    config(['organization.invitation_mailer' => 'log']);
    expect(Mail::driver('log')->getSymfonyTransport())->toBeInstanceOf(LogTransport::class);

    assertInvitationTransportRejected($this->invitation, $this->token);
});

it('rejects an SMTP URL override to array without retaining a credential-bearing message', function () {
    config(['mail.mailers.smtp.url' => 'array://localhost']);
    Mail::purge('smtp');
    $transport = Mail::driver('smtp')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    assertInvitationTransportRejected($this->invitation, $this->token);
    expect($transport->messages())->toBeEmpty();
});

it('rejects resolved debug and composite invitation transports before send', function (string $mailer) {
    config([
        'organization.invitation_mailer' => $mailer,
        'mail.mailers.roundrobin.mailers' => ['smtp', 'log'],
    ]);
    $transport = Mail::driver($mailer)->getSymfonyTransport();
    expect($transport)->toBeInstanceOf($mailer === 'array' ? ArrayTransport::class : RoundRobinTransport::class);

    assertInvitationTransportRejected($this->invitation, $this->token);
    if ($transport instanceof ArrayTransport) {
        expect($transport->messages())->toBeEmpty();
    }
})->with(['array', 'failover', 'roundrobin']);
