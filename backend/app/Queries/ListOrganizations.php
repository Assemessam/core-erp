<?php

namespace App\Queries;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

class ListOrganizations
{
    /** @return Collection<int, Organization> */
    public function handle(int $userId): Collection
    {
        return Organization::query()->whereHas('memberships', fn ($query) => $query->where('user_id', $userId))
            ->orderBy('name')->get();
    }
}
