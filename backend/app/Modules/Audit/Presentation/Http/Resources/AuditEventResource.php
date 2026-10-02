<?php

namespace App\Modules\Audit\Presentation\Http\Resources;

use App\Modules\Audit\Application\Data\AuditEventView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditEventResource extends JsonResource
{
    /** @var AuditEventView */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'action' => $event->action,
            'actor' => ['type' => $event->actorType, 'id' => $event->actorId],
            'subject' => ['type' => $event->subjectType, 'id' => $event->subjectId],
            'changes' => [
                'before' => $event->before === null ? null : (object) $event->before,
                'after' => $event->after === null ? null : (object) $event->after,
            ],
            'payload_version' => $event->payloadVersion,
            'created_at' => $event->createdAt,
        ];
    }
}
