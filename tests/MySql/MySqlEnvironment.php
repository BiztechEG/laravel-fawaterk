<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * The MySQL / MariaDB lock tests. They run only when
 * FAWATERK_TEST_MYSQL_DATABASE is set, and refuse any database whose name
 * does not end in "_testing": setUp() drops and recreates the tables.
 *
 *     FAWATERK_TEST_MYSQL_PORT=3306 FAWATERK_TEST_MYSQL_DATABASE=fawaterk_testing vendor/bin/phpunit --testsuite MySql
 *
 * Also: FAWATERK_TEST_MYSQL_HOST (127.0.0.1), FAWATERK_TEST_MYSQL_USERNAME (root), FAWATERK_TEST_MYSQL_PASSWORD.
 */
final class MySqlEnvironment
{
    public const CONNECTION = 'mysql_testing';

    /** A second connection with its own session, for the start barrier (always autocommit). */
    public const BARRIER = 'mysql_barrier';

    public static function configured(): bool
    {
        return self::database() !== '';
    }

    public static function database(): string
    {
        return (string) getenv('FAWATERK_TEST_MYSQL_DATABASE');
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(): array
    {
        $database = self::database();

        if (! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to run the MySQL tests on [{$database}]: its name must end in _testing.");
        }

        return [
            'driver' => 'mysql',
            'host' => getenv('FAWATERK_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => getenv('FAWATERK_TEST_MYSQL_PORT') ?: '3306',
            'database' => $database,
            'username' => getenv('FAWATERK_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => (string) getenv('FAWATERK_TEST_MYSQL_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            // A lock wait that hangs a test fails it within seconds instead.
            'options' => [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION innodb_lock_wait_timeout = 20'],
        ];
    }

    /**
     * The same app in the test process and in every worker process.
     */
    public static function apply(Application $app): void
    {
        $config = $app->make('config');

        // A key per process: the processes share no encrypted data.
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('app.url', 'https://shop.test');
        $config->set('database.default', self::CONNECTION);
        $config->set('database.connections.'.self::CONNECTION, self::connection());
        $config->set('database.connections.'.self::BARRIER, self::connection());
        $config->set('cache.default', 'database');
        $config->set('cache.stores.database', [
            'driver' => 'database',
            'table' => 'cache',
            'lock_table' => 'cache_locks',
            'connection' => self::CONNECTION,
            'lock_connection' => self::BARRIER,
        ]);
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => self::CONNECTION,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $config->set('fawaterk.vendor_api_key', 'test-vendor-key');
        $config->set('fawaterk.cache_store', 'database');
        $config->set('fawaterk.methods', [
            'fawry' => ['id' => ['staging' => 3, 'live' => 12]],
            'card' => ['name_en' => 'Visa-Mastercard'],
        ]);
        $config->set('fawaterk.profiles', [
            'hosted' => ['kind' => 'hosted'],
            'fawry' => ['kind' => 'method', 'method' => 'fawry', 'due_after' => 2880],
        ]);
    }
}
