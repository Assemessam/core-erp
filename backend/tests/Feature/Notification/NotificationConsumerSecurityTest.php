<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Notification\Application\Commands\MarkAllNotificationsRead;
use App\Modules\Notification\Application\Commands\MarkNotificationRead;
use App\Modules\Notification\Application\Contracts\NotificationOrganizationAccess;
use App\Modules\Notification\Application\Contracts\NotificationReader;
use App\Modules\Notification\Application\Data\NotificationCriteria;
use App\Modules\Notification\Application\Data\NotificationMembershipContext;
use App\Modules\Notification\Application\Data\NotificationPage;
use App\Modules\Notification\Application\Exceptions\NotificationNotFound;
use App\Modules\Notification\Application\Exceptions\NotificationQueryInvalid;
use App\Modules\Notification\Application\Exceptions\NotificationStorageFailed;
use App\Modules\Notification\Application\Queries\GetUnreadNotificationCount;
use App\Modules\Notification\Application\Queries\ListNotifications;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\NotificationReadFixtures;
use Tests\Support\OrganizationFixtures;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->org = OrganizationFixtures::unaudited($this->owner->id, 'Private notification security');
    $this->actor = User::factory()->create();
    $this->member = $this->org->memberships()->create(['user_id' => $this->actor->id]);
    $this->id = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id);
    $this->url = '/api/v1/organizations/'.$this->org->id.'/notifications';
});

it('enforces all four HTTP surfaces and direct Application access without recipient privilege for owners', function (string $kind, int $accessStatus, int $itemStatus) {
    $actor = $this->actor;
    $item = $this->id;
    if (in_array($kind, ['owner', 'owner-nonrecipient'])) {
        $actor = $this->owner;
        if ($kind === 'owner') {
            $membership = $this->org->memberships()->where('user_id', $actor->id)->sole();
            $item = NotificationReadFixtures::insert($this->org->id, $actor->id, $membership->id);
        }
    } elseif (in_array($kind, ['another-member', 'outsider', 'foreign'])) {
        $actor = User::factory()->create();
        if ($kind === 'another-member') {
            $this->org->memberships()->create(['user_id' => $actor->id]);
        } elseif ($kind === 'foreign') {
            OrganizationFixtures::unaudited($actor->id, 'Foreign membership');
        }
    } elseif ($kind === 'unverified') {
        $actor->forceFill(['email_verified_at' => null])->save();
    } elseif ($kind === 'suspended') {
        $this->member->update(['status' => 'suspended']);
    } elseif ($kind === 'removed') {
        $this->member->delete();
    }
    if ($kind !== 'guest') {
        $this->actingAs($actor);
    }
    $list = $this->getJson($this->url)->assertStatus($accessStatus);
    $count = $this->getJson($this->url.'/unread-count')->assertStatus($accessStatus);
    $this->postJson($this->url.'/'.$item.'/read')->assertStatus($itemStatus);
    $this->postJson($this->url.'/read-all')->assertStatus($accessStatus === 200 ? 204 : $accessStatus);
    if ($accessStatus === 200) {
        $ownsItem = $itemStatus === 204;
        $list->assertJsonCount($ownsItem ? 1 : 0, 'data');
        $count->assertJsonPath('data.unread_count', $ownsItem ? 1 : 0);
        expect(app(ListNotifications::class)->handle($actor->id, $this->org->id)->notifications)->toHaveCount($ownsItem ? 1 : 0);
        if (! $ownsItem) {
            expect(fn () => app(MarkNotificationRead::class)->handle($actor->id, $this->org->id, $this->id))->toThrow(NotificationNotFound::class);
        }
    } elseif ($accessStatus === 404) {
        foreach ([
            fn () => app(ListNotifications::class)->handle($actor->id, $this->org->id, ['cursor' => 'bad']),
            fn () => app(GetUnreadNotificationCount::class)->handle($actor->id, $this->org->id),
            fn () => app(MarkNotificationRead::class)->handle($actor->id, $this->org->id, $this->id),
            fn () => app(MarkAllNotificationsRead::class)->handle($actor->id, $this->org->id),
        ] as $operation) {
            expect($operation)->toThrow(AccessDenied::class);
        }
    }
    if ($item !== $this->id || $itemStatus !== 204) {
        expect(DB::table('organization_notifications')->where('id', $this->id)->sole()->read_at)->toBeNull();
    }
})->with([
    'guest' => ['guest', 401, 401],
    'unverified active member' => ['unverified', 403, 403],
    'active recipient without roles' => ['recipient', 200, 204],
    'another active member' => ['another-member', 200, 404],
    'owner recipient' => ['owner', 200, 204],
    'owner nonrecipient' => ['owner-nonrecipient', 200, 404],
    'suspended' => ['suspended', 404, 404],
    'removed' => ['removed', 404, 404],
    'nonmember' => ['outsider', 404, 404],
    'foreign organization owner' => ['foreign', 404, 404],
]);

