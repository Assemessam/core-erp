<?php

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationTarget;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Validation\NotificationPayloadValidator;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use Tests\Support\NotificationFixtures;

it('accepts only the initial stable type and semantic target vocabulary with a readonly producer draft', function () {
    expect(array_column(NotificationType::cases(), 'value'))->toBe(['organization.invitation_accepted']);
    expect(NotificationType::tryFrom('App\\Notifications\\InvitationAccepted'))->toBeNull();
    expect(array_column(NotificationTargetType::cases(), 'value'))->toBe(['organization.users']);
    expect(NotificationTargetType::tryFrom('javascript:alert(1)'))->toBeNull();
    $draft = new ReflectionClass(NotificationDraft::class);
    expect($draft->isReadOnly())->toBeTrue();
    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName(), $draft->getConstructor()->getParameters()))
        ->toBe(['organizationId', 'recipientUserId', 'type', 'payload', 'target', 'payloadVersion']);
});

it('accepts exact safe payloads preserving project identifier representations', function (array $payload, ?NotificationTarget $target) {
    (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload, 'target' => $target]));
    expect(true)->toBeTrue();
})->with([
    'users target' => [['invitation_id' => NotificationFixtures::INVITATION_ID, 'membership_id' => '47', 'accepted_user_id' => 29], new NotificationTarget(NotificationTargetType::OrganizationUsers)],
    'no target' => [['invitation_id' => strtolower(NotificationFixtures::INVITATION_ID), 'membership_id' => '9223372036854775807', 'accepted_user_id' => PHP_INT_MAX], null],
]);

it('rejects invalid producer scope and unsupported versions', function (array $overrides, string $category) {
    try {
        (new NotificationPayloadValidator)->validate(NotificationFixtures::draft($overrides));
        test()->fail('Expected invalid draft rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe($category);
        expect($failure->getPrevious())->toBeNull();
    }
})->with([
    'bad tenant' => [['organizationId' => 'not-an-id'], 'invalid_identifier'],
    'tenant overflow' => [['organizationId' => '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'], 'invalid_identifier'],
    'zero recipient' => [['recipientUserId' => 0], 'invalid_identifier'],
    'negative recipient' => [['recipientUserId' => -1], 'invalid_identifier'],
    'version zero' => [['payloadVersion' => 0], 'unsupported_version'],
    'future version' => [['payloadVersion' => 2], 'unsupported_version'],
    'negative version' => [['payloadVersion' => -1], 'unsupported_version'],
]);

it('rejects malformed identifiers and wrong payload scalar types', function (string $field, mixed $value) {
    $payload = NotificationFixtures::draft()->payload;
    $payload[$field] = $value;
    expect(fn () => (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload])))
        ->toThrow(NotificationWriteFailed::class);
})->with([
    'invitation missing' => ['invitation_id', null],
    'invitation malformed' => ['invitation_id', 'not-an-id'],
    'invitation overflow' => ['invitation_id', '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
    'invitation integer' => ['invitation_id', 29],
    'membership integer' => ['membership_id', 47],
    'membership zero' => ['membership_id', '0'],
    'membership negative' => ['membership_id', '-1'],
    'membership leading zero' => ['membership_id', '047'],
    'membership float text' => ['membership_id', '47.0'],
    'membership whitespace' => ['membership_id', ' 47'],
    'membership exponent' => ['membership_id', '4e2'],
    'membership bigint overflow' => ['membership_id', '9223372036854775808'],
    'membership HTML' => ['membership_id', '<script>alert(1)</script>'],
    'user string' => ['accepted_user_id', '29'],
    'user zero' => ['accepted_user_id', 0],
    'user negative' => ['accepted_user_id', -1],
    'user float' => ['accepted_user_id', 29.0],
    'user boolean' => ['accepted_user_id', true],
    'user object' => ['accepted_user_id', new stdClass],
    'user list' => ['accepted_user_id', [29]],
]);

it('rejects each missing field and unknown fields rather than sanitizing', function (string $field) {
    $payload = NotificationFixtures::draft()->payload;
    if ($field === 'unknown') {
        $payload['title'] = 'Producer controls no copy';
    } else {
        unset($payload[$field]);
    }
    try {
        (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload]));
        test()->fail('Expected exact-schema rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe($field === 'unknown' ? 'unknown_field' : 'missing_field');
    }
})->with(['invitation_id', 'membership_id', 'accepted_user_id', 'unknown']);

