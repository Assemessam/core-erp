<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;

/** Callers validate input and supply the trusted actor identity explicitly. */
class RenameOrganization
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function handle(int $actorUserId, Organization $organization, string $name): Organization
    {
        $this->access->update($actorUserId, $organization->id)->requireAllowed();
        $organization->update(['name' => $name]);

        return $organization;
    }
}
