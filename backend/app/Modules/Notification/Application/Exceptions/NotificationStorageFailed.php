<?php

namespace App\Modules\Notification\Application\Exceptions;

use RuntimeException;

/** Safe consumer storage failure, without SQL/bindings/previous exception. */
final class NotificationStorageFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Notification storage is unavailable.');
    }
}
