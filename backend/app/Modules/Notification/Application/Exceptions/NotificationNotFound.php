<?php

namespace App\Modules\Notification\Application\Exceptions;

use RuntimeException;

final class NotificationNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Notification not found.');
    }
}
