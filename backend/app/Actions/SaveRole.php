<?php

namespace App\Actions;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveRole
{
    /** @param list<string> $permissions */
    public function handle(Organization $organization, ?Role $role, string $name, array $permissions): Role
    {
        abort_if($role !== null && $role->organization_id !== $organization->getKey(), 404);

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
