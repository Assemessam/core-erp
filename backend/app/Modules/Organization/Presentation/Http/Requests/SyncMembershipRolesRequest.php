<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SyncMembershipRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('manageMembers', $this->route('organization'));

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['roles' => ['present', 'array', 'list', 'max:100'], 'roles.*' => ['required', 'string', 'ulid', 'distinct']];
    }
}
