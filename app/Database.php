<?php

namespace App;

use Exception;
use PDO;
use PDOException;

/**
 * Class Database
 *
 * A universal, lazily-connecting singleton registry for PDO connections
 * (MySQL, MariaDB, PostgreSQL, and SQLite). Each named connection is
 * configured once and only actually connects on its first use.
 */
class Database
{
    /** @var array<string, PDO> Live connections, keyed by connection name. */
    private static array $instances = [];

    /** @var array<string, array{driver: string, params: array, options: array}> Pending configuration, keyed by connection name. */
    private static array $config = [];

    private function __construct() {}
    private function __clone() {}

    public function __wakeup(): void
    {
        throw new Exception('Cannot unserialize a Database instance.');
    }

    /**
     * Registers a connection's configuration without connecting yet.
     * The actual PDO connection is only created the first time
     * getConn() is called for this key.
     *
     * @param string $key    Connection identifier.
     * @param string $driver Driver type: 'mysql', 'mariadb', 'pgsql', or 'sqlite'.
     * @param array  $params Connection details ['host', 'database', 'username', 'password', 'port', 'charset'].
     * For sqlite, only ['path'] is required.
     */
    public static function configure(string $key, string $driver, array $params, array $options = []): void
    {
        self::$config[$key] = ['driver' => $driver, 'params' => $params, 'options' => $options];
    }

    /**
     * Returns the PDO connection for the given key, connecting lazily
     * on first access.
     */
    public static function getConn(string $key = 'default'): PDO
    {
        if (isset(self::$instances[$key])) {
            return self::$instances[$key];
        }

        if (!isset(self::$config[$key])) {
            throw new Exception("Connection '{$key}' not configured.");
        }

        ['driver' => $driver, 'params' => $params, 'options' => $options] = self::$config[$key];

        $dsn = self::buildDsn($driver, $params);

        $defaultOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Merge user options with defaults
        $finalOptions = $options + $defaultOptions;

        try {
            self::$instances[$key] = new PDO(
                $dsn,
                $params['username'] ?? null,
                $params['password'] ?? null,
                $finalOptions
            );
        } catch (PDOException $e) {
            // Database missing: create it and retry once.
            if (self::isUnknownDatabaseError($driver, $e)) {
                self::createDatabase($driver, $params);

                try {
                    self::$instances[$key] = new PDO(
                        $dsn,
                        $params['username'] ?? null,
                        $params['password'] ?? null,
                        $finalOptions
                    );
                } catch (PDOException $retryException) {
                    throw new Exception("Connection failed for '{$key}': " . $retryException->getMessage());
                }
            } else {
                throw new Exception("Connection failed for '{$key}': " . $e->getMessage());
            }
        }

        return self::$instances[$key];
    }

    /**
     * Detects the "unknown database" error for drivers that support
     * auto-creation (currently mysql/mariadb).
     */
    private static function isUnknownDatabaseError(string $driver, PDOException $e): bool
    {
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return false;
        }

        // MySQL error code 1049: Unknown database '...'
        return ($e->errorInfo[1] ?? null) === 1049;
    }

    /**
     * Connects without selecting a database and creates it if missing.
     */
    private static function createDatabase(string $driver, array $p): void
    {
        $charset = $p['charset'] ?? 'utf8mb4';
        $collation = $p['collation'] ?? 'utf8mb4_unicode_ci';

        $noDbDsn = match ($driver) {
            'mysql', 'mariadb' => "mysql:host={$p['host']};port=" . ($p['port'] ?? 3306) . ";charset={$charset}",
            default            => throw new Exception("Auto-creation of database is not supported for driver '{$driver}'."),
        };

        $pdo = new PDO($noDbDsn, $p['username'] ?? null, $p['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $database = str_replace('`', '', $p['database']);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
    }

    /**
     * Build the DSN string based on the driver.
     */
    private static function buildDsn(string $driver, array $p): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => "mysql:host={$p['host']};port=" . ($p['port'] ?? 3306) . ";dbname={$p['database']};charset=" . ($p['charset'] ?? 'utf8mb4'),
            'pgsql'            => "pgsql:host={$p['host']};port=" . ($p['port'] ?? 5432) . ";dbname={$p['database']}",
            'sqlite'           => "sqlite:{$p['path']}",
            default            => throw new Exception("Driver '{$driver}' is not supported."),
        };
    }
}
