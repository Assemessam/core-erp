<?php

namespace App\Modules\Organization\Domain\Memberships;

final class MembershipRules
{
    public static function requireNotOwner(int $memberUserId, int $ownerUserId): void
    {
        if ($memberUserId === $ownerUserId) {
            throw new OwnerMembershipProtected;
        }
    }
}
