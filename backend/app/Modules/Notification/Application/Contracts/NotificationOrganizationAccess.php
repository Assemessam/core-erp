<?php

namespace App\Modules\Notification\Application\Contracts;

use App\Modules\Notification\Application\Data\NotificationMembershipContext;

interface NotificationOrganizationAccess
{
    /** Resolve fresh active membership for a recipient or a trusted authenticated actor. */
    public function resolveActiveMembership(int $userId, string $organizationId): NotificationMembershipContext;
}
