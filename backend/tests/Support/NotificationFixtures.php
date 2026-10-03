<?php

namespace Tests\Support;

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationTarget;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use Illuminate\Support\Facades\DB;

final class NotificationFixtures
{
    public const string ORGANIZATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public const string INVITATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    /** Reject only publication into a newly created test-owned organization; outer Feature rollback removes the constraint. */
    public static function rejectInsertsForOrganization(string $organizationId): void
    {
        $organization = DB::connection()->escape($organizationId);
        DB::statement('ALTER TABLE organization_notifications ADD CONSTRAINT notifications_test_failure CHECK (organization_id <> '.$organization.')');
    }

    /** @param array<string, mixed> $overrides */
    public static function draft(array $overrides = []): NotificationDraft
    {
        return new NotificationDraft(...array_replace([
            'organizationId' => self::ORGANIZATION_ID,
            'recipientUserId' => 12,
            'type' => NotificationType::OrganizationInvitationAccepted,
            'payload' => ['invitation_id' => self::INVITATION_ID, 'membership_id' => '47', 'accepted_user_id' => 29],
            'target' => new NotificationTarget(NotificationTargetType::OrganizationUsers),
            'payloadVersion' => 1,
        ], $overrides));
    }
}