it('rejects normalized credential keys even within nested values', function (string $key) {
    $payload = NotificationFixtures::draft()->payload;
    $payload['extra'] = [$key => 'never-persist-this'];
    try {
        (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload]));
        test()->fail('Expected credential rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('prohibited_key');
    }
})->with([
    'Password', 'password_hash', 'reset.token', 'Invitation-Token', 'invitation_token_hash',
    'credential_url', 'invitationUrl', 'CSRF', 'x-s-r-f', 'Session_ID', 'Cookie', 'API_Key',
    'access_token', 'refresh.token', 'Authorization', 'mail_credentials', 'smtp_password',
    'encryption_key', 'PRIVATE.KEY',
]);

it('permits no PII request model or URL payload fields', function (string $key) {
    $payload = NotificationFixtures::draft()->payload;
    $payload[$key] = 'unapproved-value';
    expect(fn () => (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload])))
        ->toThrow(NotificationWriteFailed::class);
})->with(['invitation_email', 'accepted_user_email', 'recipient_email', 'invitation_url', 'token_hash', 'request', 'model', 'body']);

it('rejects oversized deep recursive or invalid JSON data safely', function (array $payload, string $category) {
    try {
        (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload]));
        test()->fail('Expected bounded payload rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe($category);
        expect($failure->getMessage())->toBe('Notification publication failed.');
        expect($failure->getPrevious())->toBeNull();
    }
})->with([
    '8 KiB overflow' => [array_replace(NotificationFixtures::draft()->payload, ['membership_id' => str_repeat('1', 8192)]), 'payload_too_large'],
    'depth overflow' => [['extra' => ['level' => ['value' => 'deep']]], 'payload_too_deep'],
    'invalid UTF8 value' => [array_replace(NotificationFixtures::draft()->payload, ['invitation_id' => "\xFF"]), 'invalid_json'],
    'NUL value' => [array_replace(NotificationFixtures::draft()->payload, ['invitation_id' => "id\0"]), 'invalid_json'],
    'invalid UTF8 key' => [["\xFF" => 'value'], 'invalid_field'],
    'NUL key' => [["field\0" => 'value'], 'invalid_field'],
    'oversized key' => [[str_repeat('x', 81) => 'value'], 'invalid_field'],
]);

it('bounds recursive arrays without exposing their values', function () {
    $payload = [];
    $payload['loop'] = &$payload;
    expect(fn () => (new NotificationPayloadValidator)->validate(NotificationFixtures::draft(['payload' => $payload])))
        ->toThrow(NotificationWriteFailed::class);
});

it('rejects target IDs including internal route details and unsafe URLs', function (string $id) {
    try {
        (new NotificationPayloadValidator)->validate(NotificationFixtures::draft([
            'target' => new NotificationTarget(NotificationTargetType::OrganizationUsers, $id),
        ]));
        test()->fail('Expected target rejection.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('invalid_target');
    }
})->with(['47', '/app/organizations/a/users', 'organization-users', '#token=secret', '?next=evil', 'https://evil.test', 'javascript:alert(1)', '']);

it('uses only fixed exception messages and allowlisted diagnostic categories', function () {
    $failure = new NotificationWriteFailed('untrusted-input-category');
    expect($failure->category)->toBe('publication_failed');
    expect($failure->getMessage())->toBe('Notification publication failed.');
    expect($failure->getPrevious())->toBeNull();
});
