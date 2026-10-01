<?php

namespace App\Modules\Organization\Infrastructure\Eloquent\Models;

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property MembershipStatus $status
 * @property-read User $user
 * @property-read Organization $organization
 */
class OrganizationMembership extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => MembershipStatus::class];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'organization_membership_role')
            ->withPivot('organization_id');
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
