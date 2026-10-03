<?php

namespace App\Modules\Organization\Infrastructure\Notification;

use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;

final class OrganizationNotificationAccess implements NotificationOrganizationAccess
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function resolveActiveMembership(int $userId, string $organizationId): NotificationMembershipContext
    {
        $this->access->view($userId, $organizationId)->requireAllowed();
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organizationId)->where('user_id', $userId)->where('status', MembershipStatus::Active)
            ->first(['id', 'organization_id', 'user_id']);
        if ($membership === null) {
            AccessDecision::hidden()->requireAllowed();
            throw new \LogicException('Unreachable denial.');
        }

        return new NotificationMembershipContext($membership->organization_id, $membership->user_id, $membership->id);
    }
}
