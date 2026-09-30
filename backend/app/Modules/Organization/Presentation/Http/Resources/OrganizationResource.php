<?php

namespace App\Modules\Organization\Presentation\Http\Resources;

use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /** @var Organization */
    public $resource;

    /** @return array{id: string, name: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
        ];
    }
}
