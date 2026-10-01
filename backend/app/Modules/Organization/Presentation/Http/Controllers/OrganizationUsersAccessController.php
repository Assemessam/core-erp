<?php

namespace App\Modules\Organization\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrganizationUsersAccessController extends Controller
{
    public function __invoke(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return response()->json(['data' => [
            'can_view' => Gate::allows('viewMembers', $organization),
            'can_invite' => Gate::allows('inviteMembers', $organization),
            'can_manage' => Gate::allows('manageMembers', $organization),
        ]]);
    }
}
