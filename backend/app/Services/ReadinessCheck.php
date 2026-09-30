<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessCheck
{
    public function passes(): bool
    {
        try {
            DB::select('SELECT 1');

            return in_array(Redis::connection()->ping(), [true, 'PONG', '+PONG'], true);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
