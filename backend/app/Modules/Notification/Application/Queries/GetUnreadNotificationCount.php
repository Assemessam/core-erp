<?php

namespace App\Modules\Notification\Application\Queries;

use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Operations\ResolveNotificationMembership;

final class GetUnreadNotificationCount
{
    public function __construct(private readonly ResolveNotificationMembership $membership, private readonly NotificationReader $reader) {}

    public function handle(int $actorUserId, string $organizationId): int
    {
        return $this->reader->unreadCount($this->membership->handle($actorUserId, $organizationId));
    }
}
