<?php

namespace App\Modules\Organization\Application\Operations;

use App\Modules\Organization\Domain\Memberships\RoleAssignmentRules;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;

/** Internal application operation; callers load records and authorize membership administration. */
class AssignMembershipRole
{
    public function handle(OrganizationMembership $membership, Role $role): void
    {
        RoleAssignmentRules::requireSameOrganization($membership->organization_id, $role->organization_id);

        $membership->roles()->syncWithoutDetaching([$role->getKey() => ['organization_id' => $membership->organization_id]]);
    }
}
