<?php

namespace App\Modules\Organization\Infrastructure\Mail;

use App\Modules\Organization\Application\Exceptions\InvitationDeliveryFailed;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

class InvitationDelivery
{
    public function send(OrganizationInvitation $invitation, string $organizationName, #[\SensitiveParameter] string $token): void
    {
        try {
            $mailer = Mail::driver((string) config('organization.invitation_mailer'));
            // Inspect the resolved transport: a mailer URL can override its configured driver.
            // Invitations remain SMTP-only; debug and composite transports are not supported.
            if (! $mailer->getSymfonyTransport() instanceof SmtpTransport) {
                throw new RuntimeException('Invitation delivery requires an SMTP mailer.');
            }
            $url = rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$invitation->id.'/accept#token='.$token;
            $mailer->to($invitation->email)->send(new OrganizationInvitationMail($organizationName, $url));
        } catch (Throwable) {
            // Never retain a transport/view exception: its trace may contain mail body bytes.
            throw new InvitationDeliveryFailed;
        }
    }
}
