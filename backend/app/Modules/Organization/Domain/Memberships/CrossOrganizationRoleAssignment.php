<?php

namespace App\Modules\Organization\Domain\Memberships;

use DomainException;

final class CrossOrganizationRoleAssignment extends DomainException
{
    public function __construct()
    {
        parent::__construct('The role must belong to the membership organization.');
    }
}
