<?php

use App\Modules\Audit\Application\Data\AuditActor;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Data\AuditSubject;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Audit\Application\Validation\AuditPayloadValidator;
use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditActorType;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;
use Tests\Support\AuditFixtures;

it('accepts every approved version-one action schema without framework state', function (AuditAction $action, ?array $before, ?array $after) {
    $type = $action->subjectType();
    $id = match ($type) {
        AuditSubjectType::Organization => AuditFixtures::ORGANIZATION_ID,
        AuditSubjectType::Role => AuditFixtures::ROLE_ID,
        AuditSubjectType::Invitation => AuditFixtures::INVITATION_ID,
        AuditSubjectType::Membership => 47,
    };
    $entry = AuditFixtures::entry(['action' => $action, 'subject' => new AuditSubject($type, $id), 'before' => $before, 'after' => $after]);
    (new AuditPayloadValidator)->validate($entry);
    expect($entry->subject->id)->toBe((string) $id);
})->with(AuditFixtures::examples());

it('supports explicit system attribution without treating a missing user as system', function () {
    (new AuditPayloadValidator)->validate(AuditFixtures::entry(['actor' => AuditActor::system()]));
    expect(AuditActor::system()->userId)->toBeNull();
    expect(AuditActor::user(12)->type)->toBe(AuditActorType::User);
});

it('preserves the lowercase ULIDs used by current Laravel models', function () {
    $id = strtolower(AuditFixtures::ORGANIZATION_ID);
    $entry = AuditFixtures::entry(['organizationId' => $id, 'subject' => new AuditSubject(AuditSubjectType::Organization, $id)]);
    (new AuditPayloadValidator)->validate($entry);
    expect($entry->organizationId)->toBe($id);
    expect($entry->subject->id)->toBe($id);
});

it('rejects invalid actor combinations before persistence', function (AuditActorType $type, ?int $id) {
    expect(fn () => new AuditActor($type, $id))->toThrow(AuditWriteFailed::class, 'Audit recording failed.');
})->with([
    [AuditActorType::User, null], [AuditActorType::User, 0], [AuditActorType::User, -1],
    [AuditActorType::System, 12], [AuditActorType::System, 0],
]);

it('does not accept arbitrary action strings or PHP class subject names', function () {
    expect(fn () => AuditAction::from('forged.action'))->toThrow(ValueError::class);
    expect(fn () => AuditSubjectType::from('App\\Modules\\Organization\\Infrastructure\\Eloquent\\Models\\Role'))->toThrow(ValueError::class);
    expect(fn () => new AuditEntry(AuditFixtures::ORGANIZATION_ID, AuditActor::user(12), 'organization.renamed', new AuditSubject(AuditSubjectType::Organization, AuditFixtures::ORGANIZATION_ID), ['name' => 'Old'], ['name' => 'New']))->toThrow(TypeError::class);
});

