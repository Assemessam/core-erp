<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\Data\AuditEntry;

interface AuditRecorder
{
    public function record(#[\SensitiveParameter] AuditEntry $entry): void;
}
