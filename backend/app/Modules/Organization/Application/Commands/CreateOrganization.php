<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateOrganization
{
    public function handle(User $owner, string $name): Organization
    {
        return DB::transaction(function () use ($owner, $name): Organization {
            $organization = Organization::query()->create([
                'name' => $name,
                'owner_user_id' => $owner->getKey(),
            ]);
            $organization->memberships()->create(['user_id' => $owner->getKey()]);

            return $organization;
        });
    }
}
