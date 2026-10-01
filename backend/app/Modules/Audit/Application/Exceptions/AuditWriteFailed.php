<?php

namespace App\Modules\Audit\Application\Exceptions;

use RuntimeException;

final class AuditWriteFailed extends RuntimeException
{
    private const array CATEGORIES = [
        'invalid_actor', 'invalid_identifier', 'unsupported_version', 'invalid_subject',
        'invalid_snapshot', 'invalid_json', 'payload_too_large', 'payload_too_deep',
        'collection_too_large', 'prohibited_key', 'invalid_field', 'invalid_value',
        'string_too_large', 'missing_field', 'unknown_field', 'invalid_transition',
        'unsupported_connection', 'transaction_required', 'persistence_failed',
    ];

    public readonly string $category;

    public function __construct(#[\SensitiveParameter] string $category)
    {
        $this->category = in_array($category, self::CATEGORIES, true) ? $category : 'recording_failed';
        parent::__construct('Audit recording failed.');
    }
}
