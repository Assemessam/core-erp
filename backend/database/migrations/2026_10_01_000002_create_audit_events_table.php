<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->restrictOnDelete()->restrictOnUpdate();
            $table->string('actor_type', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('action', 80);
            $table->string('subject_type', 32);
            $table->string('subject_id', 64);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->smallInteger('payload_version');
            $table->timestampTz('created_at', 6)->default(DB::raw('clock_timestamp()'));
            $table->index('actor_user_id');
        });

        // Frozen structural vocabulary; evolving action payload schemas remain Application-owned.
        DB::statement(<<<'SQL'
            ALTER TABLE audit_events
                ADD CONSTRAINT audit_events_id_check CHECK (id ~* '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
                ADD CONSTRAINT audit_events_organization_id_check CHECK (organization_id ~* '^[0-7][0-9A-HJKMNP-TV-Z]{25}$'),
                ADD CONSTRAINT audit_events_actor_check CHECK (
                    (actor_type = 'user' AND actor_user_id IS NOT NULL AND actor_user_id > 0)
                    OR (actor_type = 'system' AND actor_user_id IS NULL)
                ),
                ADD CONSTRAINT audit_events_action_check CHECK (action ~ '^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$'),
                ADD CONSTRAINT audit_events_subject_type_check CHECK (subject_type IN ('organization', 'role', 'invitation', 'membership')),
                ADD CONSTRAINT audit_events_subject_id_check CHECK (
                    (subject_type = 'membership' AND subject_id ~ '^[1-9][0-9]{0,18}$' AND
                        (length(subject_id) < 19 OR subject_id COLLATE "C" <= '9223372036854775807' COLLATE "C"))
                    OR (subject_type <> 'membership' AND subject_id ~* '^[0-7][0-9A-HJKMNP-TV-Z]{25}$')
                ),
                ADD CONSTRAINT audit_events_payload_version_check CHECK (payload_version > 0),
                ADD CONSTRAINT audit_events_snapshots_check CHECK (
                    ("before" IS NULL OR jsonb_typeof("before") = 'object')
                    AND ("after" IS NULL OR jsonb_typeof("after") = 'object')
                    AND ("before" IS NOT NULL OR "after" IS NOT NULL)
                ),
                ADD CONSTRAINT audit_events_payload_size_check CHECK (
                    coalesce(octet_length("before"::text), 0) + coalesce(octet_length("after"::text), 0) <= 65536
                )
            SQL);
        DB::statement('CREATE INDEX audit_events_organization_chronology_index ON audit_events (organization_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX audit_events_organization_action_index ON audit_events (organization_id, action, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX audit_events_organization_subject_index ON audit_events (organization_id, subject_type, subject_id, created_at DESC, id DESC)');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_audit_events_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only' USING ERRCODE = '55000';
            END;
            $$;
            CREATE TRIGGER audit_events_reject_update_delete
                BEFORE UPDATE OR DELETE ON audit_events FOR EACH STATEMENT
                EXECUTE FUNCTION reject_audit_events_mutation();
            CREATE TRIGGER audit_events_reject_truncate
                BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT
                EXECUTE FUNCTION reject_audit_events_mutation();
            SQL);
    }

    public function down(): void
    {
        // Mechanically reversible, but destructive to history; retain storage on production code rollback.
        DB::statement('DROP TRIGGER IF EXISTS audit_events_reject_update_delete ON audit_events');
        DB::statement('DROP TRIGGER IF EXISTS audit_events_reject_truncate ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_events_mutation()');
        Schema::dropIfExists('audit_events');
    }
};
