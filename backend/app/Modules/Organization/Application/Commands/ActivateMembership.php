<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Organization\Application\Auditing\OrganizationAuditEntries;
use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use Illuminate\Support\Facades\DB;

class ActivateMembership
{
    public function __construct(private readonly LockManagedMembership $lock, private readonly AuditRecorder $audit, private readonly OrganizationAuditEntries $entries) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            if ($membership->status === MembershipStatus::Active) {
                return;
            }
            $membership->update(['status' => MembershipStatus::Active]);
            $this->audit->record($this->entries->membershipActivated($membership->organization_id, $actorUserId, $membership->id, $membership->user_id));
        });
    }
}
