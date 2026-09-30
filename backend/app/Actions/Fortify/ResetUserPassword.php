<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, mixed> $input */
    public function reset(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'password' => PasswordRules::rules(),
        ])->validate();

        $user->forceFill(['password' => $validated['password']])->save();
    }
}
