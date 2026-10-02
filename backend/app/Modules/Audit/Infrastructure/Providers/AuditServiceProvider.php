<?php

namespace App\Modules\Audit\Infrastructure\Providers;

use App\Modules\Audit\Application\Contracts\AuditEventReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Infrastructure\Persistence\DatabaseAuditEventReader;
use App\Modules\Audit\Infrastructure\Persistence\DatabaseAuditRecorder;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, DatabaseAuditRecorder::class);
        $this->app->bind(AuditEventReader::class, DatabaseAuditEventReader::class);
    }
}
