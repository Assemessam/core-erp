<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use Illuminate\Support\Facades\DB;

class RevokeInvitation
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function handle(int $actorUserId, string $organizationId, string $invitationId): void
    {
        $this->access->inviteMembers($actorUserId, $organizationId)->requireAllowed();
        DB::transaction(function () use ($actorUserId, $organizationId, $invitationId): void {
            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $this->access->inviteMembers($actorUserId, $organizationId)->requireAllowed();
            $invitation = $organization->invitations()->whereKey($invitationId)->lockForUpdate()->first();
            if ($invitation === null) {
                AccessDecision::hidden()->requireAllowed();

                return;
            }
            if ($invitation->state !== InvitationState::Pending) {
                return;
            }
            if ($invitation->roles()->exists()) {
                $this->access->manageMembers($actorUserId, $organizationId)->requireAllowed();
            }
            $invitation->update(['state' => InvitationState::Revoked]);
        });
    }
}
