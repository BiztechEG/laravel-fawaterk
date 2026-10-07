<?php

namespace BiztechEG\Fawaterk\Tests\MySql;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lines worker processes up on the database, on a connection of its own
 * (autocommit), so a worker inside a transaction can still signal.
 */
final class Barrier
{
    public const TABLE = 'fw_barrier';

    /**
     * Wait until $parties processes reached $name.
     */
    public static function wait(string $name, int $parties): void
    {
        self::signal($name);
        self::until(fn () => self::count($name) >= $parties, $name);
    }

    public static function signal(string $name): void
    {
        DB::connection(MySqlEnvironment::BARRIER)->table(self::TABLE)->insert(['name' => $name, 'pid' => getmypid()]);
    }

    public static function waitFor(string $name): void
    {
        self::until(fn () => self::count($name) > 0, $name);
    }

    private static function count(string $name): int
    {
        return DB::connection(MySqlEnvironment::BARRIER)->table(self::TABLE)->where('name', $name)->count();
    }

    private static function until(\Closure $done, string $name): void
    {
        $deadline = microtime(true) + 60;

        while (! $done()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Barrier [{$name}] timed out.");
            }

            usleep(1000);
        }
    }
}
