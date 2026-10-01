<?php

namespace App\Modules\Audit\Infrastructure\Persistence;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntry;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use App\Modules\Audit\Application\Validation\AuditPayloadValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DatabaseAuditRecorder implements AuditRecorder
{
    public function __construct(private readonly AuditPayloadValidator $validator) {}

    public function record(#[\SensitiveParameter] AuditEntry $entry): void
    {
        $this->validator->validate($entry);

        try {
            // Resolve the current default connection per call, never a separate audit connection.
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql') {
                throw new AuditWriteFailed('unsupported_connection');
            }
            if ($connection->transactionLevel() < 1 || ! $connection->getPdo()->inTransaction()) {
                throw new AuditWriteFailed('transaction_required');
            }
            $connection->table('audit_events')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $entry->organizationId,
                'actor_type' => $entry->actor->type->value,
                'actor_user_id' => $entry->actor->userId,
                'action' => $entry->action->value,
                'subject_type' => $entry->subject->type->value,
                'subject_id' => $entry->subject->id,
                'before' => $entry->before === null ? null : json_encode($entry->before, JSON_THROW_ON_ERROR),
                'after' => $entry->after === null ? null : json_encode($entry->after, JSON_THROW_ON_ERROR),
                'payload_version' => $entry->payloadVersion,
            ]);
        } catch (AuditWriteFailed $failure) {
            throw $failure;
        } catch (Throwable) {
            // QueryException and its previous exception include bindings. Do not retain either.
            throw new AuditWriteFailed('persistence_failed');
        }
    }
}
