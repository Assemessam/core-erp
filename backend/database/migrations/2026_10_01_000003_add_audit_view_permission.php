<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE permissions DROP CONSTRAINT permissions_known_key');
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_known_key CHECK (key IN ('organizations.update', 'roles.view', 'members.view', 'members.invite', 'audit.view'))");
        DB::table('permissions')->insert(['key' => 'audit.view']);
    }

    public function down(): void
    {
        DB::table('role_permission')->where('permission_key', 'audit.view')->delete();
        DB::table('permissions')->where('key', 'audit.view')->delete();
        DB::statement('ALTER TABLE permissions DROP CONSTRAINT permissions_known_key');
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_known_key CHECK (key IN ('organizations.update', 'roles.view', 'members.view', 'members.invite'))");
    }
};
