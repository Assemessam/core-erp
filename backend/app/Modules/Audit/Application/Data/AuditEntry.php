<?php

namespace App\Modules\Audit\Application\Data;

use App\Modules\Audit\Application\Vocabulary\AuditAction;

/** Immutable producer input; snapshots are checked against exact action schemas before insertion. */
final readonly class AuditEntry
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     */
    public function __construct(
        public string $organizationId,
        public AuditActor $actor,
        public AuditAction $action,
        public AuditSubject $subject,
        #[\SensitiveParameter] public ?array $before,
        #[\SensitiveParameter] public ?array $after,
        public int $payloadVersion = 1,
    ) {}
}