it('rejects unsafe or malformed entry structures', function (array $overrides, string $category) {
    try {
        (new AuditPayloadValidator)->validate(AuditFixtures::entry($overrides));
        $this->fail('Expected audit input rejection.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->category)->toBe($category);
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Audit recording failed.');
    }
})->with([
    'unsupported version' => [['payloadVersion' => 2], 'unsupported_version'],
    'invalid tenant' => [['organizationId' => 'not-a-tenant'], 'invalid_identifier'],
    'overflowing ULID' => [['organizationId' => '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'], 'invalid_identifier'],
    'foreign subject type' => [['subject' => new AuditSubject(AuditSubjectType::Role, AuditFixtures::ROLE_ID)], 'invalid_subject'],
    'different organization subject' => [['subject' => new AuditSubject(AuditSubjectType::Organization, AuditFixtures::ROLE_ID)], 'invalid_subject'],
    'malformed subject' => [['subject' => new AuditSubject(AuditSubjectType::Organization, 'malformed')], 'invalid_identifier'],
    'both absent' => [['before' => null, 'after' => null], 'invalid_snapshot'],
    'list snapshot' => [['before' => ['Old']], 'invalid_snapshot'],
    'missing field' => [['before' => ['other' => 'Old']], 'missing_field'],
    'unknown field' => [['after' => ['name' => 'New', 'email' => 'secret@example.test']], 'unknown_field'],
    'wrong name type' => [['after' => ['name' => 12]], 'invalid_field'],
    'float' => [['after' => ['name' => 1.2]], 'invalid_value'],
    'object' => [['after' => ['name' => new stdClass]], 'invalid_value'],
    'oversized name' => [['after' => ['name' => str_repeat('a', 256)]], 'invalid_field'],
    'oversized string' => [['after' => ['name' => str_repeat('a', 1025)]], 'string_too_large'],
    'empty name' => [['after' => ['name' => '   ']], 'invalid_field'],
    'invalid UTF8' => [['after' => ['name' => "\xB1"]], 'invalid_json'],
    'NUL' => [['after' => ['name' => "New\0"]], 'invalid_json'],
    'no change' => [['after' => ['name' => 'Old']], 'invalid_transition'],
    'too deep' => [['after' => ['extra' => ['nested' => ['again' => 'value']]]], 'payload_too_deep'],
]);

it('rejects normalized prohibited keys recursively without retaining their values', function (string $key) {
    $secret = 'credential-value-that-must-not-survive';
    try {
        (new AuditPayloadValidator)->validate(AuditFixtures::entry(['after' => ['name' => 'New', 'extra' => [$key => $secret]]]));
        $this->fail('Expected prohibited field rejection.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->category)->toBe('prohibited_key');
        expect((string) $failure)->not->toContain($secret);
        expect($failure->getMessage())->not->toContain($key);
        expect($failure->getPrevious())->toBeNull();
    }
})->with([
    'password', 'PasswordHash', 'password_reset_token', 'invitation-token', 'invitationTokenHash',
    'token_hash', 'CSRF', 'x-xsrf-token', 'Session_ID', 'authenticationCookie',
    'api_token', 'accessToken', 'refresh-token', 'Authorization', 'authorization_credentials',
    'mailCredentials', 'mail_password', 'encryption-key', 'privateKey', 'client_secret', 'API.Key',
]);

it('rejects impossible transitions and invalid relationship facts', function (AuditAction $action, ?array $before, ?array $after) {
    $subject = new AuditSubject($action->subjectType(), $action->subjectType() === AuditSubjectType::Membership ? 47 : AuditFixtures::INVITATION_ID);
    $entry = AuditFixtures::entry(compact('action', 'subject', 'before', 'after'));
    expect(fn () => (new AuditPayloadValidator)->validate($entry))->toThrow(AuditWriteFailed::class);
})->with([
    [AuditAction::RoleUpdated, ['name' => 'Old'], ['permissions' => []]],
    [AuditAction::RoleUpdated, [], []],
    [AuditAction::RoleCreated, null, ['name' => 'Role', 'permissions' => ['forged.permission']]],
    [AuditAction::RoleCreated, null, ['name' => 'Role', 'permissions' => ['roles.view', 'roles.view']]],
    [AuditAction::RoleCreated, null, ['name' => 'Role', 'permissions' => ['roles.view', 'members.view']]],
    [AuditAction::InvitationCreated, null, ['state' => 'expired', 'expires_at' => '2026-10-08T10:15:30.123456Z', 'role_ids' => []]],
    [AuditAction::InvitationCreated, null, ['state' => 'pending', 'expires_at' => '2026-02-30T10:15:30.123456Z', 'role_ids' => []]],
    [AuditAction::InvitationCreated, null, ['state' => 'pending', 'expires_at' => 'tomorrow', 'role_ids' => []]],
    [AuditAction::InvitationCreated, null, ['state' => 'pending', 'expires_at' => '2026-10-08T10:15:30.123456Z', 'role_ids' => ['foreign-invalid-id']]],
    [AuditAction::InvitationRevoked, ['state' => 'accepted'], ['state' => 'revoked']],
    [AuditAction::InvitationRevoked, ['state' => 'pending'], ['state' => 'revoked', 'reason' => 'replaced']],
    [AuditAction::InvitationRevoked, ['state' => 'pending'], ['state' => 'revoked', 'reason' => 'other', 'replacement_invitation_id' => AuditFixtures::ROLE_ID]],
    [AuditAction::InvitationRevoked, ['state' => 'pending'], ['state' => 'revoked', 'reason' => 'replaced', 'replacement_invitation_id' => AuditFixtures::INVITATION_ID]],
    [AuditAction::InvitationAccepted, ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => 0, 'user_id' => 12, 'role_ids' => []]],
    [AuditAction::InvitationAccepted, ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => 47, 'user_id' => 99, 'role_ids' => []]],
    [AuditAction::MembershipSuspended, ['user_id' => 29, 'status' => 'suspended'], ['user_id' => 29, 'status' => 'suspended']],
    [AuditAction::MembershipActivated, ['user_id' => 29, 'status' => 'suspended'], ['user_id' => 30, 'status' => 'active']],
    [AuditAction::MembershipRolesChanged, ['user_id' => '29', 'role_ids' => []], ['user_id' => 29, 'role_ids' => [AuditFixtures::ROLE_ID]]],
    [AuditAction::MembershipRemoved, ['user_id' => 29, 'status' => 'removed', 'role_ids' => []], null],
    [AuditAction::MembershipRemoved, ['user_id' => 29, 'status' => 'active', 'role_ids' => []], ['status' => 'removed']],
]);

