<?php

namespace App\Modules\Organization\Presentation\Http\Resources;

use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MembershipResource extends JsonResource
{
    /** @var OrganizationMembership */
    public $resource;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $member = $this->resource;

        return [
            'id' => $member->id, 'user_id' => $member->user_id,
            'name' => $member->user->name, 'email' => $member->user->email,
            'status' => $member->status->value,
            'is_owner' => $member->user_id === $member->organization->owner_user_id,
            'roles' => RoleResource::collection($member->roles),
        ];
    }
}
