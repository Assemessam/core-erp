<?php

namespace App\Modules\Notification\Application\Exceptions;

use RuntimeException;

final class NotificationWriteFailed extends RuntimeException
{
    private const array CATEGORIES = [
        'invalid_identifier', 'unsupported_version', 'invalid_json', 'payload_too_large',
        'payload_too_deep', 'prohibited_key', 'invalid_field', 'invalid_value',
        'missing_field', 'unknown_field', 'invalid_target', 'recipient_ineligible',
        'invalid_membership_context', 'rendering_failed', 'unsupported_connection',
        'transaction_required', 'persistence_failed',
    ];

    public readonly string $category;

    public function __construct(#[\SensitiveParameter] string $category)
    {
        $this->category = in_array($category, self::CATEGORIES, true) ? $category : 'publication_failed';
        parent::__construct('Notification publication failed.');
    }
}
