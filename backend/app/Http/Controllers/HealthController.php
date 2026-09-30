<?php

namespace App\Http\Controllers;

use App\Http\Resources\HealthResource;
use App\Services\ReadinessCheck;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): HealthResource
    {
        return new HealthResource(['status' => 'ok']);
    }

    public function ready(ReadinessCheck $check): JsonResponse
    {
        $ready = $check->passes();

        return (new HealthResource(['status' => $ready ? 'ok' : 'unavailable']))
            ->response()
            ->setStatusCode($ready ? 200 : 503);
    }
}
