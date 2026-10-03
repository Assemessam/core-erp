<?php

namespace App\Modules\Notification\Application\Operations;

use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Exceptions\NotificationNotFound;

/** Shared consumer scope resolution; every entry point independently calls it. */
final class ResolveNotificationMembership
{
    public function __construct(private readonly NotificationOrganizationAccess $access) {}

    public function handle(int $actorUserId, string $organizationId): NotificationMembershipContext
    {
        $context = $this->access->resolveActiveMembership($actorUserId, $organizationId);
        if ($context->organizationId !== $organizationId || $context->userId !== $actorUserId || $context->membershipId <= 0) {
            throw new NotificationNotFound;
        }

        return $context;
    }
}
