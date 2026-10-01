<?php

namespace App\Modules\Audit\Application\Vocabulary;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
}
