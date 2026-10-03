<?php

namespace Tests\Support;

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationTarget;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;

final class NotificationFixtures
{
    public const string ORGANIZATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public const string INVITATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    /** @param array<string, mixed> $overrides */
    public static function draft(array $overrides = []): NotificationDraft
    {
        return new NotificationDraft(...array_replace([
            'organizationId' => self::ORGANIZATION_ID,
            'recipientUserId' => 12,
            'type' => NotificationType::OrganizationInvitationAccepted,
            'payload' => ['invitation_id' => self::INVITATION_ID, 'membership_id' => '47', 'accepted_user_id' => 29],
            'target' => new NotificationTarget(NotificationTargetType::OrganizationUsers),
            'payloadVersion' => 1,
        ], $overrides));
    }
}
