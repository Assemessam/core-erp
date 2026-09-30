<?php

use App\Modules\Organization\Infrastructure\Providers\OrganizationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    OrganizationServiceProvider::class,
];
