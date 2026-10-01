<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\AcceptInvitation;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\RevokeInvitation;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use App\Modules\Organization\Presentation\Http\Requests\AcceptInvitationRequest;
use App\Modules\Organization\Presentation\Http\Requests\InviteMemberRequest;
use App\Modules\Organization\Presentation\Http\Resources\InvitationResource;
use App\Modules\Organization\Presentation\Http\Resources\OrganizationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class OrganizationInvitationController extends Controller
{
    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('inviteMembers', $organization);

        return InvitationResource::collection($organization->invitations()->where('state', InvitationState::Pending)->with('roles.permissions')->orderByDesc('created_at')->get());
    }

    public function store(InviteMemberRequest $request, Organization $organization, CreateInvitation $command): InvitationResource
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return new InvitationResource($command->handle($actor->id, $organization->id, $request->validated('email'), $request->validated('roles')));
    }

    public function destroy(Request $request, Organization $organization, OrganizationInvitation $invitation, RevokeInvitation $command): Response
    {
        Gate::authorize('inviteMembers', $organization);
        $actor = $request->user();
        assert($actor instanceof User);
        $command->handle($actor->id, $organization->id, $invitation->id);

        return response()->noContent();
    }

    public function accept(AcceptInvitationRequest $request, string $invitation, AcceptInvitation $command): OrganizationResource
    {
        $actor = $request->user();
        assert($actor instanceof User);

        return new OrganizationResource($command->handle($actor->id, $actor->email, $actor->hasVerifiedEmail(), $invitation, $request->validated('token')));
    }
}
