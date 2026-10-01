<?php

namespace App\Modules\Organization\Application\Exceptions;

use RuntimeException;
use Throwable;

final class RoleNameConflict extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('A role with this name already exists in this organization.', 0, $previous);
    }
}
