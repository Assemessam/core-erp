<?php

use App\Modules\Organization\Domain\Invitations\InvitationRejected;
use App\Modules\Organization\Domain\Invitations\InvitationRules;
use App\Modules\Organization\Domain\Invitations\InvitationState;
use App\Modules\Organization\Domain\Memberships\MembershipRules;
use App\Modules\Organization\Domain\Memberships\OwnerMembershipProtected;

it('protects owner lifecycle independently of actor authority', function () {
    expect(fn () => MembershipRules::requireNotOwner(1, 1))->toThrow(OwnerMembershipProtected::class);
    MembershipRules::requireNotOwner(2, 1);
});

it('binds invitations to verified normalized email and a live pending state', function () {
    $now = new DateTimeImmutable('2026-10-01T12:00:00Z');
    InvitationRules::requireAcceptable(InvitationState::Pending, $now->modify('+1 day'), $now, 'alice@example.test', ' ALICE@example.test ', true);
    expect(InvitationRules::normalizeEmail(' ALICE@example.test '))->toBe('alice@example.test');
});

it('rejects invalid domain acceptance independently of persistence', function (InvitationState $state, string $expiry, string $email, bool $verified, string $reason) {
    $now = new DateTimeImmutable('2026-10-01T12:00:00Z');
    try {
        InvitationRules::requireAcceptable($state, $now->modify($expiry), $now, 'alice@example.test', $email, $verified);
        test()->fail('Expected rejection');
    } catch (InvitationRejected $error) {
        expect($error->reason)->toBe($reason);
    }
})->with([
    [InvitationState::Pending, '+1 day', 'bob@example.test', true, 'email_mismatch'],
    [InvitationState::Pending, '+1 day', 'alice@example.test', false, 'unverified'],
    [InvitationState::Pending, '+0 seconds', 'alice@example.test', true, 'expired'],
    [InvitationState::Revoked, '+1 day', 'alice@example.test', true, 'revoked'],
    [InvitationState::Accepted, '+1 day', 'alice@example.test', true, 'accepted'],
]);
