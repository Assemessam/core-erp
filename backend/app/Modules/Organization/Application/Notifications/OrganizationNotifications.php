<?php

namespace App\Modules\Organization\Application\Notifications;

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationTarget;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;

/** Organization projects trusted business facts; Notification resolves the recipient era. */
final class OrganizationNotifications
{
    public function invitationAccepted(string $organizationId, int $ownerUserId, string $invitationId, int $membershipId, int $acceptedUserId): NotificationDraft
    {
        return new NotificationDraft(
            $organizationId, $ownerUserId, NotificationType::OrganizationInvitationAccepted,
            ['invitation_id' => $invitationId, 'membership_id' => (string) $membershipId, 'accepted_user_id' => $acceptedUserId],
            new NotificationTarget(NotificationTargetType::OrganizationUsers),
        );
    }
}
