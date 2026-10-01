<?php

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Exceptions\AuditWriteFailed;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuditFixtures;

it('requires an active caller-owned PostgreSQL transaction before recording', function () {
    // No committed fixtures or cleanup: validation uses pure IDs and fails before INSERT.
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $count = DB::table('audit_events')->count();
    try {
        app(AuditRecorder::class)->record(AuditFixtures::entry());
        $this->fail('Expected transaction requirement.');
    } catch (AuditWriteFailed $failure) {
        expect($failure->category)->toBe('transaction_required');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Audit recording failed.');
    }
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::table('audit_events')->count())->toBe($count);
})->group('integration');

it('does not accept a stale Laravel transaction counter after an external PDO rollback', function () {
    DB::beginTransaction();
    try {
        DB::connection()->getPdo()->rollBack();
        expect(DB::transactionLevel())->toBe(1);
        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        try {
            app(AuditRecorder::class)->record(AuditFixtures::entry());
            $this->fail('Expected physical transaction check.');
        } catch (AuditWriteFailed $failure) {
            expect($failure->category)->toBe('transaction_required');
        }
    } finally {
        DB::rollBack();
    }
    expect(DB::transactionLevel())->toBe(0);
})->group('integration');
