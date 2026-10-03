<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Commands\MarkAllNotificationsRead;
use App\Modules\Notification\Application\Commands\MarkNotificationRead;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Exceptions\NotificationNotFound;
use App\Modules\Notification\Application\Queries\GetUnreadNotificationCount;
use App\Modules\Notification\Application\Queries\ListNotifications;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Commands\ActivateMembership;
use App\Modules\Organization\Application\Commands\RemoveMembership;
use App\Modules\Organization\Application\Commands\SuspendMembership;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationReadFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->org = OrganizationFixtures::unaudited($this->owner->id, 'Consumer membership era');
    $this->actor = User::factory()->create();
    $this->member = $this->org->memberships()->create(['user_id' => $this->actor->id]);
    $this->url = '/api/v1/organizations/'.$this->org->id.'/notifications';
});

it('excludes retained old-era rows from every operation and cursor replay after removal and rejoin', function () {
    $aIds = [];
    foreach (range(1, 2) as $i) {
        $aIds[] = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['created_at' => '2026-10-02T00:00:00.123456Z']);
    }
    $alreadyRead = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['read_at' => '2026-10-01T00:00:01.654321Z']);
    $aIds[] = $alreadyRead;
    $originalRows = DB::table('organization_notifications')->whereIn('id', $aIds)->orderBy('id')->get()->all();
    $this->actingAs($this->actor)->getJson($this->url)->assertOk()->assertJsonCount(3, 'data');
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 2]]);
    $cursor = $this->getJson($this->url.'?per_page=1')->assertOk()->json('meta.next_cursor');
    $eraA = app(NotificationOrganizationAccess::class)->resolveActiveMembership($this->actor->id, $this->org->id);
    app(RemoveMembership::class)->handle($this->owner->id, $this->org->id, $this->member->id);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson($this->url.'/unread-count')->assertNotFound();
    $this->postJson($this->url.'/'.$aIds[0].'/read')->assertNotFound();
    $this->postJson($this->url.'/read-all')->assertNotFound();
    foreach ([
        fn () => app(ListNotifications::class)->handle($this->actor->id, $this->org->id),
        fn () => app(GetUnreadNotificationCount::class)->handle($this->actor->id, $this->org->id),
        fn () => app(MarkNotificationRead::class)->handle($this->actor->id, $this->org->id, $aIds[0]),
        fn () => app(MarkAllNotificationsRead::class)->handle($this->actor->id, $this->org->id),
    ] as $operation) {
        expect($operation)->toThrow(AccessDenied::class);
    }
    $replacement = $this->org->memberships()->create(['user_id' => $this->actor->id]);
    $eraB = app(NotificationOrganizationAccess::class)->resolveActiveMembership($this->actor->id, $this->org->id);
    expect($eraB->membershipId)->toBe($replacement->id)->not->toBe($eraA->membershipId);
    $this->getJson($this->url)->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25]]);
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
    expect(app(ListNotifications::class)->handle($this->actor->id, $this->org->id)->notifications)->toBe([]);
    expect(app(GetUnreadNotificationCount::class)->handle($this->actor->id, $this->org->id))->toBe(0);
    expect(fn () => app(MarkNotificationRead::class)->handle($this->actor->id, $this->org->id, $aIds[0]))->toThrow(NotificationNotFound::class);
    $this->postJson($this->url.'/'.$aIds[0].'/read')->assertNotFound()->assertExactJson(['message' => 'Notification not found.']);
    $this->postJson($this->url.'/read-all')->assertNoContent();
    app(MarkAllNotificationsRead::class)->handle($this->actor->id, $this->org->id);
    expect(DB::table('organization_notifications')->whereIn('id', $aIds)->orderBy('id')->get()->all())->toEqual($originalRows);
    $bId = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $eraB->membershipId);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bId);
    $this->getJson($this->url.'?'.http_build_query(['cursor' => $cursor]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bId);
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
    $this->postJson($this->url.'/read-all')->assertNoContent();
    expect(DB::table('organization_notifications')->where('id', $bId)->sole()->read_at)->not->toBeNull();
    expect(DB::table('organization_notifications')->whereIn('id', $aIds)->orderBy('id')->get()->all())->toEqual($originalRows);
    expect(DB::table('organization_notifications')->whereIn('id', $aIds)->pluck('recipient_membership_id')->unique()->all())->toBe([$eraA->membershipId]);
});

it('hides all operations while suspended and restores original era content and read state after reactivation', function () {
    $unread = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    $alreadyRead = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['read_at' => '2026-10-01T00:00:01.654321Z']);
    $originalRows = DB::table('organization_notifications')->whereIn('id', [$unread, $alreadyRead])->orderBy('id')->get()->all();
    $this->actingAs($this->actor)->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
    app(SuspendMembership::class)->handle($this->owner->id, $this->org->id, $this->member->id);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson($this->url.'/unread-count')->assertNotFound();
    $this->postJson($this->url.'/'.$unread.'/read')->assertNotFound();
    $this->postJson($this->url.'/read-all')->assertNotFound();
    app(ActivateMembership::class)->handle($this->owner->id, $this->org->id, $this->member->id);
    expect(app(NotificationOrganizationAccess::class)->resolveActiveMembership($this->actor->id, $this->org->id)->membershipId)->toBe($this->member->id);
    $this->getJson($this->url)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
    expect(DB::table('organization_notifications')->whereIn('id', [$unread, $alreadyRead])->orderBy('id')->get()->all())->toEqual($originalRows);
    $this->postJson($this->url.'/'.$alreadyRead.'/read')->assertNoContent();
    expect(DB::table('organization_notifications')->whereIn('id', [$unread, $alreadyRead])->orderBy('id')->get()->all())->toEqual($originalRows);
    $this->postJson($this->url.'/'.$unread.'/read')->assertNoContent();
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
});
