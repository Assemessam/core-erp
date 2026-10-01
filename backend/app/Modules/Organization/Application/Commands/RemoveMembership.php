<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Operations\LockManagedMembership;
use App\Modules\Organization\Domain\Memberships\MembershipRules;
use Illuminate\Support\Facades\DB;

class RemoveMembership
{
    public function __construct(private readonly LockManagedMembership $lock) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): void
    {
        DB::transaction(function () use ($actorUserId, $organizationId, $membershipId): void {
            $membership = $this->lock->handle($actorUserId, $organizationId, $membershipId);
            MembershipRules::requireNotOwner($membership->user_id, $membership->organization->owner_user_id);
            $membership->delete();
        });
    }
}
