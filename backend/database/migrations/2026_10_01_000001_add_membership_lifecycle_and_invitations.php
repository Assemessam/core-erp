<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            $table->unique(['organization_id', 'user_id', 'status'], 'memberships_owner_status_unique');
        });
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('active', 'suspended'))");
        // Retain the original owner FK and strengthen it with a constant active status.
        DB::statement("ALTER TABLE organizations ADD COLUMN owner_membership_status varchar(16) NOT NULL DEFAULT 'active' CHECK (owner_membership_status = 'active')");
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_active_owner_foreign FOREIGN KEY (id, owner_user_id, owner_membership_status) REFERENCES organization_memberships (organization_id, user_id, status) DEFERRABLE INITIALLY DEFERRED');
        DB::statement('ALTER TABLE permissions DROP CONSTRAINT permissions_known_key');
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_known_key CHECK (key IN ('organizations.update', 'roles.view', 'members.view', 'members.invite'))");
        DB::table('permissions')->insert([['key' => 'members.view'], ['key' => 'members.invite']]);
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('inviter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('state', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id'], 'invitations_organization_id_unique');
        });
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT invitations_state_check CHECK (state IN ('pending', 'accepted', 'revoked'))");
        DB::statement('ALTER TABLE organization_invitations ADD CONSTRAINT invitations_email_normalized CHECK (email = lower(btrim(email)) AND length(email) > 0)');
        DB::statement("ALTER TABLE organization_invitations ADD CONSTRAINT invitations_hash_check CHECK (token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("CREATE UNIQUE INDEX invitations_pending_email_unique ON organization_invitations (organization_id, email) WHERE state = 'pending'");
        Schema::create('organization_invitation_role', function (Blueprint $table) {
            $table->ulid('organization_id');
            $table->ulid('organization_invitation_id');
            $table->ulid('role_id');
            $table->primary(['organization_invitation_id', 'role_id'], 'invitation_role_primary');
            $table->foreign(['organization_id', 'organization_invitation_id'], 'invitation_role_invitation_foreign')->references(['organization_id', 'id'])->on('organization_invitations')->cascadeOnDelete();
            $table->foreign(['organization_id', 'role_id'], 'invitation_role_role_foreign')->references(['organization_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->index(['organization_id', 'role_id']);
            $table->index(['organization_id', 'organization_invitation_id'], 'invitation_role_invitation_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitation_role');
        Schema::dropIfExists('organization_invitations');
        DB::table('role_permission')->whereIn('permission_key', ['members.view', 'members.invite'])->delete();
        DB::table('permissions')->whereIn('key', ['members.view', 'members.invite'])->delete();
        DB::statement('ALTER TABLE permissions DROP CONSTRAINT permissions_known_key');
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_known_key CHECK (key IN ('organizations.update', 'roles.view'))");
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_active_owner_foreign');
        DB::statement('ALTER TABLE organizations DROP COLUMN owner_membership_status');
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT memberships_owner_status_unique');
        DB::statement('ALTER TABLE organization_memberships DROP COLUMN status');
    }
};
