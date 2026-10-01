<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Domain\Memberships\MembershipRules;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use Illuminate\Support\Facades\DB;

class SuspendMembership
{
    public function __construct(private readonly LockManagedMembership $lock, private readonly AuditRecorder $audit, private readonly OrganizationAuditEntries $entries) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            MembershipRules::requireNotOwner($membership->user_id, $membership->organization->owner_user_id);
            if ($membership->status === MembershipStatus::Suspended) {
                return;
            }
            $membership->update(['status' => MembershipStatus::Suspended]);
            $this->audit->record($this->entries->membershipSuspended($membership->organization_id, $actorUserId, $membership->id, $membership->user_id));
        });
    }
}
