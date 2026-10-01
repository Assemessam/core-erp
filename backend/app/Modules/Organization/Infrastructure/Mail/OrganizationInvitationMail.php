<?php

namespace App\Modules\Organization\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OrganizationInvitationMail extends Mailable
{
    public function __construct(public readonly string $organizationName, public readonly string $invitationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Organization invitation');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.organization-invitation');
    }
}
