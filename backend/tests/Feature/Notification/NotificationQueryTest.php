<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Data\NotificationCursor;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use App\Modules\Notification\Application\Queries\GetUnreadNotificationCount;
use App\Modules\Notification\Application\Queries\ListNotifications;
use App\Modules\Notification\Infrastructure\Persistence\DatabaseNotificationReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\NotificationReadFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->org = OrganizationFixtures::unaudited($this->owner->id, 'Notification query');
    $this->actor = User::factory()->create();
    $this->member = $this->org->memberships()->create(['user_id' => $this->actor->id]);
    $this->url = '/api/v1/organizations/'.$this->org->id.'/notifications';
    $this->list = app(ListNotifications::class);
    $this->count = app(GetUnreadNotificationCount::class);
});

it('returns an exact empty default page and zero unread count for an ordinary active member', function () {
    expect(app(NotificationReader::class))->toBeInstanceOf(DatabaseNotificationReader::class);
    $this->actingAs($this->actor)->getJson($this->url)->assertOk()->assertExactJson([
        'data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25],
    ]);
    $this->getJson($this->url.'/unread-count')->assertOk()->assertExactJson(['data' => ['unread_count' => 0]]);
    expect($this->list->handle($this->actor->id, $this->org->id)->notifications)->toBe([]);
    expect($this->count->handle($this->actor->id, $this->org->id))->toBe(0);
});

it('paginates a deterministic timestamp-tied set without duplication or omission', function () {
    $ids = [];
    foreach (['2026-10-02T00:00:00.123456Z', '2026-10-02T00:00:00.123456Z', '2026-10-02T00:00:00.123456Z', '2026-10-01T12:00:00.000001Z', '2026-10-01T12:00:00.000001Z', '2026-09-30T00:00:00.000000Z', '2026-09-29T00:00:00.000000Z'] as $time) {
        $ids[] = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['created_at' => $time]);
    }
    $expected = DB::table('organization_notifications')->whereIn('id', $ids)->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    $this->actingAs($this->actor);
    $seen = [];
    $input = ['per_page' => 3];
    for ($page = 0; $page < 3; $page++) {
        $response = $this->getJson($this->url.'?'.http_build_query($input))->assertOk();
        $rows = $response->json('data');
        expect($rows)->toHaveCount($page === 2 ? 1 : 3);
        expect($response->json('meta.has_more'))->toBe($page < 2);
        expect($response->json('meta.per_page'))->toBe(3);
        $seen = [...$seen, ...array_column($rows, 'id')];
        if ($page < 2) {
            $input['cursor'] = $response->json('meta.next_cursor');
            $cursor = NotificationCursor::decode($input['cursor']);
            expect($cursor->id)->toBe($rows[array_key_last($rows)]['id']);
            expect($cursor->createdAt)->toBe($rows[array_key_last($rows)]['created_at']);
        } else {
            expect($response->json('meta.next_cursor'))->toBeNull();
        }
    }
    expect($seen)->toBe($expected);
    expect(array_unique($seen))->toHaveCount(7);
});

it('bounds fetches to per_page plus one with deliberate projection and all three SQL scope predicates', function () {
    $this->actingAs($this->owner); // Direct Application calls use the explicit actor, not ambient auth.
    for ($i = 0; $i < 101; $i++) {
        NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    }
    foreach ([[], ['per_page' => 1], ['per_page' => 100]] as $input) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = $this->list->handle($this->actor->id, $this->org->id, $input);
            $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"')));
        } finally {
            DB::disableQueryLog();
        }
        expect($page->notifications)->toHaveCount($input['per_page'] ?? 25);
        expect($page->hasMore)->toBeTrue();
        expect($queries)->toHaveCount(1);
        $sql = strtolower($queries[0]['query']);
        expect($sql)->toContain('where "organization_id" = ? and "recipient_user_id" = ? and "recipient_membership_id" = ?', 'order by "created_at" desc, "id" desc', 'limit '.($page->perPage + 1));
        foreach (['select *', 'offset', 'count(', 'join', '"payload"', '"users"'] as $forbidden) {
            expect($sql)->not->toContain($forbidden);
        }
        expect($queries[0]['bindings'])->toBe([$this->org->id, $this->actor->id, $this->member->id]);
    }
});

it('keeps cursor OR predicates grouped inside the complete scope including tied foreign rows', function () {
    $other = User::factory()->create();
    $otherMember = $this->org->memberships()->create(['user_id' => $other->id]);
    $b = OrganizationFixtures::unaudited($this->owner->id, 'Grouped scope B');
    $bMember = $b->memberships()->create(['user_id' => $this->actor->id]);
    $own = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    NotificationReadFixtures::insert($this->org->id, $other->id, $otherMember->id);
    NotificationReadFixtures::insert($b->id, $this->actor->id, $bMember->id);
    NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id + 100000);
    $cursor = new NotificationCursor('2026-10-01T00:00:00.123456Z', '7ZZZZZZZZZZZZZZZZZZZZZZZZZ');
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $page = $this->list->handle($this->actor->id, $this->org->id, ['cursor' => $cursor->encode()]);
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"')));
    } finally {
        DB::disableQueryLog();
    }
    expect(array_column($page->notifications, 'id'))->toBe([$own]);
    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('and ("created_at" < ? or ("created_at" = ? and "id" < ?))');
});

