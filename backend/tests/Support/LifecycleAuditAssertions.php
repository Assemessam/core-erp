<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use stdClass;

final class LifecycleAuditAssertions
{
    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>|null  $after
     */
    public static function fact(string $organizationId, int $actorId, string $action, string $subjectType, string|int $subjectId, ?array $before, ?array $after): stdClass
    {
        $row = DB::table('audit_events')->where('organization_id', $organizationId)->where('action', $action)->where('subject_id', (string) $subjectId)->sole();
        expect(array_keys((array) $row))->toEqualCanonicalizing(['id', 'organization_id', 'actor_type', 'actor_user_id', 'action', 'subject_type', 'subject_id', 'before', 'after', 'payload_version', 'created_at']);
        expect($row->organization_id)->toBe($organizationId);
        expect($row->actor_type)->toBe('user');
        expect($row->actor_user_id)->toBe($actorId);
        expect($row->action)->toBe($action);
        expect($row->subject_type)->toBe($subjectType);
        expect($row->subject_id)->toBe((string) $subjectId);
        expect($row->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
        expect($row->payload_version)->toBe(1);
        expect($row->created_at)->not->toBeNull();
        // Boolean assertions avoid dumping accidentally credential-bearing JSON on test failure.
        expect(self::sameSnapshot($row->before, $before))->toBeTrue();
        expect(self::sameSnapshot($row->after, $after))->toBeTrue();

        return $row;
    }

    /** @param array<string, mixed>|null $expected */
    private static function sameSnapshot(?string $json, ?array $expected): bool
    {
        if ($json === null || $expected === null) {
            return $json === null && $expected === null;
        }
        $actual = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    public static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
