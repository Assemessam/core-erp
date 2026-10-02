<?php

use App\Modules\Audit\Application\Contracts\AuditHistoryAccess;
use App\Modules\Audit\Application\Data\AuditCursor;
use App\Modules\Audit\Application\Exceptions\AuditQueryInvalid;
use App\Modules\Audit\Application\Queries\ListAuditEvents;
use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Authorization\AccessDecision;
use App\Modules\Organization\Application\Authorization\AccessDenied;
use App\Modules\Organization\Application\Authorization\OrganizationAccess;
use App\Modules\Organization\Application\Commands\ActivateMembership;
use App\Modules\Organization\Application\Commands\CreateInvitation;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Application\Commands\SuspendMembership;
use App\Modules\Organization\Application\Operations\AssignMembershipRole;
use App\Modules\Organization\Infrastructure\Audit\OrganizationAuditHistoryAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->org = app(CreateOrganization::class)->handle($this->owner->id, 'History A');
    $this->url = '/api/v1/organizations/'.$this->org->id.'/audit-events';
    $this->list = app(ListAuditEvents::class);
});

function insertAuditReadFixture(string $organizationId, int $userId, string $time, ?string $subjectId = null): string
{
    $id = (string) Str::ulid();
    DB::table('audit_events')->insert([
        'id' => $id, 'organization_id' => $organizationId, 'actor_type' => 'user', 'actor_user_id' => $userId,
        'action' => 'membership.suspended', 'subject_type' => 'membership', 'subject_id' => $subjectId ?? (string) $userId,
        'before' => json_encode(['user_id' => $userId, 'status' => 'active'], JSON_THROW_ON_ERROR),
        'after' => json_encode(['user_id' => $userId, 'status' => 'suspended'], JSON_THROW_ON_ERROR),
        'payload_version' => 1, 'created_at' => $time,
    ]);

    return $id;
}

it('preserves middleware and persisted access outcomes in HTTP and direct calls', function (string $kind, int $status, string $outcome) {
    $actor = $kind === 'owner' ? $this->owner : User::factory()->create();
    if ($kind === 'unverified') {
        $actor->forceFill(['email_verified_at' => null])->save();
    }
    if (in_array($kind, ['reader', 'ordinary', 'suspended', 'other tenant'])) {
        $organization = $kind === 'other tenant' ? app(CreateOrganization::class)->handle($actor->id, 'Foreign') : $this->org;
        $membership = $organization->memberships()->firstOrCreate(['user_id' => $actor->id]);
        if ($kind !== 'ordinary') {
            $role = $organization->roles()->create(['name' => 'History reader']);
            $role->permissions()->sync(['audit.view']);
            app(AssignMembershipRole::class)->handle($membership, $role);
        }
        if ($kind === 'suspended') {
            $membership->update(['status' => 'suspended']);
        }
    }
    if ($kind !== 'guest') {
        $this->actingAs($actor);
    }
    $this->getJson($this->url)->assertStatus($status);
    // Authentication/verification are trusted adapter prerequisites, not duplicated Identity logic in Audit.
    if (! in_array($kind, ['guest', 'unverified'])) {
        expect(app(OrganizationAccess::class)->viewAuditHistory($actor->id, $this->org->id)->outcome)->toBe($outcome);
        if ($outcome === AccessDecision::ALLOWED) {
            expect($this->list->handle($actor->id, $this->org->id)->events)->toHaveCount(1);
            app(AuditHistoryAccess::class)->assertCanView($actor->id, $this->org->id);
        } else {
            foreach ([$this->list, app(AuditHistoryAccess::class)] as $entry) {
                try {
                    $entry instanceof ListAuditEvents
                        ? $entry->handle($actor->id, $this->org->id)
                        : $entry->assertCanView($actor->id, $this->org->id);
                    $this->fail('Expected denial.');
                } catch (AccessDenied $failure) {
                    expect($failure->decision->outcome)->toBe($outcome);
                }
            }
        }
    }
})->with([
    'guest' => ['guest', 401, AccessDecision::HIDDEN],
    'unverified' => ['unverified', 403, AccessDecision::HIDDEN],
    'owner without roles' => ['owner', 200, AccessDecision::ALLOWED],
    'audit reader' => ['reader', 200, AccessDecision::ALLOWED],
    'ordinary member' => ['ordinary', 403, AccessDecision::FORBIDDEN],
    'suspended reader' => ['suspended', 404, AccessDecision::HIDDEN],
    'nonmember' => ['outsider', 404, AccessDecision::HIDDEN],
    'other tenant owner' => ['other tenant', 404, AccessDecision::HIDDEN],
]);

