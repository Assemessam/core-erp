<?php

namespace App\Actions;

use App\Models\Organization;

/** Transitional application operation: callers authorize and validate before invoking. */
class RenameOrganization
{
    public function handle(Organization $organization, string $name): Organization
    {
        $organization->update(['name' => $name]);

        return $organization;
    }
}
