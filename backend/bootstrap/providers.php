<?php

use App\Modules\Audit\Infrastructure\Providers\AuditServiceProvider;
use App\Modules\Identity\Infrastructure\Providers\FortifyServiceProvider;
use App\Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use App\Modules\Organization\Infrastructure\Providers\OrganizationServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    OrganizationServiceProvider::class,
    AuditServiceProvider::class,
    NotificationServiceProvider::class,
];
