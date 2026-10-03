<?php

namespace App\Modules\Notification\Application\Vocabulary;

enum NotificationType: string
{
    case OrganizationInvitationAccepted = 'organization.invitation_accepted';
}
