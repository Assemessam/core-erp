<?php

namespace App\Modules\Notification\Application\Data;

/** Server-rendered plain-text snapshots; never HTML or producer-supplied templates. */
final readonly class NotificationText
{
    public function __construct(public string $title, public string $body) {}
}
