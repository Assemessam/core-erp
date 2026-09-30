<?php

namespace App\Actions\Fortify;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /** @return array<int, string|Password> */
    public static function rules(): array
    {
        return ['required', 'string', Password::min(12)->letters()->numbers(), 'confirmed'];
    }
}
