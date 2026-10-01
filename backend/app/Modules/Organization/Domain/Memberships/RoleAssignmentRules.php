<?php

namespace App\Modules\Organization\Domain\Memberships;

final class RoleAssignmentRules
{
    public static function requireSameOrganization(string $membershipOrganizationId, string $roleOrganizationId): void
    {
        if ($membershipOrganizationId !== $roleOrganizationId) {
            throw new CrossOrganizationRoleAssignment;
        }
    }
}
