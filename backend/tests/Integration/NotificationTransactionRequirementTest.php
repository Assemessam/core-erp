<?php

use App\Modules\Notification\Application\Contracts\NotificationPublisher;
use App\Modules\Notification\Application\Exceptions\NotificationWriteFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NotificationFixtures;

it('requires a physical caller-owned PostgreSQL transaction before resolving or publishing', function () {
    // Pure IDs: guard rejects before membership queries, without any committed fixtures.
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    $draft = NotificationFixtures::draft(['organizationId' => (string) Str::ulid()]);
    try {
        app(NotificationPublisher::class)->publish($draft);
        test()->fail('Expected transaction requirement.');
    } catch (NotificationWriteFailed $failure) {
        expect($failure->category)->toBe('transaction_required');
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->toBe('Notification publication failed.');
    }
    expect(DB::transactionLevel())->toBe(0);
    expect(DB::table('organization_notifications')->where('organization_id', $draft->organizationId)->exists())->toBeFalse();
})->group('integration');

it('rejects stale framework transaction state after an external physical rollback', function () {
    DB::beginTransaction();
    try {
        DB::connection()->getPdo()->rollBack();
        expect(DB::transactionLevel())->toBe(1);
        expect(DB::connection()->getPdo()->inTransaction())->toBeFalse();
        try {
            app(NotificationPublisher::class)->publish(NotificationFixtures::draft());
            test()->fail('Expected physical transaction rejection.');
        } catch (NotificationWriteFailed $failure) {
            expect($failure->category)->toBe('transaction_required');
        }
    } finally {
        DB::rollBack();
    }
    expect(DB::transactionLevel())->toBe(0);
})->group('integration');
