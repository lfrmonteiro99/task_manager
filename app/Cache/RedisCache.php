<?php

declare(strict_types=1);

namespace App\Cache;

use Predis\Client;
use Exception;

class RedisCache implements CacheInterface
{
    private Client $redis;
    private static ?Client $sharedConnection = null;
    /** @var array<string, bool> */
    private static array $connectionConfig = [];

    public function __construct()
    {
        $this->redis = $this->getSharedConnection();
    }

    private function getSharedConnection(): Client
    {
        $config = [
            'scheme' => 'tcp',
            'host' => getenv('REDIS_HOST') ?: 'localhost',
            'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            'database' => (int)(getenv('REDIS_CACHE_DB') ?: 1),
            'timeout' => 2.0,
            'read_write_timeout' => 2.0,
            'persistent' => true,
            'parameters' => [
                'tcp_keepalive' => true,
                'tcp_nodelay' => true,
            ]
        ];

        $configHash = md5(serialize($config));

        if (
            self::$sharedConnection === null ||
            !isset(self::$connectionConfig[$configHash]) ||
            !$this->isConnectionAlive(self::$sharedConnection)
        ) {
            try {
                self::$sharedConnection = new Client($config);
                self::$connectionConfig[$configHash] = true;

                // Test connection
                self::$sharedConnection->ping();
            } catch (Exception $e) {
                error_log("Redis connection failed: " . $e->getMessage());
                throw new Exception("Redis connection failed: " . $e->getMessage());
            }
        }

        return self::$sharedConnection;
    }

    private function isConnectionAlive(Client $connection): bool
    {
        try {
            $connection->ping();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function get(string $key): mixed
    {
        try {
            $value = $this->redis->get($key);

            if ($value === null) {
                return null;
            }

            return $this->unserialize($value);
        } catch (Exception $e) {
            // Log error and return null on cache miss
            error_log("Cache get error for key {$key}: " . $e->getMessage());
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        try {
            $serialized = $this->serialize($value);

            if ($ttl > 0) {
                return $this->redis->setex($key, $ttl, $serialized) === 'OK';
            } else {
                return $this->redis->set($key, $serialized) === 'OK';
            }
        } catch (Exception $e) {
            error_log("Cache set error for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            return $this->redis->del([$key]) > 0;
        } catch (Exception $e) {
            error_log("Cache delete error for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function deletePattern(string $pattern): int
    {
        try {
            // Use SCAN instead of KEYS for non-blocking operation
            /** @var int|string $iterator */
            $iterator = 0;
            $deletedCount = 0;

            do {
                $keys = $this->redis->scan($iterator, [
                    'MATCH' => $pattern,
                    'COUNT' => 100
                ]);

                if (!empty($keys)) {
                    $deletedCount += $this->redis->del($keys);
                }
                // Redis SCAN returns string '0' when done, but PHPStan doesn't know this
            } while ($iterator !== 0 && $iterator !== '0');

            return $deletedCount;
        } catch (Exception $e) {
            error_log("Cache delete pattern error for pattern {$pattern}: " . $e->getMessage());
            return 0;
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            error_log("Cache exists error for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function ttl(string $key): int
    {
        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            error_log("Cache TTL error for key {$key}: " . $e->getMessage());
            return -2;
        }
    }

    public function flush(): bool
    {
        try {
            return $this->redis->flushdb() === 'OK';
        } catch (Exception $e) {
            error_log("Cache flush error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Serialize value for storage
     */
    private function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize value from storage
     */
    private function unserialize(string $value): mixed
    {
        return unserialize($value);
    }

    /**
     * Get Redis client for advanced operations
     */
    public function getClient(): Client
    {
        return $this->redis;
    }
}
