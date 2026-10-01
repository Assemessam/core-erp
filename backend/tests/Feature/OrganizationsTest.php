<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\Organization;
use App\Modules\Organization\Infrastructure\Eloquent\Models\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createOrganizationFor(User $owner, string $name = 'Acme'): Organization
{
    return app(CreateOrganization::class)->handle($owner->id, $name);
}

it('requires authentication for every organization endpoint', function () {
    $organization = createOrganizationFor(User::factory()->create());

    $this->getJson('/api/v1/organizations')->assertUnauthorized();
    $this->postJson('/api/v1/organizations', ['name' => 'New'])->assertUnauthorized();
    $this->getJson('/api/v1/organizations/'.$organization->id)->assertUnauthorized();
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'New'])->assertUnauthorized();
});

it('requires a verified email for organization operations', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $this->getJson('/api/v1/organizations')->assertForbidden();
    $this->postJson('/api/v1/organizations', ['name' => 'New'])->assertForbidden();
    expect(Organization::query()->where('owner_user_id', $user->id)->count())->toBe(0);
});

it('creates an organization and its owner membership without accepting owner spoofing', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($owner);

    $this->postJson('/api/v1/organizations', [
        'name' => 'Spoofed', 'owner_user_id' => $other->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
    $response = $this->postJson('/api/v1/organizations', ['name' => 'Northwind'])
        ->assertCreated();

    $organization = Organization::query()->where('owner_user_id', $owner->id)->sole();
    expect(Str::isUlid($organization->id))->toBeTrue();
    expect($organization->owner_user_id)->toBe($owner->id);
    expect($response->json('data.id'))->toBe($organization->id);
    $response->assertExactJson(['data' => ['id' => $organization->id, 'name' => 'Northwind']]);
    $this->assertDatabaseHas('organization_memberships', [
        'organization_id' => $organization->id, 'user_id' => $owner->id,
    ]);
    expect(OrganizationMembership::query()->where('organization_id', $organization->id)->count())->toBe(1);
});

it('rolls back organization creation if membership insertion fails', function () {
    $owner = User::factory()->create();
    OrganizationMembership::creating(function (): void {
        throw new RuntimeException('Simulated membership write failure');
    });

    try {
        expect(fn () => createOrganizationFor($owner))->toThrow(RuntimeException::class);
    } finally {
        OrganizationMembership::flushEventListeners();
    }

    expect(Organization::query()->where('owner_user_id', $owner->id)->count())->toBe(0);
    expect(OrganizationMembership::query()->where('user_id', $owner->id)->count())->toBe(0);
});

it('rejects invalid names', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $this->postJson('/api/v1/organizations', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/organizations', ['name' => str_repeat('a', 256)])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(Organization::query()->where('owner_user_id', $owner->id)->count())->toBe(0);
});

it('lists only memberships and hides unrelated organizations', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $a = createOrganizationFor($alice, 'Alpha');
    $b = createOrganizationFor($alice, 'Beta');
    $c = createOrganizationFor($bob, 'Gamma');

    $this->actingAs($alice);
    $this->getJson('/api/v1/organizations')->assertOk()->assertExactJson(['data' => [
        ['id' => $a->id, 'name' => 'Alpha'], ['id' => $b->id, 'name' => 'Beta'],
    ]]);
    $this->getJson('/api/v1/organizations/'.$a->id)->assertOk()->assertExactJson(['data' => [
        'id' => $a->id, 'name' => 'Alpha',
    ]]);
    $this->getJson('/api/v1/organizations/'.$c->id)->assertNotFound();
    $this->getJson('/api/v1/organizations/01AAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    $this->patchJson('/api/v1/organizations/'.$c->id, ['name' => ''])->assertNotFound();

    $this->actingAs($bob);
    $this->getJson('/api/v1/organizations')->assertOk()->assertExactJson(['data' => [
        ['id' => $c->id, 'name' => 'Gamma'],
    ]]);
    $this->getJson('/api/v1/organizations/'.$a->id)->assertNotFound();
});

it('allows members to view, only owners to update, and hides non-members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = createOrganizationFor($owner);
    $organization->memberships()->create(['user_id' => $member->id]);

    $this->actingAs($member);
    $this->getJson('/api/v1/organizations/'.$organization->id)->assertOk();
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Changed'])->assertForbidden();
    expect($organization->fresh()->name)->toBe('Acme');

    $this->actingAs($outsider);
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Changed'])->assertNotFound();

    $this->actingAs($owner);
    $this->patchJson('/api/v1/organizations/'.$organization->id, [
        'name' => 'Spoofed', 'owner_user_id' => $member->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
    $this->patchJson('/api/v1/organizations/'.$organization->id, ['name' => 'Updated'])
        ->assertOk()->assertExactJson(['data' => ['id' => $organization->id, 'name' => 'Updated']]);
    expect($organization->fresh()->owner_user_id)->toBe($owner->id);
});

it('enforces membership uniqueness and foreign keys in PostgreSQL', function () {
    $owner = User::factory()->create();
    $organization = createOrganizationFor($owner);

    foreach ([
        ['organization_memberships', ['organization_id' => $organization->id, 'user_id' => $owner->id]],
        ['organizations', ['id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'name' => 'Invalid', 'owner_user_id' => 99999999]],
        ['organization_memberships', ['organization_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA', 'user_id' => $owner->id]],
        ['organization_memberships', ['organization_id' => $organization->id, 'user_id' => 99999999]],
    ] as [$table, $values]) {
        // A savepoint lets PostgreSQL recover after each expected constraint error.
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert($values)))
            ->toThrow(QueryException::class);
    }

    expect(OrganizationMembership::query()->where('organization_id', $organization->id)->count())->toBe(1);
    expect(fn () => DB::transaction(function () use ($organization, $owner): void {
        DB::table('organization_memberships')->where('organization_id', $organization->id)
            ->where('user_id', $owner->id)->delete();
        DB::statement('SET CONSTRAINTS organizations_owner_membership_foreign IMMEDIATE');
    }))->toThrow(QueryException::class);
    expect(OrganizationMembership::query()->where('organization_id', $organization->id)->count())->toBe(1);
    $indexes = DB::select("select indexname from pg_indexes where schemaname = current_schema() and tablename = 'organization_memberships'");
    $names = array_map(fn ($index) => $index->indexname, $indexes);
    expect($names)->toContain('organization_memberships_organization_id_user_id_unique');
    expect($names)->toContain('organization_memberships_user_id_index');
    $organizationIndexes = DB::select("select indexname from pg_indexes where schemaname = current_schema() and tablename = 'organizations'");
    expect(array_map(fn ($index) => $index->indexname, $organizationIndexes))
        ->toContain('organizations_owner_user_id_index');
});
