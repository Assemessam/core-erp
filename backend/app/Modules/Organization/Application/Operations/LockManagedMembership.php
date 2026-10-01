<?php

namespace App\Modules\Organization\Application\Operations;

use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;

/** Internal operation, called inside the caller's transaction. */
class LockManagedMembership
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function handle(int $actorUserId, string $organizationId, int $membershipId): OrganizationMembership
    {
        $this->access->manageMembers($actorUserId, $organizationId)->requireAllowed();
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
        $this->access->manageMembers($actorUserId, $organizationId)->requireAllowed();
        $membership = OrganizationMembership::query()->where('organization_id', $organizationId)->whereKey($membershipId)->lockForUpdate()->first();
        if ($membership === null) {
            AccessDecision::hidden()->requireAllowed();
            throw new \LogicException('Unreachable denial.');
        }

        return $membership;
    }
}
