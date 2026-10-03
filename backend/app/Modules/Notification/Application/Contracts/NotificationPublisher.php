<?php

namespace App\Modules\Notification\Application\Contracts;

use App\Modules\Notification\Application\Data\NotificationDraft;

interface NotificationPublisher
{
    public function publish(#[\SensitiveParameter] NotificationDraft $notification): void;
}
