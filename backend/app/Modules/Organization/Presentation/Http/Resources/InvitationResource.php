<?php

namespace App\Modules\Organization\Presentation\Http\Resources;

use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitationResource extends JsonResource
{
    /** @var OrganizationInvitation */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id, 'email' => $this->resource->email,
            'state' => $this->resource->effectiveState()->value,
            'expires_at' => $this->resource->expires_at->toIso8601String(),
            'roles' => RoleResource::collection($this->resource->roles),
        ];
    }
}
