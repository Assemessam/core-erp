<?php

namespace App\Modules\Notification\Application\Contracts;

use App\Modules\Notification\Application\Data\NotificationMembershipContext;

/** Internal semantic read-state persistence port, never a generic update API. */
interface NotificationReadStore
{
    /** True for a scoped newly/already read row; false when absent from that scope. */
    public function markRead(NotificationMembershipContext $context, string $notificationId): bool;

    public function markAllRead(NotificationMembershipContext $context): void;
}
