<?php

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use App\Modules\Organization\Application\Notifications\OrganizationNotifications;

it('projects exactly the trusted acceptance identifiers and semantic users target', function (int $membershipId) {
    $draft = (new OrganizationNotifications)->invitationAccepted('01AAAAAAAAAAAAAAAAAAAAAAAA', 7, '01BBBBBBBBBBBBBBBBBBBBBBBB', $membershipId, 29);

    expect($draft)->toBeInstanceOf(NotificationDraft::class);
    expect(array_keys((array) $draft))->toBe(['organizationId', 'recipientUserId', 'type', 'payload', 'target', 'payloadVersion']);
    expect($draft->organizationId)->toBe('01AAAAAAAAAAAAAAAAAAAAAAAA');
    expect($draft->recipientUserId)->toBe(7);
    expect($draft->type)->toBe(NotificationType::OrganizationInvitationAccepted);
    expect($draft->payloadVersion)->toBe(1);
    expect($draft->payload)->toBe([
        'invitation_id' => '01BBBBBBBBBBBBBBBBBBBBBBBB', 'membership_id' => (string) $membershipId, 'accepted_user_id' => 29,
    ]);
    expect($draft->target->type)->toBe(NotificationTargetType::OrganizationUsers);
    expect($draft->target->id)->toBeNull();
    expect(array_keys((array) $draft->target))->toBe(['type', 'id']);
})->with([1, PHP_INT_MAX]);
