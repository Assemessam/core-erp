<?php

namespace App\Modules\Organization\Presentation\Http\Resources;

use App\Modules\Organization\Domain\Authorization\PermissionKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    /** @var PermissionKey */
    public $resource;

    /** @return array{key: string, label: string} */
    public function toArray(Request $request): array
    {
        return ['key' => $this->resource->value, 'label' => $this->resource->label()];
    }
}
