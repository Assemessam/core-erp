<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Domain\Memberships\MembershipRules;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Illuminate\Support\Facades\DB;

class RemoveMembership
{
    public function __construct(private readonly LockManagedMembership $lock, private readonly AuditRecorder $audit, private readonly OrganizationAuditEntries $entries) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            MembershipRules::requireNotOwner($membership->user_id, $membership->organization->owner_user_id);
            $roleIds = array_values($membership->roles()->get()->map(fn (Role $role): string => $role->id)->all());
            $entry = $this->entries->membershipRemoved(
                $membership->organization_id, $actorUserId, $membership->id, $membership->user_id, $membership->status->value, $roleIds,
            );
            $membership->delete();
            $this->audit->record($entry);
        });
    }
}
