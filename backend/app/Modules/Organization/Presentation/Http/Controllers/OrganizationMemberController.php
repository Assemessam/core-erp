<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\ActivateMembership;
use App\Modules\Organization\Application\Commands\RemoveMembership;
use App\Modules\Organization\Application\Commands\SuspendMembership;
use App\Modules\Organization\Application\Commands\SyncMembershipRoles;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use App\Modules\Organization\Presentation\Http\Requests\SyncMembershipRolesRequest;
use App\Modules\Organization\Presentation\Http\Resources\MembershipResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class OrganizationMemberController extends Controller
{
    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $organization);

        return MembershipResource::collection($organization->memberships()->with(['user:id,name,email', 'organization', 'roles.permissions'])->orderBy('id')->get());
    }

    public function roles(SyncMembershipRolesRequest $request, Organization $organization, OrganizationMembership $membership, SyncMembershipRoles $command): Response
    {
        $actor = $request->user();
        assert($actor instanceof User);
        $command->handle($actor->id, $organization->id, $membership->id, $request->validated('roles'));

        return response()->noContent();
    }

    public function suspend(Request $request, Organization $organization, OrganizationMembership $membership, SuspendMembership $command): Response
    {
        Gate::authorize('manageMembers', $organization);
        $actor = $request->user();
        assert($actor instanceof User);
        $command->handle($actor->id, $organization->id, $membership->id);

        return response()->noContent();
    }

    public function activate(Request $request, Organization $organization, OrganizationMembership $membership, ActivateMembership $command): Response
    {
        Gate::authorize('manageMembers', $organization);
        $actor = $request->user();
        assert($actor instanceof User);
        $command->handle($actor->id, $organization->id, $membership->id);

        return response()->noContent();
    }

    public function destroy(Request $request, Organization $organization, OrganizationMembership $membership, RemoveMembership $command): Response
    {
        Gate::authorize('manageMembers', $organization);
        $actor = $request->user();
        assert($actor instanceof User);
        $command->handle($actor->id, $organization->id, $membership->id);

        return response()->noContent();
    }
}
