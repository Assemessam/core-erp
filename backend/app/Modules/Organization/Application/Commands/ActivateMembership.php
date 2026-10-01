<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use Illuminate\Support\Facades\DB;

class ActivateMembership
{
    public function __construct(private readonly LockManagedMembership $lock) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            $membership->update(['status' => MembershipStatus::Active]);
        });
    }
}
