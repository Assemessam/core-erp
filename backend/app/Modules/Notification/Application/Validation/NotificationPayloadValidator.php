<?php

namespace App\Modules\Notification\Application\Validation;

use App\Modules\Notification\Application\Data\NotificationDraft;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Notification\Application\Vocabulary\NotificationTargetType;
use App\Modules\Notification\Application\Vocabulary\NotificationType;
use JsonException;

final class NotificationPayloadValidator
{
    public const int MAX_PAYLOAD_BYTES = 8192;

    public const int MAX_DEPTH = 3;

    public function validateRecipient(#[\SensitiveParameter] NotificationDraft $notification): void
    {
        $this->require($this->ulid($notification->organizationId) && $notification->recipientUserId > 0, 'invalid_identifier');
    }

    public function validateMembershipContext(#[\SensitiveParameter] NotificationDraft $notification, NotificationMembershipContext $context): void
    {
        $this->require($context->organizationId === $notification->organizationId
            && $context->userId === $notification->recipientUserId && $context->membershipId > 0, 'invalid_membership_context');
    }

    public function validate(#[\SensitiveParameter] NotificationDraft $notification): void
    {
        $this->validateRecipient($notification);
        $this->require($notification->payloadVersion === 1, 'unsupported_version');
        $this->inspect($notification->payload, 1);
        try {
            // Conservative whitespace/Unicode encoding also bounds PostgreSQL's JSONB text form.
            $bytes = strlen(json_encode($notification->payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (JsonException) {
            throw new NotificationWriteFailed('invalid_json');
        }
        $this->require($bytes <= self::MAX_PAYLOAD_BYTES, 'payload_too_large');

        match ($notification->type) {
            NotificationType::OrganizationInvitationAccepted => $this->invitationAccepted($notification),
        };
    }

    private function invitationAccepted(#[\SensitiveParameter] NotificationDraft $notification): void
    {
        $fields = ['invitation_id', 'membership_id', 'accepted_user_id'];
        $payload = $notification->payload;
        foreach ($fields as $field) {
            $this->require(array_key_exists($field, $payload), 'missing_field');
        }
        $this->require(array_diff(array_keys($payload), $fields) === [], 'unknown_field');
        $this->require(is_string($payload['invitation_id']) && $this->ulid($payload['invitation_id']), 'invalid_identifier');
        $this->require(is_string($payload['membership_id']) && $this->positiveBigint($payload['membership_id']), 'invalid_identifier');
        $this->require(is_int($payload['accepted_user_id']) && $payload['accepted_user_id'] > 0, 'invalid_identifier');
        $target = $notification->target;
        $this->require($target === null || match ($target->type) {
            NotificationTargetType::OrganizationUsers => $target->id === null,
        }, 'invalid_target');
    }

    private function inspect(#[\SensitiveParameter] mixed $value, int $depth): void
    {
        $this->require($depth <= self::MAX_DEPTH, 'payload_too_deep');
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key)) {
                    $this->require(strlen($key) <= 80 && mb_check_encoding($key, 'UTF-8') && ! str_contains($key, "\0"), 'invalid_field');
                    $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $key) ?? '');
                    $this->require(preg_match('/password|passwd|pwd|token|csrf|xsrf|session|cookie|authorization|credential|secret|apikey|accesskey|privatekey|encryptionkey|url|^auth$/', $normalized) !== 1, 'prohibited_key');
                }
                $this->inspect($child, $depth + 1);
            }

            return;
        }
        $this->require(is_string($value) || is_int($value) || $value === null, 'invalid_value');
        if (is_string($value)) {
            $this->require(mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0"), 'invalid_json');
        }
    }

    private function ulid(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $value) === 1;
    }

    private function positiveBigint(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $value) === 1
            && (strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0);
    }

    private function require(bool $condition, string $category): void
    {
        if (! $condition) {
            throw new NotificationWriteFailed($category);
        }
    }
}
