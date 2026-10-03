<?php

use App\Modules\Notification\Application\Data\NotificationCursor;
use App\Modules\Notification\Application\Exceptions\NotificationQueryInvalid;
use App\Modules\Notification\Application\Validation\NotificationQueryValidator;

function notificationQueryCursor(array $overrides = []): string
{
    return rtrim(strtr(base64_encode(json_encode(array_replace([
        'v' => 1, 'created_at' => '2026-10-01T00:00:00.123456Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    ], $overrides), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), '+/', '-_'), '=');
}

it('accepts bounded pages and exact versioned microsecond cursors', function () {
    $validator = new NotificationQueryValidator;
    expect($validator->validate([])->perPage)->toBe(25);
    expect($validator->validate([])->cursor)->toBeNull();
    foreach ([1, 25, 100, '1', '25', '100'] as $size) {
        expect($validator->validate(['per_page' => $size])->perPage)->toBe((int) $size);
    }
    $cursor = new NotificationCursor('2026-10-01T00:00:00.123456Z', '01AAAAAAAAAAAAAAAAAAAAAAAA');
    expect(NotificationCursor::decode($cursor->encode()))->toEqual($cursor);
    expect(strlen($cursor->encode()))->toBeLessThanOrEqual(256);
    expect($validator->validate(['cursor' => $cursor->encode()])->cursor)->toEqual($cursor);
    expect(NotificationCursor::decode(notificationQueryCursor(['id' => strtolower($cursor->id)]))->id)->toBe(strtolower($cursor->id));
});

it('rejects unsupported or malformed query input with fixed safe field errors', function (array $input, string $field) {
    try {
        (new NotificationQueryValidator)->validate($input);
        test()->fail('Expected invalid query.');
    } catch (NotificationQueryInvalid $failure) {
        expect(array_keys($failure->errors))->toBe([$field]);
        expect($failure->getMessage())->toBe('The notification query is invalid.');
        expect($failure->getPrevious())->toBeNull();
        expect(str_contains(json_encode($failure->errors, JSON_THROW_ON_ERROR), 'secret-input'))->toBeFalse();
    }
})->with([
    'zero' => [['per_page' => 0], 'per_page'],
    'negative' => [['per_page' => -1], 'per_page'],
    'too large' => [['per_page' => 101], 'per_page'],
    'huge' => [['per_page' => str_repeat('9', 1000)], 'per_page'],
    'float' => [['per_page' => 1.5], 'per_page'],
    'boolean' => [['per_page' => true], 'per_page'],
    'array size' => [['per_page' => [25]], 'per_page'],
    'null size' => [['per_page' => null], 'per_page'],
    'empty size' => [['per_page' => ''], 'per_page'],
    'noncanonical size' => [['per_page' => '01'], 'per_page'],
    'decimal size' => [['per_page' => '1.0'], 'per_page'],
    'SQL size' => [['per_page' => "1' OR 1=1"], 'per_page'],
    'space size' => [['per_page' => ' 25'], 'per_page'],
    'cursor empty' => [['cursor' => ''], 'cursor'],
    'cursor null' => [['cursor' => null], 'cursor'],
    'cursor array' => [['cursor' => []], 'cursor'],
    'cursor integer' => [['cursor' => 42], 'cursor'],
    'cursor oversized' => [['cursor' => str_repeat('a', 257)], 'cursor'],
    'cursor alphabet' => [['cursor' => '**secret-input**'], 'cursor'],
    'cursor padded' => [['cursor' => notificationQueryCursor().'='], 'cursor'],
    'cursor malformed base64' => [['cursor' => 'a'], 'cursor'],
    'cursor noncanonical bits' => [['cursor' => 'Zh'], 'cursor'],
    'cursor non JSON' => [['cursor' => 'YWJj'], 'cursor'],
    'cursor malformed JSON' => [['cursor' => rtrim(strtr(base64_encode('{'), '+/', '-_'), '=')], 'cursor'],
    'cursor JSON scalar' => [['cursor' => 'MQ'], 'cursor'],
    'cursor JSON list' => [['cursor' => 'W10'], 'cursor'],
    'cursor missing field' => [['cursor' => rtrim(strtr(base64_encode('{"v":1,"id":"01AAAAAAAAAAAAAAAAAAAAAAAA"}'), '+/', '-_'), '=')], 'cursor'],
    'cursor unknown field' => [['cursor' => notificationQueryCursor(['direction' => 'next'])], 'cursor'],
    'cursor future version' => [['cursor' => notificationQueryCursor(['v' => 2])], 'cursor'],
    'cursor string version' => [['cursor' => notificationQueryCursor(['v' => '1'])], 'cursor'],
    'cursor float version' => [['cursor' => notificationQueryCursor(['v' => 1.0])], 'cursor'],
    'cursor timestamp integer' => [['cursor' => notificationQueryCursor(['created_at' => 42])], 'cursor'],
    'cursor timestamp array' => [['cursor' => notificationQueryCursor(['created_at' => ['date' => 'secret-input']])], 'cursor'],
    'cursor missing precision' => [['cursor' => notificationQueryCursor(['created_at' => '2026-10-01T00:00:00Z'])], 'cursor'],
    'cursor short precision' => [['cursor' => notificationQueryCursor(['created_at' => '2026-10-01T00:00:00.123Z'])], 'cursor'],
    'cursor timezone' => [['cursor' => notificationQueryCursor(['created_at' => '2026-10-01T00:00:00.123456+00:00'])], 'cursor'],
    'cursor invalid calendar' => [['cursor' => notificationQueryCursor(['created_at' => '2026-02-30T00:00:00.123456Z'])], 'cursor'],
    'cursor invalid leap day' => [['cursor' => notificationQueryCursor(['created_at' => '2025-02-29T00:00:00.123456Z'])], 'cursor'],
    'cursor year zero' => [['cursor' => notificationQueryCursor(['created_at' => '0000-10-01T00:00:00.123456Z'])], 'cursor'],
    'cursor leap second' => [['cursor' => notificationQueryCursor(['created_at' => '2026-10-01T00:00:60.123456Z'])], 'cursor'],
    'cursor bad ID' => [['cursor' => notificationQueryCursor(['id' => 'secret-input'])], 'cursor'],
    'cursor overflowing ID' => [['cursor' => notificationQueryCursor(['id' => '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'])], 'cursor'],
    'cursor integer ID' => [['cursor' => notificationQueryCursor(['id' => 42])], 'cursor'],
    'cursor duplicate key' => [['cursor' => rtrim(strtr(base64_encode('{"v":1,"v":1,"created_at":"2026-10-01T00:00:00.123456Z","id":"01AAAAAAAAAAAAAAAAAAAAAAAA"}'), '+/', '-_'), '=')], 'cursor'],
    'recipient input' => [['recipient_user_id' => 42], 'query'],
    'era input' => [['recipient_membership_id' => 42], 'query'],
    'type filter' => [['type' => 'organization.invitation_accepted'], 'query'],
    'unread filter' => [['unread' => true], 'query'],
    'sort' => [['sort' => 'id'], 'query'],
    'date range' => [['from' => '2026-10-01'], 'query'],
    'search' => [['search' => 'secret-input'], 'query'],
    'actor filter' => [['actor' => 42], 'query'],
    'target filter' => [['target' => 'organization.users'], 'query'],
]);
