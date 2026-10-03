<?php

namespace App\Modules\Notification\Infrastructure\Providers;

use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationPublisher;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationPublisher::class, DatabaseNotificationPublisher::class);
    }
}
