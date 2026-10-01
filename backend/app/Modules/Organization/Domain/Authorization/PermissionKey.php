<?php

namespace App\Modules\Organization\Domain\Authorization;

enum PermissionKey: string
{
    case OrganizationsUpdate = 'organizations.update';
    case RolesView = 'roles.view';
    case MembersView = 'members.view';
    case MembersInvite = 'members.invite';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationsUpdate => 'Update organization details',
            self::RolesView => 'View roles and permissions',
            self::MembersView => 'View organization members',
            self::MembersInvite => 'Invite organization members',
        };
    }
}
