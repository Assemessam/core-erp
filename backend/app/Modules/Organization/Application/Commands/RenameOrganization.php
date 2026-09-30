<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;

/** Transitional application operation: callers authorize and validate before invoking. */
class RenameOrganization
{
    public function handle(Organization $organization, string $name): Organization
    {
        $organization->update(['name' => $name]);

        return $organization;
    }
}
