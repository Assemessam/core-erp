<?php

namespace App\Modules\Notification\Application\Commands;

use App\Modules\Notification\Application\Contracts\NotificationReadStore;
use App\Modules\Notification\Application\Operations\ResolveNotificationMembership;

final class MarkAllNotificationsRead
{
    public function __construct(private readonly ResolveNotificationMembership $membership, private readonly NotificationReadStore $store) {}

    public function handle(int $actorUserId, string $organizationId): void
    {
        $this->store->markAllRead($this->membership->handle($actorUserId, $organizationId));
    }
}
