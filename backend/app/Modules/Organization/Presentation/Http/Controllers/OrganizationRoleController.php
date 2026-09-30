<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Application\Commands\SaveRole;
use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use App\Modules\Organization\Presentation\Http\Requests\SaveRoleRequest;
use App\Modules\Organization\Presentation\Http\Resources\PermissionResource;
use App\Modules\Organization\Presentation\Http\Resources\RoleResource;
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
