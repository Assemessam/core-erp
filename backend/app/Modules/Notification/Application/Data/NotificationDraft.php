<?php

namespace App\Modules\Notification\Application\Data;

use App\Modules\Notification\Application\Vocabulary\NotificationType;

/** Producer input; recipient membership, text, ID and time are never producer-controlled. */
final readonly class NotificationDraft
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public string $organizationId,
        public int $recipientUserId,
        public NotificationType $type,
        #[\SensitiveParameter] public array $payload,
        public ?NotificationTarget $target = null,
        public int $payloadVersion = 1,
    ) {}
}
