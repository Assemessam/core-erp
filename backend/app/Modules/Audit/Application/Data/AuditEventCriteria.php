<?php

namespace App\Modules\Audit\Application\Data;

use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;

final readonly class AuditEventCriteria
{
    public function __construct(
        public int $perPage,
        public ?AuditCursor $cursor,
        public ?AuditAction $action,
        public ?AuditSubjectType $subjectType,
        public ?string $subjectId,
    ) {}
}
