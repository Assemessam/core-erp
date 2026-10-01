<?php

namespace App\Modules\Organization\Application\Commands;

use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Operations\ResolveOrganizationRoles;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationRules;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationInvitation;
use App\Modules\Organization\Infrastructure\Mail\InvitationDelivery;
use Illuminate\Support\Facades\DB;

class CreateInvitation
{
    public function __construct(private readonly OrganizationAccess $access, private readonly ResolveOrganizationRoles $roles, private readonly InvitationDelivery $delivery) {}

    /** @param list<string> $roleIds */
    public function handle(int $actorUserId, string $organizationId, string $email, array $roleIds): OrganizationInvitation
    {
        $this->access->inviteMembers($actorUserId, $organizationId)->requireAllowed();

        return DB::transaction(function () use ($actorUserId, $organizationId, $email, $roleIds): OrganizationInvitation {
            // All invitation/lifecycle commands lock tenant first, then invitation/member.
            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            $this->access->inviteMembers($actorUserId, $organizationId)->requireAllowed();
            if ($roleIds !== []) {
                $this->access->manageMembers($actorUserId, $organizationId)->requireAllowed();
            }
            $roles = $this->roles->handle($organizationId, $roleIds);
            $email = InvitationRules::normalizeEmail($email);
            if ($organization->memberships()->whereHas('user', fn ($query) => $query->whereRaw('lower(btrim(email)) = ?', [$email]))->exists()) {
                throw new InvitationRejected('member_exists');
            }
            $previous = $organization->invitations()->where('email', $email)->where('state', InvitationState::Pending)->lockForUpdate()->first();
            // Delegated inviters cannot erase a pending owner-selected role grant by reinviting.
            if ($previous !== null && $previous->roles()->exists()) {
                $this->access->manageMembers($actorUserId, $organizationId)->requireAllowed();
            }
            $previous?->update(['state' => InvitationState::Revoked]);
            $token = bin2hex(random_bytes(32));
            $invitation = $organization->invitations()->create([
                'email' => $email, 'inviter_user_id' => $actorUserId,
                'token_hash' => hash('sha256', $token), 'state' => InvitationState::Pending,
                'expires_at' => now()->addDays((int) config('organization.invitation_days')),
            ]);
            foreach ($roles as $role) {
                $invitation->roles()->attach($role->id, ['organization_id' => $organizationId]);
            }
            DB::afterCommit(fn () => $this->delivery->send($invitation, $organization->name, $token));

            return $invitation->load('roles.permissions');
        });
    }
}
