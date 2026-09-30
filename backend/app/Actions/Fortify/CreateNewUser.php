<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        $input['email'] = isset($input['email']) && is_string($input['email'])
            ? Str::lower(trim($input['email']))
            : $input['email'] ?? null;

        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => PasswordRules::rules(),
        ])->validate();

        return User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
    }
}
