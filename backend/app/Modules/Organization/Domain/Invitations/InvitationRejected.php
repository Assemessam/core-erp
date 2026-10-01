<?php

namespace App\Modules\Organization\Domain\Invitations;

use DomainException;

final class InvitationRejected extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'email_mismatch' => 'Sign in with the email address that received this invitation.',
            'unverified' => 'Verify your email before accepting this invitation.',
            'expired' => 'This invitation has expired. Request a new invitation.',
            'revoked' => 'This invitation has been revoked. Request a new invitation.',
            'accepted' => 'This invitation has already been accepted.',
            'member_exists' => 'A membership already exists. Suspended members must be reactivated by the owner.',
            default => 'This invitation is invalid.',
        });
    }
}
