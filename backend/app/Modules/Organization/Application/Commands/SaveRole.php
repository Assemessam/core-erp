<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveRole
{
    public function __construct(private readonly OrganizationAccess $access) {}

    /** @param list<string> $permissions */
    public function handle(int $actorUserId, Organization $organization, ?Role $role, string $name, array $permissions): Role
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
                $role->permissions()->sync($permissions);

                return $role->load('permissions');
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'roles_organization_name_unique')) {
                throw ValidationException::withMessages(['name' => 'A role with this name already exists in this organization.']);
            }
            throw $exception;
        }
    }
}
