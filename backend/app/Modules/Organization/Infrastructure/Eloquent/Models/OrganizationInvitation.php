<?php

namespace App\Modules\Organization\Infrastructure\Eloquent\Models;

use App\Modules\Organization\Domain\Invitations\InvitationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property InvitationState $state
 * @property CarbonImmutable $expires_at
 */
class OrganizationInvitation extends Model
{
    use HasUlids;

    protected $fillable = ['email', 'inviter_user_id', 'token_hash', 'state', 'expires_at'];

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => InvitationState::class, 'expires_at' => 'immutable_datetime'];
    }

    public function effectiveState(): InvitationState
    {
        return $this->state === InvitationState::Pending && $this->expires_at->isPast()
            ? InvitationState::Expired : $this->state;
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'organization_invitation_role')->withPivot('organization_id');
    }
}
