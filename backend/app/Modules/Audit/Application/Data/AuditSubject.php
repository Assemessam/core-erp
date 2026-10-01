<?php

namespace App\Modules\Audit\Application\Data;

use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;

final readonly class AuditSubject
{
    public string $id;

    public function __construct(public AuditSubjectType $type, string|int $id)
    {
        $this->id = (string) $id;
    }
}
