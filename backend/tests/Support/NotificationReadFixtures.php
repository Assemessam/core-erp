<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit read fixtures and scoped SQL failure injection; no production bypass or cleanup mechanism. */
final class NotificationReadFixtures
{
    /** Reject read updates only for this test-owned row; outer Feature rollback removes the constraint. */
    public static function rejectReadForNotification(string $notificationId): void
    {
        $notification = DB::connection()->escape($notificationId);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE organization_notifications ADD CONSTRAINT notification_read_test_failure CHECK (id <> '.$notification.' OR read_at IS NULL)');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /** @param array<string, mixed> $overrides */
    public static function insert(string $organizationId, int $userId, int $membershipId, array $overrides = []): string
    {
        $values = array_replace([
            'id' => (string) Str::ulid(), 'organization_id' => $organizationId,
            'recipient_user_id' => $userId, 'recipient_membership_id' => $membershipId,
            'type' => 'organization.invitation_accepted', 'payload_version' => 1,
            'payload' => json_encode(NotificationFixtures::draft()->payload, JSON_THROW_ON_ERROR),
            'title' => 'Invitation accepted', 'body' => 'User #29 accepted an invitation and joined the organization.',
            'target_type' => 'organization.users', 'target_id' => null, 'read_at' => null,
            'created_at' => '2026-10-01T00:00:00.123456Z',
        ], $overrides);
        DB::table('organization_notifications')->insert($values);

        return $values['id'];
    }
}
