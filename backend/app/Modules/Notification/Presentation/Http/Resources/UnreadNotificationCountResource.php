<?php

namespace App\Modules\Notification\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnreadNotificationCountResource extends JsonResource
{
    /** @var int */
    public $resource;

    /** @return array{unread_count: int} */
    public function toArray(Request $request): array
    {
        return ['unread_count' => $this->resource];
    }
}
