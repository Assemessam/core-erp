<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->withHeaders(['Origin' => 'http://localhost:5174']);
});

it('sends a reset link while giving the same public response for an unknown account', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.test']);

    $known = $this->postJson('/forgot-password', ['email' => 'ADA@EXAMPLE.TEST'])->assertOk();
    $unknown = $this->postJson('/forgot-password', ['email' => 'absent@example.test'])->assertOk();

    expect($known->json())->toBe($unknown->json());
    Notification::assertSentTo($user, ResetPassword::class);
});

it('resets a password with a valid broker token but does not log the user in', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $token = Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token,
        'email' => 'ADA@EXAMPLE.TEST',
        'password' => 'New Correct Horse 123',
        'password_confirmation' => 'New Correct Horse 123',
    ])->assertOk();

    expect($user->fresh()->getAuthPassword())->not->toBe('password');
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'New Correct Horse 123'])->assertNoContent();
});

it('rejects an invalid reset token and preserves the password', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $oldHash = $user->getAuthPassword();

    $this->postJson('/reset-password', [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'New Correct Horse 123',
        'password_confirmation' => 'New Correct Horse 123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($user->fresh()->getAuthPassword())->toBe($oldHash);
});

it('applies the same password policy during reset', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->postJson('/reset-password', [
        'token' => $token, 'email' => $user->email,
        'password' => 'short', 'password_confirmation' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});
