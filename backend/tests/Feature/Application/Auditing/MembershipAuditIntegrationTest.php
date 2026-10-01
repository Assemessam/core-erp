<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Commands\ActivateMembership;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\RemoveMembership;
use App\Modules\Organization\Application\Commands\SuspendMembership;
use App\Modules\Organization\Application\Commands\SyncMembershipRoles;
use App\Modules\Organization\Domain\Memberships\CrossOrganizationRoleAssignment;
use App\Modules\Organization\Domain\Memberships\MembershipStatus;
use App\Modules\Organization\Domain\Memberships\OwnerMembershipProtected;
use Illuminate\Support\Facades\DB;
use Tests\Support\LifecycleAuditAssertions as AuditFacts;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->user = User::factory()->create();
    $this->org = app(CreateOrganization::class)->handle($this->owner->id, 'Membership audit');
    $this->member = $this->org->memberships()->create(['user_id' => $this->user->id]);
    $this->first = $this->org->roles()->create(['name' => 'First']);
    $this->second = $this->org->roles()->create(['name' => 'Second']);
    $this->first->permissions()->sync(['roles.view']);
    $this->member->roles()->attach($this->first->id, ['organization_id' => $this->org->id]);
});

it('records fresh complete persisted role sets rather than cached assignments or input order', function () {
    $this->member->load('roles');
    $this->member->roles()->sync([$this->second->id => ['organization_id' => $this->org->id]]);
    $this->actingAs($this->user);
    app(SyncMembershipRoles::class)->handle($this->owner->id, $this->org->id, $this->member->id, [$this->second->id, $this->first->id, $this->second->id]);
    AuditFacts::fact($this->org->id, $this->owner->id, 'membership.roles_changed', 'membership', $this->member->id,
        ['user_id' => $this->user->id, 'role_ids' => [$this->second->id]],
        ['user_id' => $this->user->id, 'role_ids' => AuditFacts::sorted([$this->first->id, $this->second->id])]);
    expect($this->member->roles()->count())->toBe(2);
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->count())->toBe(2);
});

it('records complete grant removal and skips equivalent reordered duplicate sets', function () {
    $this->member->roles()->attach($this->second->id, ['organization_id' => $this->org->id]);
    $count = DB::table('audit_events')->count();
    app(SyncMembershipRoles::class)->handle($this->owner->id, $this->org->id, $this->member->id, [$this->second->id, $this->first->id, $this->second->id]);
    expect(DB::table('audit_events')->count())->toBe($count);
    app(SyncMembershipRoles::class)->handle($this->owner->id, $this->org->id, $this->member->id, []);
    AuditFacts::fact($this->org->id, $this->owner->id, 'membership.roles_changed', 'membership', $this->member->id,
        ['user_id' => $this->user->id, 'role_ids' => AuditFacts::sorted([$this->first->id, $this->second->id])],
        ['user_id' => $this->user->id, 'role_ids' => []]);
});

it('records actual membership status transitions retaining roles and explicit caller attribution', function (string $command, string $action, MembershipStatus $before, MembershipStatus $after) {
    $this->member->update(['status' => $before]);
    $this->actingAs($this->user);
    app($command)->handle($this->owner->id, $this->org->id, $this->member->id);
    expect($this->member->fresh()->status)->toBe($after);
    expect($this->member->roles()->pluck('roles.id')->all())->toBe([$this->first->id]);
    AuditFacts::fact($this->org->id, $this->owner->id, $action, 'membership', $this->member->id,
        ['user_id' => $this->user->id, 'status' => $before->value], ['user_id' => $this->user->id, 'status' => $after->value]);
})->with([
    'suspend' => [SuspendMembership::class, 'membership.suspended', MembershipStatus::Active, MembershipStatus::Suspended],
    'activate' => [ActivateMembership::class, 'membership.activated', MembershipStatus::Suspended, MembershipStatus::Active],
]);

it('skips repeat status no-ops using fresh persisted status despite a stale fixture', function (string $command, MembershipStatus $status) {
    DB::table('organization_memberships')->where('id', $this->member->id)->update(['status' => $status->value]);
    $count = DB::table('audit_events')->count();
    app($command)->handle($this->owner->id, $this->org->id, $this->member->id);
    app($command)->handle($this->owner->id, $this->org->id, $this->member->id);
    expect($this->member->fresh()->status)->toBe($status);
    expect(DB::table('audit_events')->count())->toBe($count);
})->with([
    'already suspended' => [SuspendMembership::class, MembershipStatus::Suspended],
    'already active' => [ActivateMembership::class, MembershipStatus::Active],
]);

