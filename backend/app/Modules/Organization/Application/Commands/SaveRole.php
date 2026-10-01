<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SaveRole
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function handle(int $actorUserId, Organization $organization, ?Role $role, string $name, PermissionKey ...$permissions): Role
    {
        // Resource scope is independent of actor authority, just as scoped HTTP binding is.
        if ($role !== null && $role->organization_id !== $organization->getKey()) {
            AccessDecision::hidden()->requireAllowed();
        }
        $this->access->manageRoles($actorUserId, $organization->id)->requireAllowed();

        try {
            return DB::transaction(function () use ($organization, $role, $name, $permissions): Role {
                if ($role === null) {
                    $role = $organization->roles()->create(['name' => trim($name)]);
                } else {
                    // Serialize edits so a role's name and permission set change together.
                    $role = $organization->roles()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
                    $role->update(['name' => trim($name)]);
                }
                $role->permissions()->sync(array_map(fn (PermissionKey $permission): string => $permission->value, $permissions));

                return $role->load('permissions');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'roles_organization_name_unique')) {
                throw new RoleNameConflict($exception);
            }
            throw $exception;
        }
    }
}
