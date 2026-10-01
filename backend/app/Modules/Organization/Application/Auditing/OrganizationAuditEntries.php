<?php

namespace App\Modules\Organization\Application\Auditing;

use App\Modules\Audit\Application\Data\AuditActor;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Data\AuditSubject;
use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;

/** Organization owns these facts; Audit owns their validation and persistence. */
final class OrganizationAuditEntries
{
    public function organizationCreated(string $organizationId, int $ownerUserId, string $name, int $ownerMembershipId): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($ownerUserId), AuditAction::OrganizationCreated,
            new AuditSubject(AuditSubjectType::Organization, $organizationId),
            null, ['name' => $name, 'owner_user_id' => $ownerUserId, 'owner_membership_id' => $ownerMembershipId],
        );
    }

    public function organizationRenamed(string $organizationId, int $actorUserId, string $beforeName, string $afterName): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::OrganizationRenamed,
            new AuditSubject(AuditSubjectType::Organization, $organizationId),
            ['name' => $beforeName], ['name' => $afterName],
        );
    }

    /** @param list<string> $permissions */
    public function roleCreated(string $organizationId, int $actorUserId, string $roleId, string $name, array $permissions): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::RoleCreated,
            new AuditSubject(AuditSubjectType::Role, $roleId),
            null, ['name' => $name, 'permissions' => $this->permissionKeys($permissions)],
        );
    }

    /**
     * @param  list<string>  $beforePermissions
     * @param  list<string>  $afterPermissions
     */
    public function roleUpdated(string $organizationId, int $actorUserId, string $roleId, string $beforeName, string $afterName, array $beforePermissions, array $afterPermissions): ?AuditEntry
    {
        $before = $after = [];
        if ($beforeName !== $afterName) {
            $before['name'] = $beforeName;
            $after['name'] = $afterName;
        }
        $beforePermissions = $this->permissionKeys($beforePermissions);
        $afterPermissions = $this->permissionKeys($afterPermissions);
        if ($beforePermissions !== $afterPermissions) {
            $before['permissions'] = $beforePermissions;
            $after['permissions'] = $afterPermissions;
        }
        if ($before === []) {
            return null;
        }

        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::RoleUpdated,
            new AuditSubject(AuditSubjectType::Role, $roleId), $before, $after,
        );
    }

    /** @param list<string> $roleIds */
    public function invitationCreated(string $organizationId, int $actorUserId, string $invitationId, string $expiresAt, array $roleIds): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::InvitationCreated,
            new AuditSubject(AuditSubjectType::Invitation, $invitationId),
            null, ['state' => 'pending', 'expires_at' => $expiresAt, 'role_ids' => $this->roleIds($roleIds)],
        );
    }

    public function invitationRevoked(string $organizationId, int $actorUserId, string $invitationId, ?string $replacementInvitationId = null): AuditEntry
    {
        $after = ['state' => 'revoked'];
        if ($replacementInvitationId !== null) {
            $after['replacement_invitation_id'] = $replacementInvitationId;
            $after['reason'] = 'replaced';
        }

        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::InvitationRevoked,
            new AuditSubject(AuditSubjectType::Invitation, $invitationId), ['state' => 'pending'], $after,
        );
    }

    /** @param list<string> $roleIds */
    public function invitationAccepted(string $organizationId, int $actorUserId, string $invitationId, int $membershipId, int $userId, array $roleIds): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::InvitationAccepted,
            new AuditSubject(AuditSubjectType::Invitation, $invitationId), ['state' => 'pending'],
            ['state' => 'accepted', 'membership_id' => $membershipId, 'user_id' => $userId, 'role_ids' => $this->roleIds($roleIds)],
        );
    }

    /** @param list<string> $beforeRoleIds
     * @param  list<string>  $afterRoleIds
     */
    public function membershipRolesChanged(string $organizationId, int $actorUserId, int $membershipId, int $userId, array $beforeRoleIds, array $afterRoleIds): ?AuditEntry
    {
        $beforeRoleIds = $this->roleIds($beforeRoleIds);
        $afterRoleIds = $this->roleIds($afterRoleIds);
        if ($beforeRoleIds === $afterRoleIds) {
            return null;
        }

        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::MembershipRolesChanged,
            new AuditSubject(AuditSubjectType::Membership, $membershipId),
            ['user_id' => $userId, 'role_ids' => $beforeRoleIds], ['user_id' => $userId, 'role_ids' => $afterRoleIds],
        );
    }

    public function membershipSuspended(string $organizationId, int $actorUserId, int $membershipId, int $userId): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::MembershipSuspended,
            new AuditSubject(AuditSubjectType::Membership, $membershipId),
            ['user_id' => $userId, 'status' => 'active'], ['user_id' => $userId, 'status' => 'suspended'],
        );
    }

    public function membershipActivated(string $organizationId, int $actorUserId, int $membershipId, int $userId): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::MembershipActivated,
            new AuditSubject(AuditSubjectType::Membership, $membershipId),
            ['user_id' => $userId, 'status' => 'suspended'], ['user_id' => $userId, 'status' => 'active'],
        );
    }

    /** @param list<string> $roleIds */
    public function membershipRemoved(string $organizationId, int $actorUserId, int $membershipId, int $userId, string $status, array $roleIds): AuditEntry
    {
        return new AuditEntry(
            $organizationId, AuditActor::user($actorUserId), AuditAction::MembershipRemoved,
            new AuditSubject(AuditSubjectType::Membership, $membershipId),
            ['user_id' => $userId, 'status' => $status, 'role_ids' => $this->roleIds($roleIds)], null,
        );
    }

    /** @param list<string> $roleIds
     * @return list<string>
     */
    private function roleIds(array $roleIds): array
    {
        $roleIds = array_values(array_unique($roleIds));
        sort($roleIds, SORT_STRING);

        return $roleIds;
    }

    /** @param list<string> $permissions
     * @return list<string>
     */
    private function permissionKeys(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        sort($permissions, SORT_STRING);

        return $permissions;
    }
}
