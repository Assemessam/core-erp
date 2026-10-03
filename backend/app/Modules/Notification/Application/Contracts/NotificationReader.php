<?php

namespace App\Modules\Notification\Application\Contracts;

use App\Modules\Notification\Application\Data\NotificationCriteria;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Data\NotificationPage;

/** Internal consumer persistence port; Application entry points authorize its scope. */
interface NotificationReader
{
    public function read(NotificationMembershipContext $context, NotificationCriteria $criteria): NotificationPage;

    public function unreadCount(NotificationMembershipContext $context): int;
}