it('hides inaccessible tenants before malformed query validation in both entry points', function (array $input) {
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->getJson($this->url.'?'.http_build_query($input))->assertNotFound()->assertJsonMissingPath('errors');
    expect(fn () => $this->list->handle($outsider->id, $this->org->id, $input))->toThrow(AccessDenied::class);
    $this->org->memberships()->create(['user_id' => $outsider->id]);
    $this->getJson($this->url.'?'.http_build_query($input))->assertForbidden()->assertJsonMissingPath('errors');
    expect(fn () => $this->list->handle($outsider->id, $this->org->id, $input))->toThrow(AccessDenied::class);
    $this->actingAs($this->owner)->getJson($this->url.'?'.http_build_query($input))->assertUnprocessable()->assertJsonStructure(['errors']);
    expect(fn () => $this->list->handle($this->owner->id, $this->org->id, $input))->toThrow(AuditQueryInvalid::class);
})->with([
    'cursor' => [['cursor' => 'not-a-cursor']],
    'action' => [['action' => 'secret.lookup']],
    'subject' => [['subject_type' => 'user', 'subject_id' => 'invalid']],
]);

it('hides missing and malformed organization identifiers', function () {
    $this->actingAs($this->owner);
    foreach (['not-a-ulid', '81AAAAAAAAAAAAAAAAAAAAAAAA', '01AAAAAAAAAAAAAAAAAAAAAAAA'] as $id) {
        $this->getJson('/api/v1/organizations/'.$id.'/audit-events?cursor=bad')->assertNotFound()->assertJsonMissingPath('errors');
        expect(fn () => $this->list->handle($this->owner->id, $id, ['cursor' => 'bad']))->toThrow(AccessDenied::class);
    }
});

