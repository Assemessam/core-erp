<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('inviteMembers', $this->route('organization'));

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['present', 'array', 'list', 'max:100'],
            'roles.*' => ['required', 'string', 'ulid', 'distinct'],
            'organization_id' => ['prohibited'], 'inviter_user_id' => ['prohibited'],
        ];
    }
}
