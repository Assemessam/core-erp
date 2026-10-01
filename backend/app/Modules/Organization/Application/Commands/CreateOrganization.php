<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function handle(int $ownerUserId, string $name): Organization
    {
        return DB::transaction(function () use ($ownerUserId, $name): Organization {
            $organization = Organization::query()->create([
                'name' => $name,
                'owner_user_id' => $ownerUserId,
            ]);
            $organization->memberships()->create(['user_id' => $ownerUserId]);

            return $organization;
        });
    }
}
