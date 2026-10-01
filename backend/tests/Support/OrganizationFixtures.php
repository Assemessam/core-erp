<?php

namespace Tests\Support;

use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

/** Raw fixtures for storage/cascade tests, not substitutes for audited command tests. */
final class OrganizationFixtures
{
    public static function unaudited(int $ownerUserId, string $name): Organization
    {
        return DB::transaction(function () use ($ownerUserId, $name): Organization {
            $organization = Organization::query()->create(['name' => $name, 'owner_user_id' => $ownerUserId]);
            $organization->memberships()->create(['user_id' => $ownerUserId]);

            return $organization;
        });
    }
}
