<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->organization = OrganizationFixtures::unaudited($this->owner->id, 'Membership era');
    $this->recipient = User::factory()->create();
    $this->membership = $this->organization->memberships()->create(['user_id' => $this->recipient->id]);
    $this->draft = NotificationFixtures::draft(['organizationId' => $this->organization->id, 'recipientUserId' => $this->recipient->id]);
    $this->access = app(NotificationOrganizationAccess::class);
});

it('retains the old recipient membership era after removal and resolves a distinct era on rejoin', function () {
    $original = $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id);
    app(NotificationPublisher::class)->publish($this->draft);
    $oldRow = DB::table('organization_notifications')->where('organization_id', $this->organization->id)->sole();
    $this->membership->delete(); // Fixture-level removal of an ordinary member, not a new workflow.
    expect(fn () => $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id))->toThrow(AccessDenied::class);
    expect(fn () => app(NotificationPublisher::class)->publish($this->draft))->toThrow(NotificationWriteFailed::class);
    expect(DB::table('organization_notifications')->where('id', $oldRow->id)->sole()->recipient_membership_id)->toBe($original->membershipId);
    $replacement = $this->organization->memberships()->create(['user_id' => $this->recipient->id]);
    $current = $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id);
    expect($current->membershipId)->toBe($replacement->id)->not->toBe($original->membershipId);
    expect($current->organizationId)->toBe($original->organizationId);
    expect($current->userId)->toBe($original->userId);
    app(NotificationPublisher::class)->publish($this->draft);
    $rows = DB::table('organization_notifications')->where('organization_id', $this->organization->id)->where('recipient_user_id', $this->recipient->id)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->firstWhere('id', $oldRow->id)->recipient_membership_id)->toBe($original->membershipId);
    expect($rows->where('recipient_membership_id', $current->membershipId))->toHaveCount(1);
    // No consumer query/API is added in B. C must scope every operation by all three identifiers.
});

it('retains membership identity through suspension and reactivation while denying suspended publication', function () {
    app(NotificationPublisher::class)->publish($this->draft);
    $original = $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id);
    $this->membership->update(['status' => 'suspended']);
    expect($this->membership->fresh()->id)->toBe($original->membershipId);
    expect(fn () => $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id))->toThrow(AccessDenied::class);
    expect(fn () => app(NotificationPublisher::class)->publish($this->draft))->toThrow(NotificationWriteFailed::class);
    $this->membership->update(['status' => 'active']);
    $reactivated = $this->access->resolveActiveMembership($this->recipient->id, $this->organization->id);
    expect($reactivated->membershipId)->toBe($original->membershipId);
    app(NotificationPublisher::class)->publish($this->draft);
    $rows = DB::table('organization_notifications')->where('organization_id', $this->organization->id)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('recipient_membership_id')->unique()->all())->toBe([$original->membershipId]);
});
