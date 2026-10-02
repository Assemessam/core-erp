<?php

namespace App\Modules\Audit\Application\Data;

final readonly class AuditEventView
{
    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(
        public string $id,
        public string $action,
        public string $actorType,
        public ?int $actorId,
        public string $subjectType,
        public string $subjectId,
        public ?array $before,
        public ?array $after,
        public int $payloadVersion,
        public string $createdAt,
    ) {}
}
