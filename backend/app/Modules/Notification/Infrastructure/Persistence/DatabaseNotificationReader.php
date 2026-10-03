<?php

namespace App\Modules\Notification\Infrastructure\Persistence;

use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Data\NotificationCriteria;
use App\Modules\Notification\Application\Data\NotificationCursor;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Data\NotificationPage;
use App\Modules\Notification\Application\Data\NotificationView;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseNotificationReader implements NotificationReader
{
    public function read(NotificationMembershipContext $context, NotificationCriteria $criteria): NotificationPage
    {
        try {
            $query = $this->scoped($context);
            if ($criteria->cursor !== null) {
                $cursor = $criteria->cursor;
                $query->where(function (Builder $position) use ($cursor): void {
                    $position->where('created_at', '<', $cursor->createdAt)
                        ->orWhere(fn (Builder $tie) => $tie->where('created_at', '=', $cursor->createdAt)->where('id', '<', $cursor->id));
                });
            }
            $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($criteria->perPage + 1)
                ->get(['id', 'type', 'payload_version', 'title', 'body', 'target_type', 'target_id', 'read_at', 'created_at']);
            $hasMore = $rows->count() > $criteria->perPage;
            $notifications = [];
            foreach ($rows->take($criteria->perPage) as $row) {
                /** @var object{id: string, type: string, payload_version: int, title: string, body: string, target_type: string|null, target_id: string|null, read_at: string|null, created_at: string} $row */
                $notifications[] = new NotificationView(
                    $row->id, $row->type, $row->payload_version, $row->title, $row->body, $row->target_type, $row->target_id,
                    $row->read_at === null ? null : $this->utc($row->read_at), $this->utc($row->created_at),
                );
            }
            $last = $notifications === [] ? null : $notifications[array_key_last($notifications)];

            return new NotificationPage($notifications, $hasMore && $last !== null ? (new NotificationCursor($last->createdAt, $last->id))->encode() : null, $hasMore, $criteria->perPage);
        } catch (Throwable) {
            throw new NotificationStorageFailed;
        }
    }

    public function unreadCount(NotificationMembershipContext $context): int
    {
        try {
            return max(0, $this->scoped($context)->whereNull('read_at')->count());
        } catch (Throwable) {
            throw new NotificationStorageFailed;
        }
    }

    private function scoped(NotificationMembershipContext $context): Builder
    {
        return DB::table('organization_notifications')->where('organization_id', $context->organizationId)
            ->where('recipient_user_id', $context->userId)->where('recipient_membership_id', $context->membershipId);
    }

    private function utc(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
