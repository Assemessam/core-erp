<?php

namespace App\Modules\Organization\Application\Operations;

use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Validation\ValidationException;

/** Internal domain operation; callers must authorize membership administration. */
class AssignMembershipRole
{
    public function handle(OrganizationMembership $membership, Role $role): void
    {
        if ($membership->organization_id !== $role->organization_id) {
            throw ValidationException::withMessages(['role' => 'The role must belong to the membership organization.']);
        }

        $membership->roles()->syncWithoutDetaching([$role->getKey() => ['organization_id' => $membership->organization_id]]);
    }
}
