<?php

namespace App\Modules\Organization\Infrastructure\Providers;

use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Organization\Infrastructure\Audit\OrganizationAuditHistoryAccess;
use App\Modules\Organization\Infrastructure\Authorization\OrganizationPolicy;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Notification\OrganizationNotificationAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditHistoryAccess::class, OrganizationAuditHistoryAccess::class);
        $this->app->bind(NotificationOrganizationAccess::class, OrganizationNotificationAccess::class);
    }

    public function boot(): void
    {
        Gate::policy(Organization::class, OrganizationPolicy::class);
    }
}
