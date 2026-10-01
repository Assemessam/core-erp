<?php

namespace Tests\Support;

use App\Modules\Audit\Application\Data\AuditActor;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Data\AuditSubject;
use App\Modules\Audit\Application\Vocabulary\AuditAction;
use App\Modules\Audit\Application\Vocabulary\AuditSubjectType;

final class AuditFixtures
{
    public const string ORGANIZATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public const string ROLE_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

    public const string INVITATION_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAX';

    /** @param array<string, mixed> $overrides */
    public static function entry(array $overrides = []): AuditEntry
    {
        return new AuditEntry(...array_replace([
            'organizationId' => self::ORGANIZATION_ID,
            'actor' => AuditActor::user(12),
            'action' => AuditAction::OrganizationRenamed,
            'subject' => new AuditSubject(AuditSubjectType::Organization, self::ORGANIZATION_ID),
            'before' => ['name' => 'Old'],
            'after' => ['name' => 'New'],
            'payloadVersion' => 1,
        ], $overrides));
    }

    /** @return array<string, array{AuditAction, array<string, mixed>|null, array<string, mixed>|null}> */
    public static function examples(): array
    {
        return [
            'organization creation' => [AuditAction::OrganizationCreated, null, ['name' => 'CoreERP', 'owner_user_id' => 12, 'owner_membership_id' => 47]],
            'organization rename' => [AuditAction::OrganizationRenamed, ['name' => 'Old'], ['name' => 'New']],
            'role creation' => [AuditAction::RoleCreated, null, ['name' => 'Editor', 'permissions' => ['roles.view']]],
            'role rename' => [AuditAction::RoleUpdated, ['name' => 'Old'], ['name' => 'New']],
            'role permission change' => [AuditAction::RoleUpdated, ['permissions' => []], ['permissions' => ['roles.view']]],
            'role combined change' => [AuditAction::RoleUpdated, ['name' => 'Old', 'permissions' => []], ['name' => 'New', 'permissions' => ['roles.view']]],
            'invitation creation' => [AuditAction::InvitationCreated, null, ['state' => 'pending', 'expires_at' => '2026-10-08T10:15:30.123456Z', 'role_ids' => [self::ROLE_ID]]],
            'invitation revocation' => [AuditAction::InvitationRevoked, ['state' => 'pending'], ['state' => 'revoked']],
            'invitation replacement' => [AuditAction::InvitationRevoked, ['state' => 'pending'], ['state' => 'revoked', 'reason' => 'replaced', 'replacement_invitation_id' => self::ROLE_ID]],
            'invitation acceptance' => [AuditAction::InvitationAccepted, ['state' => 'pending'], ['state' => 'accepted', 'membership_id' => 47, 'user_id' => 12, 'role_ids' => [self::ROLE_ID]]],
            'membership grants' => [AuditAction::MembershipRolesChanged, ['user_id' => 29, 'role_ids' => []], ['user_id' => 29, 'role_ids' => [self::ROLE_ID]]],
            'membership suspension' => [AuditAction::MembershipSuspended, ['user_id' => 29, 'status' => 'active'], ['user_id' => 29, 'status' => 'suspended']],
            'membership activation' => [AuditAction::MembershipActivated, ['user_id' => 29, 'status' => 'suspended'], ['user_id' => 29, 'status' => 'active']],
            'membership removal' => [AuditAction::MembershipRemoved, ['user_id' => 29, 'status' => 'suspended', 'role_ids' => [self::ROLE_ID]], null],
        ];
    }
}
