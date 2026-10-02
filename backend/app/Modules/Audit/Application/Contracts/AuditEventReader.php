<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\Data\AuditEventCriteria;
use App\Modules\Audit\Application\Data\AuditEventPage;

interface AuditEventReader
{
    public function read(string $organizationId, AuditEventCriteria $criteria): AuditEventPage;
}
