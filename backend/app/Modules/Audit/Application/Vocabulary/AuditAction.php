<?php

namespace App\Modules\Audit\Application\Vocabulary;

enum AuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationRenamed = 'organization.renamed';
    case RoleCreated = 'role.created';
    case RoleUpdated = 'role.updated';
    case InvitationCreated = 'invitation.created';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';
    case MembershipRolesChanged = 'membership.roles_changed';
    case MembershipSuspended = 'membership.suspended';
    case MembershipActivated = 'membership.activated';
    case MembershipRemoved = 'membership.removed';

    public function subjectType(): AuditSubjectType
    {
        return match ($this) {
            self::OrganizationCreated, self::OrganizationRenamed => AuditSubjectType::Organization,
            self::RoleCreated, self::RoleUpdated => AuditSubjectType::Role,
            self::InvitationCreated, self::InvitationRevoked, self::InvitationAccepted => AuditSubjectType::Invitation,
            self::MembershipRolesChanged, self::MembershipSuspended, self::MembershipActivated, self::MembershipRemoved => AuditSubjectType::Membership,
        };
    }
}
