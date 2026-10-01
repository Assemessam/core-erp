<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->withHeaders(['Origin' => 'http://localhost:5174']);
});

it('verifies an authenticated user with a signed URL', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url)->assertNoContent();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
    $this->getJson('/api/v1/me')->assertJsonPath('data.email_verified', true);
});

it('rejects a tampered or mismatched verification URL', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);
    $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->getJson($url.'&extra=changed')->assertForbidden();
    $other = User::factory()->unverified()->create();
    $otherUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $other->getKey(),
        'hash' => sha1($other->getEmailForVerification()),
    ]);
    $this->getJson($otherUrl)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends verification notifications and throttles excessive resends', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $this->postJson('/email/verification-notification')->assertAccepted();
    }

    $this->postJson('/email/verification-notification')->assertStatus(429);
    Notification::assertSentToTimes($user, VerifyEmail::class, 6);
});
