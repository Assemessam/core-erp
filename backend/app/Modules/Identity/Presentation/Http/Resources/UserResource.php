<?php

namespace App\Modules\Identity\Presentation\Http\Resources;

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @var User */
    public $resource;

    /** @return array{id: int, name: string, email: string, email_verified: bool} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'email_verified' => $this->resource->hasVerifiedEmail(),
        ];
    }
}
