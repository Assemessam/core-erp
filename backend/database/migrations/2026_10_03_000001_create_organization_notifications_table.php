<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_notifications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete()->restrictOnUpdate();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete()->restrictOnUpdate();
            // Historical security scope: a live FK would block removal or erase/change this era.
            $table->bigInteger('recipient_membership_id');
            $table->string('type', 80);
            $table->smallInteger('payload_version');
            $table->jsonb('payload');
            $table->string('title', 160);
            $table->string('body', 1000);
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->timestampTz('read_at', 6)->nullable();
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->index('recipient_user_id', 'notifications_recipient_user_index');
        });
        // Frozen structural vocabulary; exact type/version payload schemas remain Application-owned.
        DB::statement(<<<'SQL'
            ALTER TABLE organization_notifications
                ADD CONSTRAINT notifications_id_check CHECK (id ~* '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
                ADD CONSTRAINT notifications_organization_id_check CHECK (organization_id ~* '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
                ADD CONSTRAINT notifications_recipient_user_check CHECK (recipient_user_id > 0),
                ADD CONSTRAINT notifications_recipient_membership_check CHECK (recipient_membership_id > 0),
                ADD CONSTRAINT notifications_type_check CHECK (type ~ '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$'),
                ADD CONSTRAINT notifications_payload_version_check CHECK (payload_version > 0),
                ADD CONSTRAINT notifications_payload_object_check CHECK (jsonb_typeof(payload) = 'object'),
                ADD CONSTRAINT notifications_payload_size_check CHECK (octet_length(payload::text) <= 8192),
                ADD CONSTRAINT notifications_title_check CHECK (title ~ '[^[:space:]]'),
                ADD CONSTRAINT notifications_body_check CHECK (body ~ '[^[:space:]]'),
                ADD CONSTRAINT notifications_target_check CHECK (
                    (target_type IS NULL AND target_id IS NULL)
                    OR (target_type IS NOT NULL AND target_type = 'organization.users' AND target_id IS NULL)
                ),
                ADD CONSTRAINT notifications_read_at_check CHECK (read_at IS NULL OR read_at >= created_at)
            SQL);
        DB::statement('CREATE INDEX notifications_recipient_chronology_index ON organization_notifications (organization_id, recipient_user_id, recipient_membership_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX notifications_recipient_unread_index ON organization_notifications (organization_id, recipient_user_id, recipient_membership_id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        // Destructive to notification history; normal code rollback should retain populated storage.
        Schema::dropIfExists('organization_notifications');
    }
};
