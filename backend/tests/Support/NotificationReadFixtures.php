<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit INSERT-only read fixtures; no production bypass or cleanup mechanism. */
final class NotificationReadFixtures
{
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
