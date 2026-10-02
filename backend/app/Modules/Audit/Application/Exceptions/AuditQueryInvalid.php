<?php

namespace App\Modules\Audit\Application\Exceptions;

use RuntimeException;

final class AuditQueryInvalid extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The audit query is invalid.');
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}
