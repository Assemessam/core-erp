<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('enforces active owner membership and existing uniqueness through raw PostgreSQL writes', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Constraints');
    $membership = $org->memberships()->sole();
    foreach (['suspend', 'remove', 'constant', 'duplicate', 'status'] as $attack) {
        expect(fn () => DB::transaction(function () use ($attack, $org, $membership): void {
            match ($attack) {
                'suspend' => DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'suspended']),
                'remove' => DB::table('organization_memberships')->where('id', $membership->id)->delete(),
                'constant' => DB::table('organizations')->where('id', $org->id)->update(['owner_membership_status' => 'suspended']),
                'duplicate' => DB::table('organization_memberships')->insert(['organization_id' => $org->id, 'user_id' => $membership->user_id]),
                'status' => DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'invented']),
            };
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }))->toThrow(QueryException::class);
    }
});

it('enforces invitation references normalization state pending uniqueness and tenant compatible grants', function () {
    $owner = User::factory()->create();
    $a = app(CreateOrganization::class)->handle($owner->id, 'A');
    $b = app(CreateOrganization::class)->handle($owner->id, 'B');
    $values = ['email' => 'alice@example.test', 'inviter_user_id' => $owner->id, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', 'one')];
    $invite = $a->invitations()->create($values);
    $foreign = $b->roles()->create(['name' => 'Foreign']);
    foreach ([$a->id, $b->id] as $tenant) {
        expect(fn () => DB::transaction(fn () => DB::table('organization_invitation_role')->insert(['organization_id' => $tenant, 'organization_invitation_id' => $invite->id, 'role_id' => $foreign->id])))->toThrow(QueryException::class);
    }
    foreach ([['organization_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'], ['inviter_user_id' => 999999999], ['email' => 'ALICE@example.test'], ['state' => 'expired'], ['token_hash' => 'plaintext'], []] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('organization_invitations')->insert(array_replace($values, ['id' => (string) Str::ulid(), 'organization_id' => $a->id, 'token_hash' => hash('sha256', 'two')], $invalid))))->toThrow(QueryException::class);
    }
    $role = $a->roles()->create(['name' => 'Local']);
    $invite->roles()->attach($role->id, ['organization_id' => $a->id]);
    expect(fn () => DB::transaction(fn () => DB::table('organization_invitations')->where('id', $invite->id)->update(['organization_id' => $b->id])))->toThrow(QueryException::class);
    $invite->delete();
    expect(DB::table('organization_invitation_role')->where('organization_invitation_id', $invite->id)->exists())->toBeFalse();
    expect($role->fresh())->not->toBeNull();
    expect($owner->fresh())->not->toBeNull();
    expect(DB::table('permissions')->whereIn('key', ['members.view', 'members.invite'])->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => DB::table('permissions')->insert(['key' => 'members.manage'])))->toThrow(QueryException::class);
});

it('backfills existing membership as active on lifecycle migration reapply', function () {
    $migration = require database_path('migrations/2026_10_01_000001_add_membership_lifecycle_and_invitations.php');
    $migration->down();
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Before lifecycle');
    $member = $org->memberships()->create(['user_id' => User::factory()->create()->id]);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $migration->up();
    DB::statement('SET CONSTRAINTS ALL DEFERRED');
    expect(DB::table('organization_memberships')->where('organization_id', $org->id)->pluck('status')->all())->toBe(['active', 'active']);
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
});

it('protects invitation pivot references uniqueness and intentional role cascades', function () {
    $owner = User::factory()->create();
    $org = app(CreateOrganization::class)->handle($owner->id, 'Invitation pivots');
    $invite = $org->invitations()->create(['email' => 'pivot@example.test', 'inviter_user_id' => $owner->id, 'expires_at' => now()->addDay(), 'token_hash' => hash('sha256', 'pivot')]);
    $role = $org->roles()->create(['name' => 'Selected']);
    $values = ['organization_id' => $org->id, 'organization_invitation_id' => $invite->id, 'role_id' => $role->id];
    DB::table('organization_invitation_role')->insert($values);
    foreach ([[], ['role_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA'], ['organization_invitation_id' => '01AAAAAAAAAAAAAAAAAAAAAAAA']] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('organization_invitation_role')->insert(array_replace($values, $invalid))))->toThrow(QueryException::class);
    }
    $role->delete();
    expect($invite->fresh())->not->toBeNull();
    expect($invite->roles()->count())->toBe(0);
});
