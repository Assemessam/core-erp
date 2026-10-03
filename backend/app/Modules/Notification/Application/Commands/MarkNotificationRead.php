<?php

namespace App\Modules\Notification\Application\Commands;

use App\Modules\Notification\Application\Contracts\NotificationReadStore;
use App\Modules\Notification\Application\Exceptions\NotificationNotFound;
use App\Modules\Notification\Application\Operations\ResolveNotificationMembership;

final class MarkNotificationRead
{
    public function __construct(private readonly ResolveNotificationMembership $membership, private readonly NotificationReadStore $store) {}

    public function handle(int $actorUserId, string $organizationId, string $notificationId): void
    {
        $context = $this->membership->handle($actorUserId, $organizationId);
        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $notificationId) !== 1 || ! $this->store->markRead($context, $notificationId)) {
            throw new NotificationNotFound;
        }
    }
}
