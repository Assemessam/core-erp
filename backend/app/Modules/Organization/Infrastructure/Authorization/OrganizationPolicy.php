<?php

namespace App\Modules\Organization\Infrastructure\Authorization;

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function viewMembers(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->viewMembers($user->id, $organization->id));
    }

    public function inviteMembers(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->inviteMembers($user->id, $organization->id));
    }

    public function manageMembers(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->manageMembers($user->id, $organization->id));
    }

    public function view(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->view($user->id, $organization->id));
    }

    public function update(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->update($user->id, $organization->id));
    }

    public function viewRoles(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->viewRoles($user->id, $organization->id));
    }

    public function manageRoles(User $user, Organization $organization): Response
    {
        return AccessResponse::fromDecision($this->access->manageRoles($user->id, $organization->id));
    }
}
