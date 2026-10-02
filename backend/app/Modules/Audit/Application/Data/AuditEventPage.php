<?php

namespace App\Modules\Audit\Application\Data;

final readonly class AuditEventPage
{
    /** @param list<AuditEventView> $events */
    public function __construct(public array $events, public ?string $nextCursor, public bool $hasMore, public int $perPage) {}
}
