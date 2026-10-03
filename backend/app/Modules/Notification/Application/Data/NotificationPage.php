<?php

namespace App\Modules\Notification\Application\Data;

final readonly class NotificationPage
{
    /** @param list<NotificationView> $notifications */
    public function __construct(public array $notifications, public ?string $nextCursor, public bool $hasMore, public int $perPage) {}
}
