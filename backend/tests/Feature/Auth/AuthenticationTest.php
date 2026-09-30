<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withHeaders(['Origin' => 'http://localhost:5174']);
});

it('registers a normalized user and sends verification without exposing credentials', function () {
    Notification::fake();

    $response = $this->postJson('/register', [
        'name' => 'Ada Example',
        'email' => '  Ada@Example.test  ',
        'password' => 'Correct Horse 123',
        'password_confirmation' => 'Correct Horse 123',
        'is_admin' => true,
    ]);

    $response->assertCreated();
    expect($response->json())->toBe('');
    $user = User::query()->where('email', 'ada@example.test')->firstOrFail();
    expect($user->name)->toBe('Ada Example');
    expect($user->email_verified_at)->toBeNull();
    expect($user->getAuthPassword())->not->toBe('Correct Horse 123');
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('rejects invalid registration and a duplicate address regardless of case', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    $this->postJson('/register', [
        'name' => '', 'email' => 'bad-email', 'password' => 'short', 'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);

    $this->postJson('/register', [
        'name' => 'Another Ada', 'email' => 'ADA@EXAMPLE.TEST',
        'password' => 'Correct Horse 123', 'password_confirmation' => 'Correct Horse 123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(User::query()->where('email', 'ada@example.test')->count())->toBe(1);
});

it('authenticates with a session and returns only the public current-user fields', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);

    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $guestSessionId = session()->getId();
    $this->postJson('/login', ['email' => 'ADA@EXAMPLE.TEST', 'password' => 'password'])->assertNoContent();
    expect(session()->getId())->not->toBe($guestSessionId);

    $this->getJson('/api/v1/me')->assertOk()->assertExactJson(['data' => [
        'id' => $user->id,
        'name' => $user->name,
        'email' => 'ada@example.test',
        'email_verified' => true,
    ]]);
});

it('rejects an invalid password without disclosing which credential failed', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    $known = $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'wrong'])->assertUnprocessable();
    $unknown = $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])->assertUnprocessable();

    expect($known->json('errors.email'))->toBe($unknown->json('errors.email'));
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('rate limits repeated login attempts', function () {
    User::factory()->create(['email' => 'ada@example.test']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'wrong'])->assertUnprocessable();
    }

    $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'wrong'])->assertStatus(429);
});

it('invalidates the authenticated session on logout', function () {
    User::factory()->create(['email' => 'ada@example.test']);
    $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertNoContent();
    $this->getJson('/api/v1/me')->assertOk();

    $this->postJson('/logout')->assertNoContent();
    // Clear Laravel's per-process guard cache between simulated HTTP requests.
    Auth::forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
