<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Application\Operations\ResolveOrganizationRoles;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Support\Facades\DB;

class SyncMembershipRoles
{
    public function __construct(private readonly LockManagedMembership $lock, private readonly ResolveOrganizationRoles $roles, private readonly AssignMembershipRole $assign, private readonly AuditRecorder $audit, private readonly OrganizationAuditEntries $entries) {}

    /** @param list<string> $roleIds */
    public function handle(int $actorUserId, string $organizationId, int $membershipId, array $roleIds): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId, $roleIds): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            $roles = $this->roles->handle($organizationId, $roleIds);
            $beforeRoleIds = array_values($membership->roles()->get()->map(fn (Role $role): string => $role->id)->all());
            $membership->roles()->detach();
            foreach ($roles as $role) {
                $this->assign->handle($membership, $role);
            }
            $afterRoleIds = array_values($membership->roles()->get()->map(fn (Role $role): string => $role->id)->all());
            $entry = $this->entries->membershipRolesChanged(
                $membership->organization_id, $actorUserId, $membership->id, $membership->user_id, $beforeRoleIds, $afterRoleIds,
            );
            if ($entry !== null) {
                $this->audit->record($entry);
            }
        });
    }
}