it('rejects noncanonical or overflowing membership identifiers', function (string $id) {
    $entry = AuditFixtures::entry([
        'action' => AuditAction::MembershipRemoved, 'subject' => new AuditSubject(AuditSubjectType::Membership, $id),
        'before' => ['user_id' => 29, 'status' => 'active', 'role_ids' => []], 'after' => null,
    ]);
    expect(fn () => (new AuditPayloadValidator)->validate($entry))->toThrow(AuditWriteFailed::class);
})->with(['0', '-1', '01', '1e2', '9223372036854775808', '18446744073709551615']);

it('bounds combined valid collections instead of silently truncating history', function () {
    $roles = array_map(fn (int $id): string => str_pad((string) $id, 26, '0', STR_PAD_LEFT), range(1, 1000));
    $entry = AuditFixtures::entry([
        'action' => AuditAction::MembershipRolesChanged, 'subject' => new AuditSubject(AuditSubjectType::Membership, 47),
        'before' => ['user_id' => 29, 'role_ids' => $roles],
        'after' => ['user_id' => 29, 'role_ids' => [...array_slice($roles, 1), '01ARZ3NDEKTSV4RRFFQ69G5FAV']],
    ]);
    try {
        (new AuditPayloadValidator)->validate($entry);
        $this->fail('Expected combined payload limit.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->category)->toBe('payload_too_large');
        expect($entry->before['role_ids'])->toHaveCount(1000);
    }
});

it('rejects excessive collection sizes and recursive arrays safely', function () {
    $entry = AuditFixtures::entry(['after' => ['name' => 'New', 'extra' => array_fill(0, 1001, 'item')]]);
    expect(fn () => (new AuditPayloadValidator)->validate($entry))->toThrow(AuditWriteFailed::class);
    $recursive = [];
    $recursive['self'] = &$recursive;
    expect(fn () => (new AuditPayloadValidator)->validate(AuditFixtures::entry(['after' => $recursive])))->toThrow(AuditWriteFailed::class);
});

it('validates UTC timestamps independently of the host timezone and daylight saving gaps', function () {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/New_York');
    try {
        $entry = AuditFixtures::entry([
            'action' => AuditAction::InvitationCreated,
            'subject' => new AuditSubject(AuditSubjectType::Invitation, AuditFixtures::INVITATION_ID),
            'before' => null,
            'after' => ['state' => 'pending', 'expires_at' => '2026-03-08T02:30:00.000000Z', 'role_ids' => []],
        ]);
        (new AuditPayloadValidator)->validate($entry);
        expect($entry->after['expires_at'])->toBe('2026-03-08T02:30:00.000000Z');
    } finally {
        date_default_timezone_set($previous);
    }
});

it('never exposes arbitrary diagnostic input as a failure category', function () {
    $failure = new AuditWriteFailed('credential-value-that-must-not-survive');
    expect($failure->category)->toBe('recording_failed');
    expect($failure->getMessage())->toBe('Audit recording failed.');
    expect($failure->getPrevious())->toBeNull();
});