it('uses the bound Organization adapter and fresh grants status and ownership without role names', function () {
    expect(app(AuditHistoryAccess::class))->toBeInstanceOf(OrganizationAuditHistoryAccess::class);
    $reader = User::factory()->create();
    $member = $this->org->memberships()->create(['user_id' => $reader->id]);
    $this->actingAs($this->owner);
    $created = $this->postJson('/api/v1/organizations/'.$this->org->id.'/roles', ['name' => 'Owner', 'permissions' => ['audit.view']])->assertCreated();
    $roleId = $created->json('data.id');
    $role = $this->org->roles()->findOrFail($roleId);
    app(AssignMembershipRole::class)->handle($member, $role);
    $member->load('roles.permissions');
    $this->actingAs($reader)->getJson($this->url)->assertOk();
    $this->getJson('/api/v1/organizations/'.$this->org->id)->assertJsonPath('meta.can_view_audit', true);
    app(SuspendMembership::class)->handle($this->owner->id, $this->org->id, $member->id);
    expect($member->roles()->count())->toBe(1);
    $this->getJson($this->url)->assertNotFound();
    $this->getJson('/api/v1/organizations/'.$this->org->id)->assertNotFound();
    app(ActivateMembership::class)->handle($this->owner->id, $this->org->id, $member->id);
    $this->getJson($this->url)->assertOk();
    $this->actingAs($this->owner)->patchJson('/api/v1/organizations/'.$this->org->id.'/roles/'.$roleId, ['name' => 'Owner', 'permissions' => []])->assertOk();
    $this->actingAs($reader)->getJson($this->url)->assertForbidden();
    expect(fn () => $this->list->handle($reader->id, $this->org->id))->toThrow(AccessDenied::class);
    $this->getJson('/api/v1/organizations/'.$this->org->id)->assertJsonPath('meta.can_view_audit', false);
    // Even a stale/spoofed owner attribute cannot change persisted authority.
    $this->org->owner_user_id = $reader->id;
    expect(app(OrganizationAccess::class)->viewAuditHistory($reader->id, $this->org->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    expect($this->org->roles()->count())->toBe(1); // No implicit Owner role was created.
});

it('adds show-only capability metadata without changing organization data or list contracts', function () {
    $this->actingAs($this->owner);
    $this->getJson('/api/v1/organizations/'.$this->org->id)->assertExactJson([
        'data' => ['id' => $this->org->id, 'name' => $this->org->name], 'meta' => ['can_view_audit' => true],
    ]);
    $this->getJson('/api/v1/organizations')->assertExactJson(['data' => [['id' => $this->org->id, 'name' => $this->org->name]]]);
    $ordinary = User::factory()->create();
    $this->org->memberships()->create(['user_id' => $ordinary->id]);
    $this->actingAs($ordinary)->getJson('/api/v1/organizations/'.$this->org->id)->assertJsonPath('meta.can_view_audit', false);
    $this->getJson($this->url)->assertForbidden();
    $outsider = User::factory()->create();
    $this->actingAs($outsider)->getJson('/api/v1/organizations/'.$this->org->id)->assertNotFound();
});

it('paginates three timestamp-tied pages with no omissions or duplication', function () {
    $ids = [];
    foreach (['2026-10-02T00:00:00.123456Z', '2026-10-02T00:00:00.123456Z', '2026-10-02T00:00:00.123456Z', '2026-10-01T12:00:00.000001Z', '2026-10-01T12:00:00.000001Z', '2026-09-30T00:00:00.000000Z', '2026-09-29T00:00:00.000000Z'] as $time) {
        $ids[] = insertAuditReadFixture($this->org->id, $this->owner->id, $time);
    }
    $expected = DB::table('audit_events')->whereIn('id', $ids)->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
    $this->actingAs($this->owner);
    $seen = [];
    $input = ['per_page' => 3, 'action' => 'membership.suspended'];
    for ($page = 0; $page < 3; $page++) {
        $response = $this->getJson($this->url.'?'.http_build_query($input))->assertOk();
        expect($response->json('data'))->toHaveCount($page === 2 ? 1 : 3);
        expect($response->json('meta.has_more'))->toBe($page < 2);
        expect($response->json('meta.per_page'))->toBe(3);
        $events = $response->json('data');
        $seen = [...$seen, ...array_column($events, 'id')];
        if ($page < 2) {
            $input['cursor'] = $response->json('meta.next_cursor');
            $cursor = AuditCursor::decode($input['cursor']);
            expect($cursor->id)->toBe($events[array_key_last($events)]['id']);
            expect($cursor->createdAt)->toBe($events[array_key_last($events)]['created_at']);
        } else {
            expect($response->json('meta.next_cursor'))->toBeNull();
        }
    }
    expect($seen)->toBe($expected);
    expect(array_unique($seen))->toHaveCount(7);
});

it('bounds page sizes and explicitly projects one tenant query without counts offsets or joins', function () {
    for ($i = 0; $i < 101; $i++) {
        insertAuditReadFixture($this->org->id, $this->owner->id, '2026-10-02T00:00:00.123456Z');
    }
    foreach ([[], ['per_page' => 1], ['per_page' => 100]] as $input) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = $this->list->handle($this->owner->id, $this->org->id, $input);
        $queries = array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"audit_events"')));
        DB::disableQueryLog();
        expect($page->events)->toHaveCount($input['per_page'] ?? 25);
        expect($page->hasMore)->toBeTrue();
        expect($queries)->toHaveCount(1);
        $sql = strtolower($queries[0]['query']);
        expect($sql)->toContain('where "organization_id" = ?', 'order by "created_at" desc, "id" desc', 'limit '.($page->perPage + 1));
        foreach (['select *', 'offset', 'count(', 'join', 'users'] as $forbidden) {
            expect($sql)->not->toContain($forbidden);
        }
        expect($queries[0]['bindings'][0])->toBe($this->org->id);
    }
});

it('scopes cursor replay and subject filters when an actor owns both tenants', function () {
    $b = app(CreateOrganization::class)->handle($this->owner->id, 'History B');
    insertAuditReadFixture($this->org->id, $this->owner->id, '2026-10-03T00:00:00.000000Z', '111');
    insertAuditReadFixture($this->org->id, $this->owner->id, '2026-10-02T00:00:00.000000Z', '111');
    $bId = insertAuditReadFixture($b->id, $this->owner->id, '2026-10-01T00:00:00.000000Z', '222');
    $this->actingAs($this->owner);
    $cursor = $this->getJson($this->url.'?per_page=1&action=membership.suspended')->assertOk()->json('meta.next_cursor');
    $bUrl = '/api/v1/organizations/'.$b->id.'/audit-events';
    $this->getJson($bUrl.'?'.http_build_query(['cursor' => $cursor, 'action' => 'membership.suspended']))->assertOk()->assertJsonPath('data.0.id', $bId)->assertJsonCount(1, 'data');
    $this->getJson($bUrl.'?subject_type=membership&subject_id=111')->assertOk()->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25]]);
    $this->getJson($bUrl.'?subject_type=organization&subject_id='.$this->org->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($bUrl.'?action=membership.suspended&subject_type=membership&subject_id=222')->assertOk()->assertJsonPath('data.0.id', $bId)->assertJsonCount(1, 'data');
});