it('allows foreign tenant and recipient cursors to change position only', function () {
    $aIds = [];
    foreach (range(1, 2) as $i) {
        $aIds[] = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['created_at' => '2026-10-02T00:00:00.000000Z']);
    }
    $b = OrganizationFixtures::unaudited($this->owner->id, 'Cursor B');
    $bMember = $b->memberships()->create(['user_id' => $this->actor->id]);
    $bId = NotificationReadFixtures::insert($b->id, $this->actor->id, $bMember->id);
    $cursor = $this->list->handle($this->actor->id, $this->org->id, ['per_page' => 1])->nextCursor;
    $this->actingAs($this->actor)->getJson('/api/v1/organizations/'.$b->id.'/notifications?'.http_build_query(['cursor' => $cursor]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bId);
    $other = User::factory()->create();
    $otherMember = $this->org->memberships()->create(['user_id' => $other->id]);
    foreach (range(1, 2) as $i) {
        NotificationReadFixtures::insert($this->org->id, $other->id, $otherMember->id, ['created_at' => '2026-10-03T00:00:00.000000Z']);
    }
    $foreignCursor = $this->list->handle($other->id, $this->org->id, ['per_page' => 1])->nextCursor;
    $response = $this->getJson($this->url.'?'.http_build_query(['cursor' => $foreignCursor]))->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.*.id'))->toEqualCanonicalizing($aIds);
});

it('returns stored future type and version as data with private payload and canonical UTC projections', function () {
    $id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, [
        'type' => 'organization.future_notice', 'payload_version' => 2,
        'payload' => '{"private_marker":"never-expose-this","recipient_email":"private@example.test"}',
        'target_type' => null, 'created_at' => '2026-10-01T03:00:00.654321+03:00', 'read_at' => '2026-10-01T03:00:01.000001+03:00',
    ]);
    $response = $this->actingAs($this->actor)->getJson($this->url)->assertOk()->assertExactJson([
        'data' => [[
            'id' => $id, 'type' => 'organization.future_notice', 'payload_version' => 2,
            'title' => 'Invitation accepted', 'body' => 'User #29 accepted an invitation and joined the organization.',
            'target' => null, 'read_at' => '2026-10-01T00:00:01.000001Z', 'created_at' => '2026-10-01T00:00:00.654321Z',
        ]],
        'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25],
    ]);
    foreach (['"payload"', 'organization_id', 'recipient_user_id', 'recipient_membership_id', 'email', 'token', 'password', 'relationships', 'updated_at', 'never-expose-this', 'private@example.test'] as $private) {
        expect(str_contains($response->getContent(), $private))->toBeFalse();
    }
    expect(array_keys((array) $this->list->handle($this->actor->id, $this->org->id)->notifications[0]))
        ->toBe(['id', 'type', 'payloadVersion', 'title', 'body', 'targetType', 'targetId', 'readAt', 'createdAt']);
});

it('counts only unread rows in current organization recipient and membership era without joins or cache', function () {
    foreach (range(1, 3) as $i) {
        NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    }
    NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id, ['read_at' => '2026-10-01T00:00:01.000000Z']);
    NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id + 100000);
    $ownerMember = $this->org->memberships()->where('user_id', $this->owner->id)->sole();
    NotificationReadFixtures::insert($this->org->id, $this->owner->id, $ownerMember->id);
    $b = OrganizationFixtures::unaudited($this->owner->id, 'Count B');
    $bMember = $b->memberships()->create(['user_id' => $this->actor->id]);
    NotificationReadFixtures::insert($b->id, $this->actor->id, $bMember->id);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($this->count->handle($this->actor->id, $this->org->id))->toBe(3);
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"')));
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('count(*)', '"organization_id" = ? and "recipient_user_id" = ? and "recipient_membership_id" = ? and "read_at" is null');
    expect($queries[0]['bindings'])->toBe([$this->org->id, $this->actor->id, $this->member->id]);
    expect($queries[0]['query'])->not->toContain('join');
    $this->actingAs($this->actor)->getJson($this->url.'/unread-count')->assertOk()->assertExactJson(['data' => ['unread_count' => 3]]);
});

it('replaces real query failure without retaining SQL details and restores the test schema on rollback', function () {
    try {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE organization_notifications RENAME COLUMN title TO test_private_title');
            $this->list->handle($this->actor->id, $this->org->id);
        });
        test()->fail('Expected safe storage failure.');
    } catch (NotificationStorageFailed $failure) {
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Notification storage is unavailable.');
        expect(str_contains((string) $failure, 'select "id"') || str_contains((string) $failure, 'test_private_title'))->toBeFalse();
    }
    expect($this->list->handle($this->actor->id, $this->org->id)->notifications)->toBe([]);
});
