<?php

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Application\Contracts\NotificationReadStore;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseNotificationReadStore implements NotificationReadStore
{
    public function markRead(NotificationMembershipContext $context, string $notificationId): bool
    {
        try {
            $query = $this->scoped($context)->where('id', $notificationId);
            // Conditional UPDATE protects the first successful timestamp, including concurrent replay.
            // Clamp to creation if a clock adjustment or deliberate future-dated fixture puts it ahead.
            $changed = (clone $query)->whereNull('read_at')->update(['read_at' => DB::raw('GREATEST(clock_timestamp(), created_at)')]);

            return $changed > 0 || $query->exists();
        } catch (Throwable) {
            throw new NotificationStorageFailed;
        }
    }

    public function markAllRead(NotificationMembershipContext $context): void
    {
        try {
            $this->scoped($context)->whereNull('read_at')->update(['read_at' => DB::raw('GREATEST(clock_timestamp(), created_at)')]);
        } catch (Throwable) {
            throw new NotificationStorageFailed;
        }
    }

    private function scoped(NotificationMembershipContext $context): Builder
    {
        return DB::table('organization_notifications')->where('organization_id', $context->organizationId)
            ->where('recipient_user_id', $context->userId)->where('recipient_membership_id', $context->membershipId);
    }
}
