<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Whole-database cleanup for tests that commit immutable audit history. Never reuse an existing DB. */
final class DisposableConcurrencyDatabase
{
    public const string NAME = 'coreerp_concurrency_test';

    public static function requireSafeTarget(bool $testing, string $target, string $applicationDatabase): void
    {
        if (! $testing || $target !== self::NAME || $applicationDatabase === '' || $target === $applicationDatabase) {
            throw new RuntimeException('Unsafe disposable test database configuration.');
        }
    }

    public static function run(callable $test): void
    {
        $target = (string) getenv('COREERP_CONCURRENCY_TEST_DATABASE');
        $connectionName = (string) config('database.default');
        $settings = config('database.connections.'.$connectionName);
        self::requireSafeTarget(app()->environment('testing'), $target, (string) ($settings['database'] ?? ''));
        if ($connectionName !== 'pgsql' || ($settings['driver'] ?? null) !== 'pgsql' || ! empty($settings['url'])
            || DB::connection()->transactionLevel() !== 0 || config('database.connections.concurrency_test_admin') !== null) {
            throw new RuntimeException('Disposable test database requires an idle explicit PostgreSQL configuration.');
        }
        $applicationDatabase = DB::selectOne('SELECT current_database() AS name')->name;
        self::requireSafeTarget(app()->environment('testing'), $target, $applicationDatabase);

        config(['database.connections.concurrency_test_admin' => array_replace($settings, ['database' => 'postgres', 'url' => null])]);
        $admin = DB::connection('concurrency_test_admin');
        $created = false;
        try {
            if ($admin->table('pg_database')->where('datname', $target)->exists()) {
                throw new RuntimeException('Disposable test database already exists; refusing to reuse or drop it.');
            }
            // Identifier is the fixed allowlisted constant, never arbitrary environment input.
            $admin->statement('CREATE DATABASE "'.self::NAME.'"');
            $created = true;
            DB::purge($connectionName);
            config(['database.connections.'.$connectionName => array_replace($settings, ['database' => $target, 'url' => null])]);
            if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Disposable test database migration failed.');
            }
            $test();
        } finally {
            try {
                if ($created) {
                    self::requireSafeTarget(app()->environment('testing'), $target, $applicationDatabase);
                    DB::purge($connectionName);
                    $admin->statement('DROP DATABASE "'.self::NAME.'"');
                }
            } finally {
                config(['database.connections.'.$connectionName => $settings]);
                DB::purge('concurrency_test_admin');
                config(['database.connections.concurrency_test_admin' => null]);
            }
        }
    }
}
