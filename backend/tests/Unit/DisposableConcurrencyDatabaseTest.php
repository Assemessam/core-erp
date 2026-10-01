<?php

use Tests\Support\DisposableConcurrencyDatabase;

it('fails closed for unsafe disposable database targets before any database operation', function (bool $testing, string $target, string $applicationDatabase) {
    expect(fn () => DisposableConcurrencyDatabase::requireSafeTarget($testing, $target, $applicationDatabase))
        ->toThrow(RuntimeException::class, 'Unsafe disposable test database configuration.');
})->with([
    'non-testing environment' => [false, 'coreerp_concurrency_test', 'coreerp'],
    'development database' => [true, 'coreerp', 'coreerp'],
    'production database' => [true, 'coreerp_production', 'coreerp'],
    'unconfigured target' => [true, '', 'coreerp'],
    'arbitrary test database' => [true, 'other_test', 'coreerp'],
    'default equals target' => [true, 'coreerp_concurrency_test', 'coreerp_concurrency_test'],
    'unknown application database' => [true, 'coreerp_concurrency_test', ''],
    'SQL identifier input' => [true, 'coreerp_concurrency_test"; DROP DATABASE coreerp; --', 'coreerp'],
]);

it('accepts only the explicit dedicated name distinct from the application database', function () {
    DisposableConcurrencyDatabase::requireSafeTarget(true, DisposableConcurrencyDatabase::NAME, 'coreerp');
    expect(DisposableConcurrencyDatabase::NAME)->toBe('coreerp_concurrency_test');
});
