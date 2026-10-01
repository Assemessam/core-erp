<?php

namespace App\Modules\Organization\Domain\Memberships;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