it('retains removal history after deleting the membership and cascading grants', function (MembershipStatus $status) {
    $this->member->load('roles');
    $this->member->roles()->attach($this->second->id, ['organization_id' => $this->org->id]);
    DB::table('organization_memberships')->where('id', $this->member->id)->update(['status' => $status->value]);
    app(RemoveMembership::class)->handle($this->owner->id, $this->org->id, $this->member->id);
    expect($this->member->fresh())->toBeNull();
    expect($this->member->roles()->count())->toBe(0);
    expect($this->user->fresh())->not->toBeNull();
    expect($this->first->fresh())->not->toBeNull();
    expect($this->second->fresh())->not->toBeNull();
    AuditFacts::fact($this->org->id, $this->owner->id, 'membership.removed', 'membership', $this->member->id,
        ['user_id' => $this->user->id, 'status' => $status->value, 'role_ids' => AuditFacts::sorted([$this->first->id, $this->second->id])], null);
})->with([MembershipStatus::Active, MembershipStatus::Suspended]);

it('restores complete grants and membership status or deletion when audit recording fails', function (string $command, MembershipStatus $status) {
    $this->member->update(['status' => $status]);
    $this->member->roles()->attach($this->second->id, ['organization_id' => $this->org->id]);
    $count = DB::table('audit_events')->count();
    $viewBefore = app(OrganizationAccess::class)->view($this->user->id, $this->org->id)->outcome;
    $this->app->instance(AuditRecorder::class, new class implements AuditRecorder
    {
        public function record(#[SensitiveParameter] AuditEntry $entry): void
        {
            throw new AuditWriteFailed('persistence_failed');
        }
    });
    expect(fn () => $command === SyncMembershipRoles::class
        ? app($command)->handle($this->owner->id, $this->org->id, $this->member->id, [])
        : app($command)->handle($this->owner->id, $this->org->id, $this->member->id))->toThrow(AuditWriteFailed::class);
    expect($this->member->fresh()->status)->toBe($status);
    expect(AuditFacts::sorted($this->member->roles()->pluck('roles.id')->all()))->toBe(AuditFacts::sorted([$this->first->id, $this->second->id]));
    expect(app(OrganizationAccess::class)->view($this->user->id, $this->org->id)->outcome)->toBe($viewBefore);
    expect(DB::table('audit_events')->count())->toBe($count);
})->with([
    'sync' => [SyncMembershipRoles::class, MembershipStatus::Active],
    'suspend' => [SuspendMembership::class, MembershipStatus::Active],
    'activate' => [ActivateMembership::class, MembershipStatus::Suspended],
    'remove' => [RemoveMembership::class, MembershipStatus::Active],
]);

it('produces no fact for protected owner suspension or removal', function (string $command) {
    $ownerMember = $this->org->memberships()->where('user_id', $this->owner->id)->sole();
    $count = DB::table('audit_events')->count();
    expect(fn () => app($command)->handle($this->owner->id, $this->org->id, $ownerMember->id))->toThrow(OwnerMembershipProtected::class);
    expect($ownerMember->fresh()->status)->toBe(MembershipStatus::Active);
    expect(DB::table('audit_events')->count())->toBe($count);
})->with([SuspendMembership::class, RemoveMembership::class]);

it('emits no membership facts on unauthorized foreign member or foreign role attempts', function () {
    $other = app(CreateOrganization::class)->handle($this->owner->id, 'Elsewhere');
    $foreignMember = $other->memberships()->sole();
    $foreignRole = $other->roles()->create(['name' => 'Foreign']);
    $count = DB::table('audit_events')->count();
    $this->actingAs($this->owner);
    foreach ([SuspendMembership::class, ActivateMembership::class, RemoveMembership::class] as $command) {
        expect(fn () => app($command)->handle($this->user->id, $this->org->id, $this->member->id))->toThrow(AccessDenied::class);
        expect(fn () => app($command)->handle($this->owner->id, $this->org->id, $foreignMember->id))->toThrow(AccessDenied::class);
    }
    expect(fn () => app(SyncMembershipRoles::class)->handle($this->owner->id, $this->org->id, $this->member->id, [$foreignRole->id]))->toThrow(CrossOrganizationRoleAssignment::class);
    expect(DB::table('audit_events')->count())->toBe($count);
    expect($this->member->roles()->pluck('roles.id')->all())->toBe([$this->first->id]);
});
