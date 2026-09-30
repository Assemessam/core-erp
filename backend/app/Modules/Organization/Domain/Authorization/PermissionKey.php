<?php

namespace App\Modules\Organization\Domain\Authorization;

enum PermissionKey: string
{
    case OrganizationsUpdate = 'organizations.update';
    case RolesView = 'roles.view';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationsUpdate => 'Update organization details',
            self::RolesView => 'View roles and permissions',
        };
    }
}
