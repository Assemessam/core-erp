<?php

namespace App\Modules\Notification\Application\Data;

final readonly class NotificationCriteria
{
    public function __construct(public int $perPage, public ?NotificationCursor $cursor) {}
}
