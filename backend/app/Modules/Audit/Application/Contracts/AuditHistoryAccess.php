<?php

namespace App\Modules\Audit\Application\Contracts;

interface AuditHistoryAccess
{
    /** Denial preserves the implementing context's safe hidden/forbidden exception boundary. */
    public function assertCanView(int $actorUserId, string $organizationId): void;
}
