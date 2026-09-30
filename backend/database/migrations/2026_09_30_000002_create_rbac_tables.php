<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['organization_id', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX roles_organization_name_unique ON roles (organization_id, lower(btrim(name)))');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_name_nonempty CHECK (length(btrim(name)) > 0)');

        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key', 80)->primary();
        });
        // Frozen migration data: future capabilities require an explicit migration.
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_known_key CHECK (key IN ('organizations.update', 'roles.view'))");
        DB::table('permissions')->insert([
            ['key' => 'organizations.update'],
            ['key' => 'roles.view'],
        ]);
        Schema::create('role_permission', function (Blueprint $table) {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 80);
            $table->foreign('permission_key')->references('key')->on('permissions')->restrictOnDelete();
            $table->primary(['role_id', 'permission_key']);
            $table->index('permission_key');
        });
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->unique(['organization_id', 'id'], 'memberships_organization_id_unique');
        });
        Schema::create('organization_membership_role', function (Blueprint $table) {
            $table->ulid('organization_id');
            $table->unsignedBigInteger('organization_membership_id');
            $table->ulid('role_id');
            $table->primary(['organization_membership_id', 'role_id']);
            $table->foreign(['organization_id', 'organization_membership_id'], 'membership_role_membership_foreign')
                ->references(['organization_id', 'id'])->on('organization_memberships')->cascadeOnDelete();
            $table->foreign(['organization_id', 'role_id'], 'membership_role_role_foreign')
                ->references(['organization_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->index(['organization_id', 'role_id']);
            $table->index(['organization_id', 'organization_membership_id'], 'membership_role_membership_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_membership_role');
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->dropUnique('memberships_organization_id_unique');
        });
        Schema::dropIfExists('role_permission');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
