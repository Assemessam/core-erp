<?php

namespace App\Modules\Organization\Application\Authorization;

use RuntimeException;

final class AccessDenied extends RuntimeException
{
    public function __construct(public readonly AccessDecision $decision)
    {
        parent::__construct($decision->message ?? 'Organization access denied.');
    }
}
