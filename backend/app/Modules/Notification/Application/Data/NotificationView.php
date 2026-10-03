<?php

namespace App\Modules\Notification\Application\Data;

/** Deliberate public projection; raw payload and recipient/tenant scope never leave persistence. */
final readonly class NotificationView
{
    public function __construct(
        public string $id,
        public string $type,
        public int $payloadVersion,
        public string $title,
        public string $body,
        public ?string $targetType,
        public ?string $targetId,
        public ?string $readAt,
        public string $createdAt,
    ) {}
}
