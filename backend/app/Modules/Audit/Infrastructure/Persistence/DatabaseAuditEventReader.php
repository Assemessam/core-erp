<?php

namespace App\Modules\Audit\Infrastructure\Persistence;

use App\Modules\Audit\Application\Contracts\AuditEventReader;
use App\Modules\Audit\Application\Data\AuditCursor;
use App\Modules\Audit\Application\Data\AuditEventCriteria;
use App\Modules\Audit\Application\Data\AuditEventPage;
use App\Modules\Audit\Application\Data\AuditEventView;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class DatabaseAuditEventReader implements AuditEventReader
{
    public function read(string $organizationId, AuditEventCriteria $criteria): AuditEventPage
    {
        $query = DB::table('audit_events')->where('organization_id', $organizationId);
        if ($criteria->cursor !== null) {
            $cursor = $criteria->cursor;
            $query->where(function (Builder $position) use ($cursor): void {
                $position->where('created_at', '<', $cursor->createdAt)
                    ->orWhere(fn (Builder $tie) => $tie->where('created_at', '=', $cursor->createdAt)->where('id', '<', $cursor->id));
            });
        }
        if ($criteria->action !== null) {
            $query->where('action', $criteria->action->value);
        }
        if ($criteria->subjectType !== null) {
            $query->where('subject_type', $criteria->subjectType->value)->where('subject_id', $criteria->subjectId);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit($criteria->perPage + 1)
            ->get(['id', 'actor_type', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'payload_version', 'created_at']);
        $hasMore = $rows->count() > $criteria->perPage;
        $events = [];
        foreach ($rows->take($criteria->perPage) as $row) {
            /** @var object{id: string, actor_type: string, actor_user_id: int|null, action: string, subject_type: string, subject_id: string, before: string|null, after: string|null, payload_version: int, created_at: string} $row */
            /** @var array<string, mixed>|null $before */
            $before = $row->before === null ? null : json_decode($row->before, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed>|null $after */
            $after = $row->after === null ? null : json_decode($row->after, true, 512, JSON_THROW_ON_ERROR);
            $events[] = new AuditEventView(
                $row->id, $row->action, $row->actor_type, $row->actor_user_id, $row->subject_type, $row->subject_id,
                $before, $after, $row->payload_version,
                (new DateTimeImmutable($row->created_at))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            );
        }
        $last = $events === [] ? null : $events[array_key_last($events)];

        return new AuditEventPage($events, $hasMore && $last !== null ? (new AuditCursor($last->createdAt, $last->id))->encode() : null, $hasMore, $criteria->perPage);
    }
}
