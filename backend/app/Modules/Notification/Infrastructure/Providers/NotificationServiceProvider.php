<?php

namespace App\Modules\Notification\Infrastructure\Providers;

use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Contracts\NotificationReadStore;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationPublisher;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationReader;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationReadStore;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationPublisher::class, DatabaseNotificationPublisher::class);
        $this->app->bind(NotificationReader::class, DatabaseNotificationReader::class);
        $this->app->bind(NotificationReadStore::class, DatabaseNotificationReadStore::class);
    }
}
