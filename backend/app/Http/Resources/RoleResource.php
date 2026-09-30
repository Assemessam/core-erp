<?php

namespace App\Http\Resources;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /** @var Role */
    public $resource;

    /** @return array{id: string, name: string, permissions: list<string>} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'permissions' => array_values($this->resource->permissions->map(fn (Permission $permission): string => $permission->key)->sort()->all()),
        ];
    }
}
