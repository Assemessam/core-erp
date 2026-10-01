<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\DisposableConcurrencyDatabase;

it('drops its newly created disposable database and restores the application connection after a test failure', function () {
    $settings = config('database.connections.pgsql');
    $database = DB::selectOne('SELECT current_database() AS name')->name;
    expect(fn () => DisposableConcurrencyDatabase::run(function (): void {
        expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe(DisposableConcurrencyDatabase::NAME);
        throw new RuntimeException('Deliberate disposable test failure');
    }))->toThrow(RuntimeException::class, 'Deliberate disposable test failure');
    expect(config('database.connections.pgsql'))->toBe($settings);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe($database);
    expect(DB::table('pg_database')->where('datname', DisposableConcurrencyDatabase::NAME)->exists())->toBeFalse();
    expect(config('database.connections.concurrency_test_admin'))->toBeNull();
})->group('integration');
