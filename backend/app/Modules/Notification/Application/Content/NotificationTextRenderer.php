<?php

namespace App\Modules\Notification\Application\Content;

use App\Modules\Notification\Application\Data\NotificationText;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Vocabulary\NotificationType;

class NotificationTextRenderer
{
    /** @param array<array-key, mixed> $payload Validated type/version-specific payload only. */
    public function render(NotificationType $type, int $payloadVersion, #[\SensitiveParameter] array $payload): NotificationText
    {
        $userId = $payload['accepted_user_id'] ?? null;
        if ($payloadVersion !== 1 || ! is_int($userId) || $userId <= 0) {
            throw new NotificationWriteFailed('rendering_failed');
        }

        return match ($type) {
            NotificationType::OrganizationInvitationAccepted => new NotificationText(
                'Invitation accepted',
                'User #'.$userId.' accepted an invitation and joined the organization.',
            ),
        };
    }
}
