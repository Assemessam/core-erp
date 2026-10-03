<?php

namespace App\Modules\Notification\Application\Queries;

use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Data\NotificationPage;
use App\Modules\Notification\Application\Operations\ResolveNotificationMembership;
use App\Modules\Notification\Application\Validation\NotificationQueryValidator;

final class ListNotifications
{
    public function __construct(
        private readonly ResolveNotificationMembership $membership,
        private readonly NotificationQueryValidator $validator,
        private readonly NotificationReader $reader,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(int $actorUserId, string $organizationId, #[\SensitiveParameter] array $input = []): NotificationPage
    {
        $context = $this->membership->handle($actorUserId, $organizationId);

        return $this->reader->read($context, $this->validator->validate($input));
    }
}
