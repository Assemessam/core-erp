<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->foreignId('owner_user_id')->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        // PostgreSQL checks this at commit, allowing the organization and its
        // owner membership to be inserted together in one transaction.
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_owner_membership_foreign FOREIGN KEY (id, owner_user_id) REFERENCES organization_memberships (organization_id, user_id) DEFERRABLE INITIALLY DEFERRED');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_owner_membership_foreign');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
