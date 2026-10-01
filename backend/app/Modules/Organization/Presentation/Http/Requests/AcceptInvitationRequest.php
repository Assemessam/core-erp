<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['token' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']];
    }
}
