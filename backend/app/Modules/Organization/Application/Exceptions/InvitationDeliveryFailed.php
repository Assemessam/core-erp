<?php

namespace App\Modules\Organization\Application\Exceptions;

use RuntimeException;

final class InvitationDeliveryFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invitation saved, but email could not be sent. Refresh the list and reinvite to send a new link.');
    }
}
