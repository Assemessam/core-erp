<?php

use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Domain\Memberships\RoleAssignmentRules;

// Plain Pest/PHPUnit: this directory does not extend the Laravel TestCase.
it('accepts equal organization identifiers without persistence', function (string $id) {
    expect(fn () => RoleAssignmentRules::requireSameOrganization($id, $id))
        ->not->toThrow(CrossOrganizationRoleAssignment::class);
})->with(['00000000000000000000000000', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '7ZZZZZZZZZZZZZZZZZZZZZZZZZ']);

it('rejects different organization identifiers with a specific domain failure', function (string $membershipId, string $roleId) {
    expect(fn () => RoleAssignmentRules::requireSameOrganization($membershipId, $roleId))
        ->toThrow(CrossOrganizationRoleAssignment::class, 'The role must belong to the membership organization.');
})->with([
    ['01ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAW'],
    ['00000000000000000000000000', '7ZZZZZZZZZZZZZZZZZZZZZZZZZ'],
]);
