<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\AppConfig;
use PDO;
use PDOException;
use Exception;

class Database
{
    /** @var array<string, PDO> */
    private static array $connectionPool = [];
    private static int $maxConnections = 10;
    private static int $activeConnections = 0;
    private ?PDO $connection = null;
    private AppConfig $config;

    public function __construct(?AppConfig $config = null)
    {
        $this->config = $config ?? AppConfig::getInstance();
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->getPooledConnection();
        }

        return $this->connection;
    }

    private function getPooledConnection(): PDO
    {
        $dbConfig = $this->config->getDatabaseConfig();
        $host = $dbConfig['host'];
        $dbname = $dbConfig['name'];
        $username = $dbConfig['user'];
        $password = $dbConfig['pass'];

        $connectionKey = "{$host}:{$dbname}:{$username}";

        // Check if we have a healthy connection in the pool
        if (
            isset(self::$connectionPool[$connectionKey]) &&
            $this->isConnectionAlive(self::$connectionPool[$connectionKey])
        ) {
            return self::$connectionPool[$connectionKey];
        }

        // Remove dead connection from pool if exists
        if (isset(self::$connectionPool[$connectionKey])) {
            unset(self::$connectionPool[$connectionKey]);
            self::$activeConnections--;
        }

        // Check connection pool limit
        if (self::$activeConnections >= self::$maxConnections) {
            // Find and close oldest connection
            $this->closeOldestConnection();
        }

        try {
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=3600, interactive_timeout=3600"
            ];

            $connection = new PDO($dsn, $username, $password, $options);

            // Store in pool
            self::$connectionPool[$connectionKey] = $connection;
            self::$activeConnections++;

            return $connection;
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    private function isConnectionAlive(PDO $connection): bool
    {
        try {
            $connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function closeOldestConnection(): void
    {
        if (!empty(self::$connectionPool)) {
            $oldestKey = array_key_first(self::$connectionPool);
            unset(self::$connectionPool[$oldestKey]);
            self::$activeConnections--;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConnectionPoolStats(): array
    {
        return [
            'active_connections' => self::$activeConnections,
            'max_connections' => self::$maxConnections,
            'pool_utilization' => round((self::$activeConnections / self::$maxConnections) * 100, 2)
        ];
    }

    public static function closeAllConnections(): void
    {
        self::$connectionPool = [];
        self::$activeConnections = 0;
    }

    public function __destruct()
    {
        $this->connection = null;
    }
}
