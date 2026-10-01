<?php

namespace App\Modules\Organization\Presentation\Http\Exceptions;

use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use Illuminate\Validation\ValidationException;

final class OrganizationFailureMapper
{
    public static function roleNameConflict(RoleNameConflict $exception): ValidationException
    {
        return ValidationException::withMessages(['name' => $exception->getMessage()]);
    }

    public static function crossOrganizationRoleAssignment(CrossOrganizationRoleAssignment $exception): ValidationException
    {
        return ValidationException::withMessages(['role' => $exception->getMessage()]);
    }
}
