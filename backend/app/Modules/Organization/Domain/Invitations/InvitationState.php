<?php

namespace App\Modules\Organization\Domain\Invitations;

enum InvitationState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired'; // Derived, never persisted.
}
