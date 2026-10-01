<?php

namespace App\Modules\Organization\Domain\Invitations;

use DateTimeImmutable;

final class InvitationRules
{
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function requireAcceptable(InvitationState $state, DateTimeImmutable $expiresAt, DateTimeImmutable $now, string $invitedEmail, string $actorEmail, bool $verified): void
    {
        if (! $verified) {
            throw new InvitationRejected('unverified');
        }
        if (self::normalizeEmail($invitedEmail) !== self::normalizeEmail($actorEmail)) {
            throw new InvitationRejected('email_mismatch');
        }
        if ($state !== InvitationState::Pending) {
            throw new InvitationRejected($state->value);
        }
        if ($expiresAt <= $now) {
            throw new InvitationRejected('expired');
        }
    }
}
