<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationRules;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;

/** Identity values must come from a trusted authenticated adapter, never request payload. */
class AcceptInvitation
{
    public function __construct(private readonly AssignMembershipRole $assign) {}

    public function handle(int $actorUserId, string $actorEmail, bool $verified, string $invitationId, #[\SensitiveParameter] string $token): Organization
    {
        return DB::transaction(function () use ($actorUserId, $actorEmail, $verified, $invitationId, $token): Organization {
            $candidate = OrganizationInvitation::query()->whereKey($invitationId)->first();
            if ($candidate === null) {
                throw new InvitationRejected('invalid');
            }
            $organization = Organization::query()->whereKey($candidate->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = $organization->invitations()->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if (! hash_equals($invitation->token_hash, hash('sha256', $token))) {
                throw new InvitationRejected('invalid');
            }
            InvitationRules::requireAcceptable($invitation->state, $invitation->expires_at, now()->toDateTimeImmutable(), $invitation->email, $actorEmail, $verified);
            if ($organization->memberships()->where('user_id', $actorUserId)->exists()) {
                throw new InvitationRejected('member_exists');
            }
            $membership = $organization->memberships()->create(['user_id' => $actorUserId, 'status' => MembershipStatus::Active]);
            foreach ($invitation->roles()->get() as $role) {
                $this->assign->handle($membership, $role);
            }
            $invitation->update(['state' => InvitationState::Accepted]);

            return $organization;
        });
    }
}
