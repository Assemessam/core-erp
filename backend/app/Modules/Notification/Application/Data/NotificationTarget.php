<?php

namespace App\Modules\Notification\Application\Data;

use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;

final readonly class NotificationTarget
{
    public function __construct(public NotificationTargetType $type, public ?string $id = null) {}
}
