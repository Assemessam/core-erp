<?php

namespace App\Modules\Notification\Presentation\Http\Resources;

use App\Modules\Notification\Application\Data\NotificationView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /** @var NotificationView */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'payload_version' => $notification->payloadVersion,
            'title' => $notification->title,
            'body' => $notification->body,
            'target' => $notification->targetType === null ? null : ['type' => $notification->targetType, 'id' => $notification->targetId],
            'read_at' => $notification->readAt,
            'created_at' => $notification->createdAt,
        ];
    }
}
