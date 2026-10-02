<?php

namespace App\Modules\Organization\Infrastructure\Audit;

use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;

final class OrganizationAuditHistoryAccess implements AuditHistoryAccess
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function assertCanView(int $actorUserId, string $organizationId): void
    {
        $this->access->viewAuditHistory($actorUserId, $organizationId)->requireAllowed();
    }
}