it('does not carry a tenant role permission to another membership', function () {
    $reader = User::factory()->create();
    $b = app(CreateOrganization::class)->handle($this->owner->id, 'History B');
    $aMember = $this->org->memberships()->create(['user_id' => $reader->id]);
    $b->memberships()->create(['user_id' => $reader->id]);
    $role = $this->org->roles()->create(['name' => 'Reader']);
    $role->permissions()->sync(['audit.view']);
    app(AssignMembershipRole::class)->handle($aMember, $role);
    $this->actingAs($reader)->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.id', $this->org->id);
    $this->getJson('/api/v1/organizations/'.$b->id.'/audit-events')->assertForbidden();
});

it('returns credential-free actual invitation history and system actors with deliberate fields', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $invitee = User::factory()->create();
    $invite = app(CreateInvitation::class)->handle($this->owner->id, $this->org->id, $invitee->email, []);
    $response = $this->actingAs($this->owner)->getJson($this->url.'?action=invitation.created')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->url.'?'.http_build_query(['subject_type' => 'invitation', 'subject_id' => $invite->id]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject.id', $invite->id);
    $event = $response->json('data.0');
    expect(array_keys($event))->toBe(['id', 'action', 'actor', 'subject', 'changes', 'payload_version', 'created_at']);
    expect($event['actor'])->toBe(['type' => 'user', 'id' => $this->owner->id]);
    expect($event['subject'])->toBe(['type' => 'invitation', 'id' => $invite->id]);
    expect($event['changes'])->toEqual(['before' => null, 'after' => ['state' => 'pending', 'expires_at' => $invite->expires_at->utc()->format('Y-m-d\TH:i:s.u\Z'), 'role_ids' => []]]);
    expect($event['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    $json = $response->getContent();
    // Boolean checks avoid printing credentials if the regression fails.
    expect(str_contains($json, $invitee->email) || str_contains($json, $this->owner->email) || str_contains($json, $invite->token_hash))->toBeFalse();
    foreach (['organization_id', 'token', 'password', 'email', 'updated_at', 'owner', 'relations'] as $field) {
        expect(str_contains($json, '"'.$field.'"'))->toBeFalse();
    }
    DB::table('audit_events')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $this->org->id, 'actor_type' => 'system', 'actor_user_id' => null,
        'action' => 'organization.renamed', 'subject_type' => 'organization', 'subject_id' => $this->org->id,
        'before' => '{"name":"Before"}', 'after' => '{"name":"After"}', 'payload_version' => 2,
    ]);
    $this->getJson($this->url.'?action=organization.renamed')->assertOk()->assertJsonPath('data.0.actor', ['type' => 'system', 'id' => null])->assertJsonPath('data.0.payload_version', 2)->assertJsonPath('data.0.changes', ['before' => ['name' => 'Before'], 'after' => ['name' => 'After']]);
});

