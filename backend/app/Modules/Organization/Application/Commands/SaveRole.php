<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Permission;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SaveRole
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AuditRecorder $audit,
        private readonly OrganizationAuditEntries $entries,
    ) {}

    public function handle(int $actorUserId, Organization $organization, ?Role $role, string $name, PermissionKey ...$permissions): Role
    {
        // Resource scope is independent of actor authority, just as scoped HTTP binding is.
        if ($role !== null && $role->organization_id !== $organization->getKey()) {
            AccessDecision::hidden()->requireAllowed();
        }
        $this->access->manageRoles($actorUserId, $organization->id)->requireAllowed();

        try {
            return DB::transaction(function () use ($actorUserId, $organization, $role, $name, $permissions): Role {
                $creating = $role === null;
                $beforeName = '';
                $beforePermissions = [];
                if ($creating) {
                    $role = $organization->roles()->create(['name' => trim($name)]);
                } else {
                    // Serialize edits so a role's name and permission set change together.
                    $role = $organization->roles()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();
                    $beforeName = $role->name;
                    $beforePermissions = array_values($role->permissions()->get()->map(fn (Permission $permission): string => $permission->key)->all());
                    $role->update(['name' => trim($name)]);
                }
                $role->permissions()->sync(array_map(fn (PermissionKey $permission): string => $permission->value, $permissions));
                $role->load('permissions');
                $afterPermissions = array_values($role->permissions->map(fn (Permission $permission): string => $permission->key)->all());
                $entry = $creating
                    ? $this->entries->roleCreated($role->organization_id, $actorUserId, $role->id, $role->name, $afterPermissions)
                    : $this->entries->roleUpdated($role->organization_id, $actorUserId, $role->id, $beforeName, $role->name, $beforePermissions, $afterPermissions);
                if ($entry !== null) {
                    $this->audit->record($entry);
                }

                return $role;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505' && str_contains($exception->getMessage(), 'roles_organization_name_unique')) {
                throw new RoleNameConflict($exception);
            }
            throw $exception;
        }
    }
}
