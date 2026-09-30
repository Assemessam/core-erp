<?php

namespace App\Modules\Organization\Application\Authorization;

use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;

final class OrganizationAccess
{
    public function view(int $actorUserId, string $organizationId): AccessDecision
    {
        return $this->memberOrganization($actorUserId, $organizationId) === null
            ? AccessDecision::hidden()
            : AccessDecision::allowed();
    }

    public function update(int $actorUserId, string $organizationId): AccessDecision
    {
        return $this->permission($actorUserId, $organizationId, PermissionKey::OrganizationsUpdate);
    }

    public function viewRoles(int $actorUserId, string $organizationId): AccessDecision
    {
        return $this->permission($actorUserId, $organizationId, PermissionKey::RolesView);
    }

    public function manageRoles(int $actorUserId, string $organizationId): AccessDecision
    {
        $organization = $this->memberOrganization($actorUserId, $organizationId);
        if ($organization === null) {
            return AccessDecision::hidden();
        }

        return $organization->owner_user_id === $actorUserId
            ? AccessDecision::allowed()
            : AccessDecision::forbidden('Only the organization owner may manage roles.');
    }

    private function permission(int $actorUserId, string $organizationId, PermissionKey $permission): AccessDecision
    {
        $organization = $this->memberOrganization($actorUserId, $organizationId);
        if ($organization === null) {
            return AccessDecision::hidden();
        }
        if ($organization->owner_user_id === $actorUserId) {
            return AccessDecision::allowed();
        }

        $hasPermission = OrganizationMembership::query()
            ->where('organization_id', $organizationId)->where('user_id', $actorUserId)
            ->whereHas('roles', fn ($query) => $query->where('roles.organization_id', $organizationId)
                ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission->value)))
            ->exists();

        return $hasPermission
            ? AccessDecision::allowed()
            : AccessDecision::forbidden('You do not have permission for this action.');
    }

    private function memberOrganization(int $actorUserId, string $organizationId): ?Organization
    {
        // Read persisted membership and ownership together; never reuse loaded relationships.
        // Membership remains required even for owners (also enforced by the deferred FK).
        return Organization::query()->whereKey($organizationId)
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actorUserId))
            ->first(['id', 'owner_user_id']);
    }
}
