<?php

namespace App\Policies;

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
        if (! $organization->memberships()->where('user_id', $user->getKey())->exists()) {
            return Response::denyAsNotFound();
        }

        return $organization->owner_user_id === $user->getKey()
            ? Response::allow()
            : Response::deny('Only the organization owner may update it.');
    }
}
