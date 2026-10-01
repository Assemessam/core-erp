<?php

namespace App\Modules\Organization\Domain\Memberships;

use DomainException;

final class OwnerMembershipProtected extends DomainException
{
    public function __construct()
    {
        parent::__construct('The owner membership cannot be suspended or removed.');
    }
}
