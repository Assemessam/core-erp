<?php

namespace App\Modules\Notification\Application\Validation;

use App\Modules\Notification\Application\Data\NotificationCriteria;
use App\Modules\Notification\Application\Data\NotificationCursor;
use App\Modules\Notification\Application\Exceptions\NotificationQueryInvalid;

final class NotificationQueryValidator
{
    /** @param array<string, mixed> $input */
    public function validate(#[\SensitiveParameter] array $input): NotificationCriteria
    {
        if (array_diff(array_keys($input), ['per_page', 'cursor']) !== []) {
            throw NotificationQueryInvalid::field('query', 'Only cursor and per_page query parameters are supported.');
        }
        $size = $input['per_page'] ?? 25;
        if ((array_key_exists('per_page', $input) && $input['per_page'] === null)
            || ! ((is_int($size) && $size >= 1 && $size <= 100)
                || (is_string($size) && preg_match('/^(?:[1-9][0-9]?|100)$/D', $size) === 1))) {
            throw NotificationQueryInvalid::field('per_page', 'The per_page must be an integer between 1 and 100.');
        }

        return new NotificationCriteria((int) $size, array_key_exists('cursor', $input) ? NotificationCursor::decode($input['cursor']) : null);
    }
}
