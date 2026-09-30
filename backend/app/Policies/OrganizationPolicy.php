<?php

namespace App\Policies;

use App\Enums\PermissionKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): Response
    {
        return $organization->memberships()->where('user_id', $user->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Organization $organization): Response
    {
        return $this->permission($user, $organization, PermissionKey::OrganizationsUpdate);
    }

    public function viewRoles(User $user, Organization $organization): Response
    {
        return $this->permission($user, $organization, PermissionKey::RolesView);
    }

    public function manageRoles(User $user, Organization $organization): Response
    {
        if ($this->view($user, $organization)->denied()) {
            return Response::denyAsNotFound();
        }

        return $organization->owner_user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('Only the organization owner may manage roles.');
    }

    private function permission(User $user, Organization $organization, PermissionKey $permission): Response
    {
        $membership = $organization->memberships()->where('user_id', $user->getKey())->first();
        if ($membership === null) {
            return Response::denyAsNotFound();
        }

        return $membership->hasPermission($organization, $permission)
            ? Response::allow()
            : Response::deny('You do not have permission for this action.');
    }
}
