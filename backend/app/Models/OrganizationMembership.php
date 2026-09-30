<?php

namespace App\Models;

use App\Enums\PermissionKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrganizationMembership extends Model
{
    protected $fillable = ['organization_id', 'user_id'];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'organization_membership_role')
            ->withPivot('organization_id');
    }

    public function hasPermission(Organization $organization, PermissionKey $permission): bool
    {
        // Requery persisted identity/ownership; do not trust cached relationships.
        $membership = self::query()->whereKey($this->getKey())
            ->where('organization_id', $organization->getKey())->first();
        if ($membership === null) {
            return false;
        }

        if ($membership->organization()->where('owner_user_id', $membership->user_id)->exists()) {
            return true;
        }

        return $membership->roles()->where('roles.organization_id', $organization->getKey())
            ->whereHas('permissions', fn ($query) => $query->where('permissions.key', $permission->value))->exists();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
