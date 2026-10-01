<?php

namespace App\Modules\Organization\Application\Operations;

use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class ResolveOrganizationRoles
{
    /** @param list<string> $roleIds
     * @return Collection<int, Role>
     */
    public function handle(string $organizationId, array $roleIds): Collection
    {
        $roles = Role::query()->where('organization_id', $organizationId)->whereKey($roleIds)->orderBy('id')->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new CrossOrganizationRoleAssignment;
        }

        return $roles;
    }
}
