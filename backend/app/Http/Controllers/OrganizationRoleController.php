<?php

namespace App\Http\Controllers;

use App\Actions\SaveRole;
use App\Enums\PermissionKey;
use App\Http\Requests\SaveRoleRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationRoleController extends Controller
{
    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewRoles', $organization);

        return RoleResource::collection($organization->roles()->with('permissions')->orderBy('name')->get())
            ->additional(['meta' => ['can_manage' => Gate::allows('manageRoles', $organization)]]);
    }

    public function permissions(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewRoles', $organization);

        return PermissionResource::collection(PermissionKey::cases());
    }

    public function store(SaveRoleRequest $request, Organization $organization, SaveRole $save): RoleResource
    {
        return new RoleResource($save->handle($organization, null, $request->validated('name'), $request->validated('permissions')));
    }

    public function update(SaveRoleRequest $request, Organization $organization, Role $role, SaveRole $save): RoleResource
    {
        return new RoleResource($save->handle($organization, $role, $request->validated('name'), $request->validated('permissions')));
    }
}
