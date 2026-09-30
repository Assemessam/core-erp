<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthResource extends JsonResource
{
    /** @var array{status: string} */
    public $resource;

    /** @return array{status: string} */
    public function toArray(Request $request): array
    {
        return ['status' => $this->resource['status']];
    }
}
