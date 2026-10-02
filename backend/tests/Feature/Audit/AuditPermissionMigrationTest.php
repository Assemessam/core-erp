<?php

use App\Modules\Identity\Infrastructure\Eloquent\Models\User;
use App\Modules\Organization\Application\Commands\CreateOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('reverses and reapplies only audit.view while preserving existing permissions and grants', function () {
    $previous = ['members.invite', 'members.view', 'organizations.update', 'roles.view'];
    $owner = User::factory()->create();
    $organization = app(CreateOrganization::class)->handle($owner->id, 'Permission migration');
    $role = $organization->roles()->create(['name' => 'Existing grants']);
    $role->permissions()->sync([...$previous, 'audit.view']);
    expect(DB::table('permissions')->orderBy('key')->pluck('key')->all())->toBe(['audit.view', ...$previous]);
    $migration = require database_path('migrations/2026_10_01_000003_add_audit_view_permission.php');
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $migration->down();
    expect(DB::table('permissions')->orderBy('key')->pluck('key')->all())->toBe($previous);
    expect($role->permissions()->orderBy('key')->pluck('key')->all())->toBe($previous);
    expect(DB::table('role_permission')->where('permission_key', 'audit.view')->exists())->toBeFalse();
    expect(fn () => DB::transaction(fn () => DB::table('permissions')->insert(['key' => 'audit.view'])))->toThrow(QueryException::class);
    $migration->up();
    expect(DB::table('permissions')->orderBy('key')->pluck('key')->all())->toBe(['audit.view', ...$previous]);
    $role->permissions()->attach('audit.view');
    expect($role->permissions()->orderBy('key')->pluck('key')->all())->toBe(['audit.view', ...$previous]);
    expect(fn () => DB::transaction(fn () => DB::table('permissions')->insert(['key' => 'audit.write'])))->toThrow(QueryException::class);
});