it('exposes only a read route and does not create facts when reading', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'audit-events'))->values();
    expect($routes)->toHaveCount(1);
    expect($routes[0]->methods())->toBe(['GET', 'HEAD']);
    expect($routes[0]->gatherMiddleware())->toContain('auth:sanctum', 'verified');
    $before = DB::table('audit_events')->where('organization_id', $this->org->id)->count();
    $this->actingAs($this->owner)->getJson($this->url)->assertOk();
    foreach (['postJson', 'patchJson', 'putJson', 'deleteJson'] as $method) {
        $this->$method($this->url, ['action' => 'organization.renamed'])->assertStatus(405);
    }
    expect(DB::table('audit_events')->where('organization_id', $this->org->id)->count())->toBe($before);
});

function auditHttpCursor(array $replace): string
{
    return rtrim(strtr(base64_encode(json_encode(array_replace([
        'v' => 1, 'created_at' => '2026-10-01T00:00:00.123456Z', 'id' => '01AAAAAAAAAAAAAAAAAAAAAAAA',
    ], $replace), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
}

it('rejects invalid HTTP query values after authorization without reading audit storage', function (array $input, string $field) {
    $this->actingAs($this->owner);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson($this->url.'?'.http_build_query($input))->assertUnprocessable()->assertJsonValidationErrors($field);
    $reads = array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"audit_events"'));
    DB::disableQueryLog();
    expect($reads)->toBeEmpty();
})->with([
    'size zero' => [['per_page' => 0], 'per_page'],
    'size max exceeded' => [['per_page' => 101], 'per_page'],
    'size decimal' => [['per_page' => '1.5'], 'per_page'],
    'size array' => [['per_page' => [1]], 'per_page'],
    'size empty' => [['per_page' => ''], 'per_page'],
    'cursor oversized' => [['cursor' => str_repeat('a', 257)], 'cursor'],
    'cursor bad alphabet' => [['cursor' => '**'], 'cursor'],
    'cursor empty' => [['cursor' => ''], 'cursor'],
    'cursor malformed JSON' => [['cursor' => (new AuditCursor('2026-10-01T00:00:00.123456Z', '01AAAAAAAAAAAAAAAAAAAAAAAA'))->encode().'x'], 'cursor'],
    'cursor version' => [['cursor' => auditHttpCursor(['v' => 2])], 'cursor'],
    'cursor version type' => [['cursor' => auditHttpCursor(['v' => '1'])], 'cursor'],
    'cursor timestamp format' => [['cursor' => auditHttpCursor(['created_at' => '2026-10-01T00:00:00Z'])], 'cursor'],
    'cursor calendar' => [['cursor' => auditHttpCursor(['created_at' => '2026-02-30T00:00:00.000000Z'])], 'cursor'],
    'cursor PG year zero' => [['cursor' => auditHttpCursor(['created_at' => '0000-10-01T00:00:00.000000Z'])], 'cursor'],
    'cursor ULID' => [['cursor' => auditHttpCursor(['id' => 'bad-id'])], 'cursor'],
    'cursor unknown fields' => [['cursor' => auditHttpCursor(['extra' => 'ignored?'])], 'cursor'],
    'cursor direction' => [['cursor' => auditHttpCursor(['direction' => 'previous'])], 'cursor'],
    'action' => [['action' => 'unsupported.action'], 'action'],
    'action array' => [['action' => ['role.created']], 'action'],
    'type only' => [['subject_type' => 'role'], 'subject'],
    'id only' => [['subject_id' => '1'], 'subject'],
    'subject type' => [['subject_type' => 'user', 'subject_id' => '1'], 'subject_type'],
    'subject ULID' => [['subject_type' => 'invitation', 'subject_id' => 'not-an-id'], 'subject_id'],
    'subject bigint overflow' => [['subject_type' => 'membership', 'subject_id' => '9223372036854775808'], 'subject_id'],
    'unknown parameter' => [['actor_user_id' => 1], 'query'],
]);

it('filters role and invitation subjects exactly and applies a cursor within that filtered set', function () {
    $this->actingAs($this->owner);
    $role = $this->postJson('/api/v1/organizations/'.$this->org->id.'/roles', ['name' => 'Reader', 'permissions' => ['audit.view']])->assertCreated()->json('data.id');
    $this->patchJson('/api/v1/organizations/'.$this->org->id.'/roles/'.$role, ['name' => 'Reader updated', 'permissions' => ['audit.view', 'roles.view']])->assertOk();
    $first = $this->getJson($this->url.'?'.http_build_query(['subject_type' => 'role', 'subject_id' => $role, 'per_page' => 1]))->assertOk()->assertJsonPath('data.0.action', 'role.updated')->assertJsonPath('data.0.changes.after.permissions', ['audit.view', 'roles.view']);
    $this->getJson($this->url.'?'.http_build_query(['subject_type' => 'role', 'subject_id' => $role, 'per_page' => 1, 'cursor' => $first->json('meta.next_cursor')]))->assertOk()->assertJsonPath('data.0.action', 'role.created')->assertJsonPath('meta.next_cursor', null);
    $this->getJson($this->url.'?action=membership.removed')->assertOk()->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null, 'has_more' => false, 'per_page' => 25]]);
});

it('uses fresh persisted ownership and hides even a transiently missing owner membership', function () {
    $successor = User::factory()->create();
    $membership = $this->org->memberships()->create(['user_id' => $successor->id]);
    $this->org->fresh()->update(['owner_user_id' => $successor->id]);
    expect(app(OrganizationAccess::class)->viewAuditHistory($this->owner->id, $this->org->id)->outcome)->toBe(AccessDecision::FORBIDDEN);
    expect($this->list->handle($successor->id, $this->org->id)->events)->toHaveCount(1);
    // The deferred owner FK makes committing this impossible; it is rolled back with the test.
    $membership->delete();
    expect(app(OrganizationAccess::class)->viewAuditHistory($successor->id, $this->org->id)->outcome)->toBe(AccessDecision::HIDDEN);
    expect(fn () => $this->list->handle($successor->id, $this->org->id))->toThrow(AccessDenied::class);
});

it('preserves empty stored snapshot objects for future payload versions', function () {
    DB::table('audit_events')->insert([
        'id' => (string) Str::ulid(), 'organization_id' => $this->org->id, 'actor_type' => 'system', 'actor_user_id' => null,
        'action' => 'organization.renamed', 'subject_type' => 'organization', 'subject_id' => $this->org->id,
        'before' => '{}', 'after' => '{"name":"After"}', 'payload_version' => 2,
    ]);
    $response = $this->actingAs($this->owner)->getJson($this->url.'?action=organization.renamed')->assertOk();
    expect(str_contains($response->getContent(), '"before":{}'))->toBeTrue();
    $response->assertJsonPath('data.0.payload_version', 2);
});
