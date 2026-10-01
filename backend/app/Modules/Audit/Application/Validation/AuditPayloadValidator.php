<?php

namespace App\Modules\Audit\Application\Validation;

use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditActorType;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final class AuditPayloadValidator
{
    public const int MAX_PAYLOAD_BYTES = 65536;

    public const int MAX_DEPTH = 3;

    public const int MAX_COLLECTION_ITEMS = 1000;

    // Frozen initial audit vocabulary, not an import of Organization Domain internals.
    private const array PERMISSIONS = ['members.invite', 'members.view', 'organizations.update', 'roles.view'];

    public function validate(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $this->require($this->ulid($entry->organizationId), 'invalid_identifier');
        $this->require($entry->payloadVersion === 1, 'unsupported_version');
        $this->require($entry->action->subjectType() === $entry->subject->type, 'invalid_subject');
        $this->require($entry->subject->type === AuditSubjectType::Membership
            ? $this->positiveId($entry->subject->id) : $this->ulid($entry->subject->id), 'invalid_identifier');
        if ($entry->subject->type === AuditSubjectType::Organization) {
            $this->require($entry->subject->id === $entry->organizationId, 'invalid_subject');
        }
        $this->require($entry->actor->type === AuditActorType::User
            ? $entry->actor->userId !== null && $entry->actor->userId > 0
            : $entry->actor->userId === null, 'invalid_actor');
        $this->require($entry->before !== null || $entry->after !== null, 'invalid_snapshot');

        $bytes = 0;
        foreach ([$entry->before, $entry->after] as $snapshot) {
            if ($snapshot !== null) {
                $this->inspect($snapshot, 1);
                try {
                    // Conservative whitespace/Unicode encoding also bounds PostgreSQL's JSONB text form.
                    $bytes += strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
                } catch (JsonException) {
                    throw new AuditWriteFailed('invalid_json');
                }
            }
        }
        $this->require($bytes <= self::MAX_PAYLOAD_BYTES, 'payload_too_large');

        match ($entry->action) {
            AuditAction::OrganizationCreated => $this->creation($entry, ['name', 'owner_user_id', 'owner_membership_id']),
            AuditAction::OrganizationRenamed => $this->change($entry, ['name']),
            AuditAction::RoleCreated => $this->creation($entry, ['name', 'permissions']),
            AuditAction::RoleUpdated => $this->roleChange($entry),
            AuditAction::InvitationCreated => $this->creation($entry, ['state', 'expires_at', 'role_ids']),
            AuditAction::InvitationRevoked => $this->revocation($entry),
            AuditAction::InvitationAccepted => $this->acceptance($entry),
            AuditAction::MembershipRolesChanged => $this->change($entry, ['user_id', 'role_ids'], ['user_id']),
            AuditAction::MembershipSuspended, AuditAction::MembershipActivated => $this->change($entry, ['user_id', 'status'], ['user_id']),
            AuditAction::MembershipRemoved => $this->removal($entry),
        };
    }

    private function inspect(#[\SensitiveParameter] mixed $value, int $depth): void
    {
        $this->require($depth <= self::MAX_DEPTH, 'payload_too_deep');
        if (is_array($value)) {
            $this->require(count($value) <= self::MAX_COLLECTION_ITEMS, 'collection_too_large');
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');
                    $this->require(! preg_match('/password|passwd|pwd|token|csrf|xsrf|session|cookie|authorization|credential|secret|apikey|accesskey|privatekey|encryptionkey|^auth$/', $normalized), 'prohibited_key');
                    $this->require(strlen($key) <= 80, 'invalid_field');
                }
                $this->inspect($child, $depth + 1);
            }

            return;
        }
        $this->require(is_string($value) || is_int($value) || $value === null, 'invalid_value');
        if (is_string($value)) {
            $this->require(mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0"), 'invalid_json');
            $this->require(mb_strlen($value, 'UTF-8') <= 1024, 'string_too_large');
        }
    }

    /** @param list<string> $fields */
    private function creation(#[\SensitiveParameter] AuditEntry $entry, array $fields): void
    {
        $this->require($entry->before === null, 'invalid_snapshot');
        $after = $this->snapshot($entry->after, $fields, [], $entry->action, false);
        if ($entry->action === AuditAction::OrganizationCreated) {
            $this->require($entry->actor->type === AuditActorType::User && $after['owner_user_id'] === $entry->actor->userId, 'invalid_actor');
        }
    }

    /** @param list<string> $fields
     * @param  list<string>  $unchanged
     */
    private function change(#[\SensitiveParameter] AuditEntry $entry, array $fields, array $unchanged = []): void
    {
        $before = $this->snapshot($entry->before, $fields, [], $entry->action, true);
        $after = $this->snapshot($entry->after, $fields, [], $entry->action, false);
        foreach ($fields as $field) {
            $this->require(in_array($field, $unchanged, true)
                ? $before[$field] === $after[$field]
                : $before[$field] !== $after[$field], 'invalid_transition');
        }
    }

    private function roleChange(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $before = $this->snapshot($entry->before, [], ['name', 'permissions'], $entry->action, true);
        $after = $this->snapshot($entry->after, [], ['name', 'permissions'], $entry->action, false);
        $beforeKeys = array_keys($before);
        $afterKeys = array_keys($after);
        sort($beforeKeys);
        sort($afterKeys);
        $this->require($beforeKeys !== [] && $beforeKeys === $afterKeys, 'invalid_snapshot');
        foreach ($beforeKeys as $field) {
            $this->require($before[$field] !== $after[$field], 'invalid_transition');
        }
    }

    private function revocation(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $this->snapshot($entry->before, ['state'], [], $entry->action, true);
        $this->snapshot($entry->after, ['state'], ['replacement_invitation_id', 'reason'], $entry->action, false);
        $hasReplacement = array_key_exists('replacement_invitation_id', $entry->after ?? []);
        $this->require($hasReplacement === array_key_exists('reason', $entry->after ?? []), 'invalid_snapshot');
        if ($hasReplacement) {
            $this->require($entry->after['replacement_invitation_id'] !== $entry->subject->id, 'invalid_transition');
        }
    }

    private function acceptance(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $this->snapshot($entry->before, ['state'], [], $entry->action, true);
        $after = $this->snapshot($entry->after, ['state', 'membership_id', 'user_id', 'role_ids'], [], $entry->action, false);
        $this->require($entry->actor->type === AuditActorType::User && $after['user_id'] === $entry->actor->userId, 'invalid_actor');
    }

    private function removal(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $this->snapshot($entry->before, ['user_id', 'status', 'role_ids'], [], $entry->action, true);
        $this->require($entry->after === null, 'invalid_snapshot');
    }

    /** @param array<array-key, mixed>|null $snapshot
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array<string, mixed>
     */
    private function snapshot(#[\SensitiveParameter] ?array $snapshot, array $required, array $optional, AuditAction $action, bool $before): array
    {
        $this->require($snapshot !== null && ! array_is_list($snapshot), 'invalid_snapshot');
        foreach ($required as $field) {
            $this->require(array_key_exists($field, $snapshot), 'missing_field');
        }
        foreach ($snapshot as $field => $value) {
            $this->require(is_string($field) && in_array($field, [...$required, ...$optional], true), 'unknown_field');
            $valid = match ($field) {
                'name' => is_string($value) && trim($value) !== '' && mb_strlen($value, 'UTF-8') <= ($action->subjectType() === AuditSubjectType::Role ? 80 : 255),
                'user_id', 'owner_user_id', 'owner_membership_id', 'membership_id' => is_int($value) && $value > 0,
                'permissions' => $this->stringSet($value, fn (string $key): bool => in_array($key, self::PERMISSIONS, true)),
                'role_ids' => $this->stringSet($value, $this->ulid(...)),
                'expires_at' => $this->timestamp($value),
                'state' => $value === ($before ? 'pending' : match ($action) {
                    AuditAction::InvitationCreated => 'pending',
                    AuditAction::InvitationRevoked => 'revoked',
                    AuditAction::InvitationAccepted => 'accepted',
                    default => null,
                }),
                'status' => match ($action) {
                    AuditAction::MembershipRemoved => in_array($value, ['active', 'suspended'], true),
                    AuditAction::MembershipSuspended => $value === ($before ? 'active' : 'suspended'),
                    AuditAction::MembershipActivated => $value === ($before ? 'suspended' : 'active'),
                    default => false,
                },
                'replacement_invitation_id' => $this->ulid($value),
                'reason' => $value === 'replaced',
                default => false,
            };
            $this->require($valid, 'invalid_field');
        }

        return $snapshot;
    }

    private function ulid(#[\SensitiveParameter] mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $value) === 1;
    }

    private function positiveId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private function timestamp(#[\SensitiveParameter] mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $value) !== 1) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s.u\Z') === $value;
    }

    private function stringSet(#[\SensitiveParameter] mixed $value, callable $valid): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_COLLECTION_ITEMS) {
            return false;
        }
        foreach ($value as $item) {
            if (! is_string($item) || ! $valid($item)) {
                return false;
            }
        }
        $sorted = array_values(array_unique($value));
        sort($sorted, SORT_STRING);

        return $value === $sorted;
    }

    /** @phpstan-assert true $condition */
    private function require(bool $condition, string $category): void
    {
        if (! $condition) {
            throw new AuditWriteFailed($category);
        }
    }
}
