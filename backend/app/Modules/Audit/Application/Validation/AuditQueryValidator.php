<?php

namespace App\Modules\Audit\Application\Validation;

use App\Modules\Audit\Application\Data\AuditCursor;
use App\Modules\Audit\Application\Data\AuditEventCriteria;
use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;

final class AuditQueryValidator
{
    /** @param array<string, mixed> $input */
    public function validate(array $input): AuditEventCriteria
    {
        if (array_diff(array_keys($input), ['per_page', 'cursor', 'action', 'subject_type', 'subject_id']) !== []) {
            throw AuditQueryInvalid::field('query', 'Only approved audit query parameters are supported.');
        }
        $size = $input['per_page'] ?? 25;
        if ((array_key_exists('per_page', $input) && $input['per_page'] === null)
            || ! ((is_int($size) && $size >= 1 && $size <= 100)
                || (is_string($size) && preg_match('/^(?:[1-9][0-9]?|100)$/D', $size) === 1))) {
            throw AuditQueryInvalid::field('per_page', 'The per_page must be an integer between 1 and 100.');
        }
        $cursor = array_key_exists('cursor', $input) ? AuditCursor::decode($input['cursor']) : null;
        $action = null;
        if (array_key_exists('action', $input)) {
            $action = is_string($input['action']) ? AuditAction::tryFrom($input['action']) : null;
            if ($action === null) {
                throw AuditQueryInvalid::field('action', 'The action is invalid.');
            }
        }
        $type = null;
        $id = null;
        if (array_key_exists('subject_type', $input) !== array_key_exists('subject_id', $input)) {
            throw AuditQueryInvalid::field('subject', 'The subject_type and subject_id must be supplied together.');
        }
        if (array_key_exists('subject_type', $input)) {
            $type = is_string($input['subject_type']) ? AuditSubjectType::tryFrom($input['subject_type']) : null;
            if ($type === null) {
                throw AuditQueryInvalid::field('subject_type', 'The subject_type is invalid.');
            }
            $id = $input['subject_id'];
            $valid = is_string($id) && ($type === AuditSubjectType::Membership
                ? preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1 && (strlen($id) < 19 || strcmp($id, (string) PHP_INT_MAX) <= 0)
                : preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/iD', $id) === 1);
            if (! $valid) {
                throw AuditQueryInvalid::field('subject_id', 'The subject_id is invalid.');
            }
        }

        return new AuditEventCriteria((int) $size, $cursor, $action, $type, $id);
    }
}
