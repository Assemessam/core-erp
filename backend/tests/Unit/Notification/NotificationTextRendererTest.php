<?php

use App\Modules\Notification\Application\Content\NotificationTextRenderer;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Validation\NotificationPayloadValidator;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use Tests\Support\NotificationFixtures;

it('renders deterministic plain snapshots from validated scalar identifiers', function () {
    $draft = NotificationFixtures::draft();
    (new NotificationPayloadValidator)->validate($draft);
    $text = (new NotificationTextRenderer)->render($draft->type, $draft->payloadVersion, $draft->payload);
    expect($text->title)->toBe('Invitation accepted');
    expect($text->body)->toBe('User #29 accepted an invitation and joined the organization.');
    expect((new NotificationTextRenderer)->render($draft->type, 1, $draft->payload))->toEqual($text);
});

it('does not interpret HTML or template values as a user identifier', function (mixed $value) {
    expect(fn () => (new NotificationTextRenderer)->render(NotificationType::OrganizationInvitationAccepted, 1, ['accepted_user_id' => $value]))
        ->toThrow(NotificationWriteFailed::class);
})->with(['<script>alert(1)</script>', '{{ config("app.key") }}', '**29**', true, 29.0, 0, null]);

it('fails safely on unsupported content versions', function () {
    expect(fn () => (new NotificationTextRenderer)->render(NotificationType::OrganizationInvitationAccepted, 2, ['accepted_user_id' => 29]))
        ->toThrow(NotificationWriteFailed::class);
});
