<?php

use App\Modules\Audit\Application\Data\AuditCursor;
use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use App\Modules\Audit\Application\Validation\AuditQueryValidator;

function auditTestCursor(array $values): string
{
    return rtrim(strtr(base64_encode(json_encode($values, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

it('rejects unsafe query input with fixed field errors', function (array $input, string $field) {
    try {
        (new AuditQueryValidator)->validate($input);
        $this->fail('Expected invalid query.');
    } catch (AuditQueryInvalid $failure) {
        expect(array_keys($failure->errors))->toBe([$field]);
        expect($failure->getMessage())->toBe('The audit query is invalid.');
        expect($failure->getPrevious())->toBeNull();
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
    'arbitrary action' => [['action' => 'secret.lookup'], 'action'],
    'array action' => [['action' => ['role.created']], 'action'],
    'null action' => [['action' => null], 'action'],
    'half type' => [['subject_type' => 'role'], 'subject'],
    'half id' => [['subject_id' => '1'], 'subject'],
    'unknown type' => [['subject_type' => 'user', 'subject_id' => '1'], 'subject_type'],
    'array type' => [['subject_type' => ['role'], 'subject_id' => '1'], 'subject_type'],
    'bad ULID' => [['subject_type' => 'role', 'subject_id' => '1'], 'subject_id'],
    'ULID overflow' => [['subject_type' => 'invitation', 'subject_id' => '81AAAAAAAAAAAAAAAAAAAAAAAA'], 'subject_id'],
    'array id' => [['subject_type' => 'membership', 'subject_id' => [1]], 'subject_id'],
    'integer id' => [['subject_type' => 'membership', 'subject_id' => 1], 'subject_id'],
    'zero id' => [['subject_type' => 'membership', 'subject_id' => '0'], 'subject_id'],
    'leading zero id' => [['subject_type' => 'membership', 'subject_id' => '01'], 'subject_id'],
    'overflow id' => [['subject_type' => 'membership', 'subject_id' => '9223372036854775808'], 'subject_id'],
    'SQL id' => [['subject_type' => 'membership', 'subject_id' => "1' OR 1=1"], 'subject_id'],
    'actor filter deferred' => [['actor' => '1'], 'query'],
    'sorting deferred' => [['sort' => 'id'], 'query'],
    'empty cursor' => [['cursor' => ''], 'cursor'],
    'null cursor' => [['cursor' => null], 'cursor'],
    'array cursor' => [['cursor' => []], 'cursor'],
    'cursor size bound' => [['cursor' => str_repeat('a', 257)], 'cursor'],
    'bad alphabet' => [['cursor' => '**'], 'cursor'],
    'non JSON' => [['cursor' => 'YWJj'], 'cursor'],
    'invalid base64 length' => [['cursor' => 'a'], 'cursor'],
    'scalar JSON' => [['cursor' => auditTestCursor([1])], 'cursor'],
    'missing field' => [['cursor' => auditTestCursor(['v' => 1, 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'unknown field' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-10-01T00:00:00.000001Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'direction' => 'next'])], 'cursor'],
    'unsupported version' => [['cursor' => auditTestCursor(['v' => 2, 'created_at' => '2026-10-01T00:00:00.000001Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'string version' => [['cursor' => auditTestCursor(['v' => '1', 'created_at' => '2026-10-01T00:00:00.000001Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'bad timestamp type' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => 1, 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'missing timestamp precision' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-10-01T00:00:00Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'invalid calendar' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-02-30T00:00:00.000001Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'PG invalid year zero' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '0000-10-01T00:00:00.000001Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'wrong timezone' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-10-01T00:00:00.000001+00:00', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'])], 'cursor'],
    'invalid ULID' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-10-01T00:00:00.000001Z', 'id' => "1' OR 1=1"])], 'cursor'],
    'bad ID type' => [['cursor' => auditTestCursor(['v' => 1, 'created_at' => '2026-10-01T00:00:00.000001Z', 'id' => 1])], 'cursor'],
]);

it('accepts bounded pages and supported scalar subjects', function () {
    $validator = new AuditQueryValidator;
    expect($validator->validate([])->perPage)->toBe(25);
    foreach ([1, 100, '1', '100'] as $size) {
        expect($validator->validate(['per_page' => $size])->perPage)->toBe((int) $size);
    }
    foreach (['organization', 'role', 'invitation'] as $type) {
        expect($validator->validate(['subject_type' => $type, 'subject_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa'])->subjectId)->toBe('01aaaaaaaaaaaaaaaaaaaaaaaa');
    }
    expect($validator->validate(['subject_type' => 'membership', 'subject_id' => '9223372036854775807'])->subjectId)->toBe('9223372036854775807');
    $cursor = new AuditCursor('2026-10-01T00:00:00.123456Z', '01AAAAAAAAAAAAAAAAAAAAAAAA');
    expect(AuditCursor::decode($cursor->encode()))->toEqual($cursor);
});
