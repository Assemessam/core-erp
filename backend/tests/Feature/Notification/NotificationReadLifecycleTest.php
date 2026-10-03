<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Commands\MarkAllNotificationsRead;
use App\Modules\Notification\Application\Commands\MarkNotificationRead;
use App\Modules\Notification\Application\Contracts\NotificationReadStore;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationReadStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationReadFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->org = OrganizationFixtures::unaudited($this->owner->id, 'Read lifecycle');
    $this->actor = User::factory()->create();
    $this->member = $this->org->memberships()->create(['user_id' => $this->actor->id]);
    $this->url = '/api/v1/organizations/'.$this->org->id.'/notifications';
    $this->mark = app(MarkNotificationRead::class);
    $this->markAll = app(MarkAllNotificationsRead::class);
});

it('generates the first read timestamp in SQL ignores client scope/time and preserves all content on replay', function () {
    expect(app(NotificationReadStore::class))->toBeInstanceOf(DatabaseNotificationReadStore::class);
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    $before = (array) DB::table('organization_notifications')->where('id', $id)->sole();
    $serverBefore = DB::selectOne('SELECT clock_timestamp() AS time')->time;
    $this->actingAs($this->actor)->postJson($this->url.'/'.$id.'/read', [
        'recipient_user_id' => $this->owner->id, 'recipient_membership_id' => 1, 'read_at' => '2099-01-01T00:00:00.000000Z',
    ])->assertNoContent();
    $first = (array) DB::table('organization_notifications')->where('id', $id)->sole();
    expect($first['read_at'])->not->toBeNull();
    $withinServerTime = DB::selectOne('SELECT read_at >= ?::timestamptz AND read_at <= clock_timestamp() AND read_at >= created_at AS valid FROM organization_notifications WHERE id = ?', [$serverBefore, $id]);
    expect($withinServerTime->valid)->toBeTrue();
    expect(array_replace($first, ['read_at' => null]))->toBe($before);
    DB::select('SELECT pg_sleep(0.002)');
    $this->postJson($this->url.'/'.$id.'/read')->assertNoContent();
    $this->mark->handle($this->actor->id, $this->org->id, $id);
    expect((array) DB::table('organization_notifications')->where('id', $id)->sole())->toBe($first);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.read_at', (new DateTimeImmutable($first['read_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'));
});

it('uses a conditional database update and scoped existence fallback for a competing replay', function () {
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    $firstAttempt = app(MarkNotificationRead::class);
    $secondAttempt = app(MarkNotificationRead::class);
    $firstAttempt->handle($this->actor->id, $this->org->id, $id);
    $firstTime = DB::table('organization_notifications')->where('id', $id)->sole()->read_at;
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $secondAttempt->handle($this->actor->id, $this->org->id, $id);
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"')));
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(2);
    expect(strtolower($queries[0]['query']))->toContain('update "organization_notifications"', 'greatest(clock_timestamp(), created_at)', '"organization_id" = ? and "recipient_user_id" = ? and "recipient_membership_id" = ? and "id" = ? and "read_at" is null');
    expect($queries[0]['bindings'])->toBe([$this->org->id, $this->actor->id, $this->member->id, $id]);
    expect(strtolower($queries[1]['query']))->toContain('select exists', '"organization_id" = ? and "recipient_user_id" = ? and "recipient_membership_id" = ? and "id" = ?');
    expect($queries[1]['bindings'])->toBe($queries[0]['bindings']);
    expect(DB::table('organization_notifications')->where('id', $id)->sole()->read_at)->toBe($firstTime);
});

it('marks all current-era unread rows with one update and leaves other scopes and first timestamps untouched', function () {
    $unread = [];
    foreach (range(1, 3) as $i) {
        $unread[] = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    }
    $alreadyRead = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['read_at' => '2026-10-01T00:00:01.654321Z']);
    $originalTime = DB::table('organization_notifications')->where('id', $alreadyRead)->sole()->read_at;
    $oldEra = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id + 100000);
    $ownerMember = $this->org->memberships()->where('user_id', $this->owner->id)->sole();
    $otherUser = NotificationReadFixtures::insert($this->org->id, $this->owner->id, $ownerMember->id);
    $b = OrganizationFixtures::unaudited($this->owner->id, 'Read all B');
    $bMember = $b->memberships()->create(['user_id' => $this->actor->id]);
    $otherTenant = NotificationReadFixtures::insert($b->id, $this->actor->id, $bMember->id);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->actingAs($this->actor)->postJson($this->url.'/read-all', ['read_at' => '2099-01-01', 'recipient_user_id' => $this->owner->id])->assertNoContent();
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"')));
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('"organization_id" = ? and "recipient_user_id" = ? and "recipient_membership_id" = ? and "read_at" is null');
    expect($queries[0]['bindings'])->toBe([$this->org->id, $this->actor->id, $this->member->id]);
    expect(DB::table('organization_notifications')->whereIn('id', $unread)->whereNotNull('read_at')->count())->toBe(3);
    expect(DB::table('organization_notifications')->where('id', $alreadyRead)->sole()->read_at)->toBe($originalTime);
    expect(DB::table('organization_notifications')->whereIn('id', [$oldEra, $otherUser, $otherTenant])->whereNull('read_at')->count())->toBe(3);
    $firstTimes = DB::table('organization_notifications')->whereIn('id', $unread)->pluck('read_at', 'id')->all();
    $this->markAll->handle($this->actor->id, $this->org->id);
    $this->postJson($this->url.'/read-all')->assertNoContent();
    expect(DB::table('organization_notifications')->whereIn('id', $unread)->pluck('read_at', 'id')->all())->toBe($firstTimes);
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 0]]);
});

it('succeeds for an empty mark-all and leaves a later inserted row unread', function () {
    $this->actingAs($this->actor)->postJson($this->url.'/read-all')->assertNoContent();
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    expect(DB::table('organization_notifications')->where('id', $id)->sole()->read_at)->toBeNull();
    $this->getJson($this->url.'/unread-count')->assertExactJson(['data' => ['unread_count' => 1]]);
});

it('satisfies read timestamp constraints even when creation is ahead of the current database clock', function () {
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['created_at' => '2099-01-01T00:00:00.123456Z']);
    $this->mark->handle($this->actor->id, $this->org->id, $id);
    expect(DB::selectOne('SELECT read_at = created_at AS clamped FROM organization_notifications WHERE id = ?', [$id])->clamped)->toBeTrue();
    $another = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['created_at' => '2099-01-01T00:00:01.123456Z']);
    $this->markAll->handle($this->actor->id, $this->org->id);
    expect(DB::selectOne('SELECT read_at = created_at AS clamped FROM organization_notifications WHERE id = ?', [$another])->clamped)->toBeTrue();
});

it('sanitizes actual read-state SQL failure without changing content or retaining a database exception', function () {
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('ALTER TABLE organization_notifications ADD CONSTRAINT notification_read_test_failure CHECK (read_at IS NULL)');
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    try {
        DB::transaction(fn () => $this->mark->handle($this->actor->id, $this->org->id, $id));
        test()->fail('Expected safe storage failure.');
    } catch (NotificationStorageFailed $failure) {
        expect($failure->getMessage())->toBe('Notification storage is unavailable.');
        expect($failure->getPrevious())->toBeNull();
        expect(str_contains((string) $failure, 'notification_read_test_failure') || str_contains((string) $failure, 'update "organization_notifications"'))->toBeFalse();
    }
    expect(DB::table('organization_notifications')->where('id', $id)->sole()->read_at)->toBeNull();
});