it('hides inaccessible scope before each detailed invalid query in HTTP and Application', function (string $kind, array $input) {
    if ($kind === 'suspended') {
        $this->member->update(['status' => 'suspended']);
    } elseif ($kind === 'removed') {
        $this->member->delete();
    } else {
        $this->actor = User::factory()->create();
        if ($kind === 'foreign') {
            OrganizationFixtures::unaudited($this->actor->id, 'Foreign validation scope');
        }
    }
    $this->actingAs($this->actor)->getJson($this->url.'?'.http_build_query($input))->assertNotFound()->assertJsonMissingPath('errors');
    expect(fn () => app(ListNotifications::class)->handle($this->actor->id, $this->org->id, $input))->toThrow(AccessDenied::class);
})->with(['nonmember', 'suspended', 'removed', 'foreign'])->with([
    'malformed cursor' => [['cursor' => '**secret-input**']],
    'invalid page' => [['per_page' => 0]],
    'unsupported field' => [['recipient_membership_id' => 42]],
]);

it('returns fixed validation errors for authorized callers before any notification SELECT', function (array $input, string $field) {
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $response = $this->actingAs($this->actor)->getJson($this->url.'?'.http_build_query($input))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $queries = array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"organization_notifications"'));
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toBeEmpty();
    expect(str_contains($response->getContent(), 'secret-input'))->toBeFalse();
    expect(fn () => app(ListNotifications::class)->handle($this->actor->id, $this->org->id, $input))->toThrow(NotificationQueryInvalid::class);
})->with([
    'page zero' => [['per_page' => 0], 'per_page'],
    'page above bound' => [['per_page' => 101], 'per_page'],
    'page decimal' => [['per_page' => '1.5'], 'per_page'],
    'page array' => [['per_page' => [25]], 'per_page'],
    'cursor oversized' => [['cursor' => str_repeat('a', 257)], 'cursor'],
    'cursor malformed' => [['cursor' => '**secret-input**'], 'cursor'],
    'cursor JSON' => [['cursor' => 'YWJj'], 'cursor'],
    'cursor unknown fields' => [['cursor' => rtrim(strtr(base64_encode('{"v":1,"created_at":"2026-10-01T00:00:00.123456Z","id":"01AAAAAAAAAAAAAAAAAAAAAAAA","extra":1}'), '+/', '-_'), '=')], 'cursor'],
    'recipient override' => [['recipient_user_id' => 42], 'query'],
    'era override' => [['recipient_membership_id' => 42], 'query'],
    'unread filter' => [['unread' => 1], 'query'],
]);

it('keeps foreign recipient tenant historical era and nonexistent item IDs on one not-found surface', function () {
    $other = User::factory()->create();
    $otherMember = $this->org->memberships()->create(['user_id' => $other->id]);
    $anotherRecipient = NotificationReadFixtures::insert($this->org->id, $other->id, $otherMember->id);
    $b = OrganizationFixtures::unaudited($this->owner->id, 'Foreign item B');
    $bMember = $b->memberships()->create(['user_id' => $this->actor->id]);
    $anotherTenant = NotificationReadFixtures::insert($b->id, $this->actor->id, $bMember->id);
    $oldEra = NotificationReadFixtures::insert($this->org->id, $this->actor->id, $this->member->id + 100000);
    $this->actingAs($this->actor);
    foreach ([$anotherRecipient, $anotherTenant, $oldEra, (string) Str::ulid()] as $id) {
        $this->postJson($this->url.'/'.$id.'/read')->assertNotFound()->assertExactJson(['message' => 'Notification not found.']);
        expect(fn () => app(MarkNotificationRead::class)->handle($this->actor->id, $this->org->id, $id))->toThrow(NotificationNotFound::class);
    }
    expect(DB::table('organization_notifications')->whereIn('id', [$anotherRecipient, $anotherTenant, $oldEra])->whereNull('read_at')->count())->toBe(3);
    // An actor valid in both organizations still cannot mark an A item through B.
    $this->postJson('/api/v1/organizations/'.$b->id.'/notifications/'.$this->id.'/read')->assertNotFound()->assertExactJson(['message' => 'Notification not found.']);
    expect(DB::table('organization_notifications')->where('id', $this->id)->sole()->read_at)->toBeNull();
});

