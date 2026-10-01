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
