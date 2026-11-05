<?php

declare(strict_types=1);

namespace Zana\Outbox\Tests;

/**
 * The databases these tests run on, as in mj-zana/database's tests: SQLite
 * always; MySQL and PostgreSQL when they answer (ZANA_TEST_MYSQL_* and
 * ZANA_TEST_PGSQL_*: host, port, user, password), on a database
 * "zana_outbox_test" created afresh.
 */
final class Databases
{
    /** @var array<string, array<string, mixed>|string> */
    private static array $prepared = [];

    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /**
     * @return array<string, mixed>|string Settings, or why the database cannot be used
     */
    public static function settings(string $driver): array|string
    {
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:'];
        }

        return self::$prepared[$driver] ??= self::prepare($driver);
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function prepare(string $driver): array|string
    {
        $prefix = $driver === 'mysql' ? 'ZANA_TEST_MYSQL_' : 'ZANA_TEST_PGSQL_';
        $env = static fn (string $name, string $default): string => is_string(getenv($prefix . $name)) ? (string) getenv($prefix . $name) : $default;
        $settings = ['driver' => $driver, 'host' => $env('HOST', '127.0.0.1'), 'port' => $env('PORT', $driver === 'mysql' ? '3306' : '5432'), 'username' => $env('USER', $driver === 'mysql' ? 'root' : 'postgres'), 'password' => $env('PASSWORD', '')];
        if (!extension_loaded("pdo_{$driver}")) {
            return "The PHP extension pdo_{$driver} is not loaded.";
        }

        try {
            $dsn = $driver === 'mysql' ? "mysql:host={$settings['host']};port={$settings['port']}" : "pgsql:host={$settings['host']};port={$settings['port']};dbname=postgres";
            $server = new \PDO($dsn, $settings['username'], $settings['password'], [\PDO::ATTR_TIMEOUT => 2, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $server->exec('DROP DATABASE IF EXISTS zana_outbox_test');
            $server->exec('CREATE DATABASE zana_outbox_test');
        } catch (\PDOException $e) {
            return "{$driver} is not reachable at {$settings['host']}:{$settings['port']}: {$e->getMessage()}";
        }

        return [...$settings, 'database' => 'zana_outbox_test'];
    }
}
