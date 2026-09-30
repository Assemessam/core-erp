<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('update', $this->route('organization'));

        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'id' => ['prohibited'],
            'owner_user_id' => ['prohibited'],
        ];
    }
}
