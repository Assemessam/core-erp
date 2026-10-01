<?php

namespace App\Modules\Organization\Presentation\Http\Exceptions;

use App\Modules\Organization\Application\Exceptions\InvitationDeliveryFailed;
use App\Modules\Organization\Application\Exceptions\RoleNameConflict;
use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Domain\Memberships\OwnerMembershipProtected;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class OrganizationFailureMapper
{
    public static function invitationDeliveryFailed(InvitationDeliveryFailed $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], 503);
    }

    public static function invitationRejected(InvitationRejected $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], 422);
    }

    public static function ownerMembershipProtected(OwnerMembershipProtected $exception): ValidationException
    {
        return ValidationException::withMessages(['membership' => $exception->getMessage()]);
    }

    public static function roleNameConflict(RoleNameConflict $exception): ValidationException
    {
        return ValidationException::withMessages(['name' => $exception->getMessage()]);
    }

    public static function crossOrganizationRoleAssignment(CrossOrganizationRoleAssignment $exception): ValidationException
    {
        return ValidationException::withMessages(['role' => $exception->getMessage()]);
    }
}
