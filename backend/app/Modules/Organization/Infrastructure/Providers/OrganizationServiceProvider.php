<?php

namespace App\Modules\Organization\Infrastructure\Providers;

use App\Modules\Organization\Infrastructure\Authorization\OrganizationPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrganizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
    }
}
