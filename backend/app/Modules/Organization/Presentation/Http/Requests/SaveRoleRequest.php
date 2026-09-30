<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use App\Modules\Organization\Domain\Authorization\PermissionKey;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Role;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageRoles', $this->route('organization'));

        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $organization = $this->route('organization');
        assert($organization instanceof Organization);
        $role = $this->route('role');

        return [
            'name' => ['required', 'string', 'max:80', function (string $attribute, mixed $value, Closure $fail) use ($organization, $role): void {
                if (! is_string($value)) {
                    return;
                }
                $query = $organization->roles()->whereRaw('lower(btrim(name)) = lower(btrim(?))', [$value]);
                if ($role instanceof Role) {
                    $query->whereKeyNot($role->getKey());
                }
                if ($query->exists()) {
                    $fail('A role with this name already exists in this organization.');
                }
            }],
            'permissions' => ['present', 'array', 'list', 'max:'.count(PermissionKey::cases())],
            'permissions.*' => ['required', 'string', 'distinct', Rule::enum(PermissionKey::class)],
            'organization_id' => ['prohibited'],
            'id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
        ];
    }
}
