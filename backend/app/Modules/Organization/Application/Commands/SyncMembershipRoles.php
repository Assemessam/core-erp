<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Application\Operations\ResolveOrganizationRoles;
use Illuminate\Support\Facades\DB;

class SyncMembershipRoles
{
    public function __construct(private readonly LockManagedMembership $lock, private readonly ResolveOrganizationRoles $roles, private readonly AssignMembershipRole $assign) {}

    /** @param list<string> $roleIds */
    public function handle(int $actorUserId, string $organizationId, int $membershipId, array $roleIds): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId, $roleIds): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            $roles = $this->roles->handle($organizationId, $roleIds);
            $membership->roles()->detach();
            foreach ($roles as $role) {
                $this->assign->handle($membership, $role);
            }
        });
    }
}