it('rejects inconsistent trusted contexts before all four consumer persistence paths', function (string $kind) {
    app()->instance(NotificationOrganizationAccess::class, new class($kind) implements NotificationOrganizationAccess
    {
        public function __construct(private readonly string $kind) {}

        public function resolveActiveMembership(int $userId, string $organizationId): NotificationMembershipContext
        {
            return new NotificationMembershipContext($this->kind === 'organization' ? '01AAAAAAAAAAAAAAAAAAAAAAAA' : $organizationId,
                $this->kind === 'user' ? $userId + 1 : $userId, $this->kind === 'membership' ? 0 : 47);
        }
    });
    foreach ([
        fn () => app(ListNotifications::class)->handle($this->actor->id, $this->org->id),
        fn () => app(GetUnreadNotificationCount::class)->handle($this->actor->id, $this->org->id),
        fn () => app(MarkNotificationRead::class)->handle($this->actor->id, $this->org->id, $this->id),
        fn () => app(MarkAllNotificationsRead::class)->handle($this->actor->id, $this->org->id),
    ] as $operation) {
        expect($operation)->toThrow(NotificationNotFound::class);
    }
    expect(DB::table('organization_notifications')->where('id', $this->id)->sole()->read_at)->toBeNull();
})->with(['organization', 'user', 'membership']);

it('renders safe consumer storage unavailability without framework SQL or debug details', function () {
    app()->instance(NotificationReader::class, new class implements NotificationReader
    {
        public function read(NotificationMembershipContext $context, NotificationCriteria $criteria): NotificationPage
        {
            throw new NotificationStorageFailed;
        }

        public function unreadCount(NotificationMembershipContext $context): int
        {
            throw new NotificationStorageFailed;
        }
    });
    foreach ([$this->url, $this->url.'/unread-count'] as $url) {
        $this->actingAs($this->actor)->getJson($url)->assertStatus(503)->assertExactJson(['message' => 'Notification storage is unavailable.']);
    }
});

it('registers exactly the four identifier-based routes without permissions capabilities or producer behavior', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), '/notifications'))->values();
    expect($routes)->toHaveCount(4);
    $expected = [
        'api/v1/organizations/{organization}/notifications' => ['GET', 'HEAD'],
        'api/v1/organizations/{organization}/notifications/unread-count' => ['GET', 'HEAD'],
        'api/v1/organizations/{organization}/notifications/read-all' => ['POST'],
        'api/v1/organizations/{organization}/notifications/{notification}/read' => ['POST'],
    ];
    foreach ($routes as $route) {
        expect($route->methods())->toBe($expected[$route->uri()]);
        expect($route->gatherMiddleware())->toContain('auth:sanctum', 'verified');
        expect($route->wheres)->toHaveKey('organization');
        if (str_contains($route->uri(), '{notification}')) {
            expect($route->wheres)->toHaveKey('notification');
        }
    }
    $beforeAudit = DB::table('audit_events')->count();
    $beforeNotifications = DB::table('organization_notifications')->where('organization_id', $this->org->id)->count();
    $this->actingAs($this->actor);
    foreach (['not-a-ulid', '8ZZZZZZZZZZZZZZZZZZZZZZZZZ'] as $id) {
        $this->getJson('/api/v1/organizations/'.$id.'/notifications?cursor=bad')->assertNotFound()->assertJsonMissingPath('errors');
        $this->postJson($this->url.'/'.$id.'/read')->assertNotFound();
        expect(fn () => app(MarkNotificationRead::class)->handle($this->actor->id, $this->org->id, $id))->toThrow(NotificationNotFound::class);
    }
    $this->getJson($this->url)->assertOk();
    $this->getJson($this->url.'/unread-count')->assertOk();
    $this->postJson($this->url.'/'.$this->id.'/read')->assertNoContent();
    $this->postJson($this->url.'/read-all')->assertNoContent();
    foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
        $this->$method($this->url)->assertStatus(405);
    }
    $this->getJson('/api/v1/organizations/'.$this->org->id)->assertJsonMissingPath('meta.can_view_notifications');
    expect(DB::table('permissions')->where('key', 'like', 'notifications.%')->count())->toBe(0);
    expect(DB::table('audit_events')->count())->toBe($beforeAudit);
    expect(DB::table('organization_notifications')->where('organization_id', $this->org->id)->count())->toBe($beforeNotifications);
});
