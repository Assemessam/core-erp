<?php

namespace App\Modules\Notification\Application\Data;

/** Persisted active membership resolved by the owning context, not supplied by producers. */
final readonly class NotificationMembershipContext
{
    public function __construct(public string $organizationId, public int $userId, public int $membershipId) {}
}
