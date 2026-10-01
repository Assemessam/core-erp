<?php

namespace App\Modules\Audit\Application\Vocabulary;

enum AuditSubjectType: string
{
    case Organization = 'organization';
    case Role = 'role';
    case Invitation = 'invitation';
    case Membership = 'membership';
}
