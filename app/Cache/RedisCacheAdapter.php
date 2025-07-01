<?php

declare(strict_types=1);

namespace App\Cache;

use Redis;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Optimized Redis cache adapter for multi-user scenarios
 * Implements connection pooling, compression, and performance monitoring
 */
class RedisCacheAdapter implements CacheInterface
{
    private Redis $redis;
    private LoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<string, int> */
    private array $metrics;
    private bool $isConnected = false;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->config = CacheConfig::getRedisConfig();
        $this->metrics = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'connection_errors' => 0
        ];

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->redis = new Redis();

            // Configure connection options
            $this->redis->setOption(Redis::OPT_TCP_KEEPALIVE, 1);
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout']);

            // Enable compression if configured
            if ($this->config['compression']) {
                $this->redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZ4);
            }

            // Connect with persistent connection for connection pooling
            $connected = $this->redis->pconnect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout'],
                $this->config['persistent_id']
            );

            if (!$connected) {
                throw new Exception('Failed to connect to Redis');
            }

            // Authenticate if password is provided
            if ($this->config['password']) {
                $this->redis->auth($this->config['password']);
            }

            // Select database
            $this->redis->select($this->config['database']);

            $this->isConnected = true;
            $this->logger->info('Redis cache adapter connected successfully');
        } catch (Exception $e) {
            $this->metrics['connection_errors']++;
            $this->isConnected = false;
            $this->logger->error('Redis connection failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function get(string $key): mixed
    {
        if (!$this->isConnected) {
            $this->metrics['misses']++;
            return null;
        }

        try {
            $startTime = microtime(true);
            $value = $this->redis->get($key);
            $duration = microtime(true) - $startTime;

            if ($value === false) {
                $this->metrics['misses']++;
                $this->logSlowOperation('get_miss', $key, $duration);
                return null;
            }

            $this->metrics['hits']++;
            $this->logSlowOperation('get_hit', $key, $duration);

            // Handle different serialization formats
            return $this->deserialize($value);
        } catch (Exception $e) {
            $this->metrics['misses']++;
            $this->logger->error('Cache get failed', ['key' => $key, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            $startTime = microtime(true);
            $serialized = $this->serialize($value);

            $result = $this->redis->setex($key, $ttl, $serialized);
            $duration = microtime(true) - $startTime;

            if ($result) {
                $this->metrics['sets']++;
                $this->logSlowOperation('set', $key, $duration);
                return true;
            }

            return false;
        } catch (Exception $e) {
            $this->logger->error('Cache set failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            $deleteResult = $this->redis->del($key);
            $result = is_int($deleteResult) && $deleteResult > 0;

            if ($result) {
                $this->metrics['deletes']++;
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Cache delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function deletePattern(string $pattern): int
    {
        if (!$this->isConnected) {
            return 0;
        }

        try {
            // Use SCAN for better performance than KEYS
            $deleted = 0;
            $iterator = null;

            while (false !== ($keys = $this->redis->scan($iterator, $pattern, 100))) {
                if (!empty($keys)) {
                    $deleted += $this->redis->del($keys);
                }
            }

            $this->metrics['deletes'] += $deleted;
            return $deleted;
        } catch (Exception $e) {
            $this->logger->error('Cache delete pattern failed', ['pattern' => $pattern, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    public function exists(string $key): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            $existsResult = $this->redis->exists($key);
            return is_int($existsResult) && $existsResult > 0;
        } catch (Exception $e) {
            $this->logger->error('Cache exists check failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function ttl(string $key): int
    {
        if (!$this->isConnected) {
            return -1;
        }

        try {
            return $this->redis->ttl($key);
        } catch (Exception $e) {
            $this->logger->error('Cache TTL check failed', ['key' => $key, 'error' => $e->getMessage()]);
            return -1;
        }
    }

    public function flush(): bool
    {
        if (!$this->isConnected) {
            return false;
        }

        try {
            return $this->redis->flushDB();
        } catch (Exception $e) {
            $this->logger->error('Cache flush failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * Get multiple keys at once for better performance
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function multiGet(array $keys): array
    {
        if (!$this->isConnected || empty($keys)) {
            return [];
        }

        try {
            $values = $this->redis->mget($keys);
            $result = [];

            foreach ($keys as $index => $key) {
                if ($values[$index] !== false) {
                    $result[$key] = $this->deserialize($values[$index]);
                    $this->metrics['hits']++;
                } else {
                    $this->metrics['misses']++;
                }
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Cache multi-get failed', ['keys' => $keys, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Set multiple keys at once for better performance
     * @param array<string, mixed> $data
     */
    public function multiSet(array $data, int $ttl = 3600): bool
    {
        if (!$this->isConnected || empty($data)) {
            return false;
        }

        try {
            // Use pipeline for atomic operations
            $pipe = $this->redis->pipeline();

            foreach ($data as $key => $value) {
                $serialized = $this->serialize($value);
                $pipe->setex($key, $ttl, $serialized);
            }

            $results = $pipe->exec();
            $successful = array_filter($results);

            $this->metrics['sets'] += count($successful);

            return count($successful) === count($data);
        } catch (Exception $e) {
            $this->logger->error('Cache multi-set failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get Redis info for monitoring
     * @return array<string, mixed>
     */
    public function getInfo(): array
    {
        if (!$this->isConnected) {
            return [];
        }

        try {
            $info = $this->redis->info();

            // Add our custom metrics
            $info['custom_metrics'] = $this->metrics;
            $info['hit_ratio'] = $this->getHitRatio();

            return $info;
        } catch (Exception $e) {
            $this->logger->error('Failed to get Redis info', ['error' => $e->getMessage()]);
            return ['custom_metrics' => $this->metrics];
        }
    }

    /**
     * Get cache performance metrics
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return [
            'hits' => $this->metrics['hits'],
            'misses' => $this->metrics['misses'],
            'sets' => $this->metrics['sets'],
            'deletes' => $this->metrics['deletes'],
            'hit_ratio' => $this->getHitRatio(),
            'connection_errors' => $this->metrics['connection_errors'],
            'is_connected' => $this->isConnected
        ];
    }

    private function getHitRatio(): float
    {
        $total = $this->metrics['hits'] + $this->metrics['misses'];
        return $total > 0 ? round(($this->metrics['hits'] / $total) * 100, 2) : 0.0;
    }

    private function serialize(mixed $value): string
    {
        switch (CacheConfig::SERIALIZATION_FORMAT) {
            case 'json':
                $result = json_encode($value);
                return $result !== false ? $result : 'null';
            case 'php':
                return serialize($value);
            default:
                $result = json_encode($value);
                return $result !== false ? $result : 'null';
        }
    }

    private function deserialize(string $value): mixed
    {
        switch (CacheConfig::SERIALIZATION_FORMAT) {
            case 'json':
                return json_decode($value, true);
            case 'php':
                return unserialize($value);
            default:
                return json_decode($value, true);
        }
    }

    private function logSlowOperation(string $operation, string $key, float $duration): void
    {
        $config = CacheConfig::getMonitoringConfig();

        if ($config['enable_metrics'] && $duration > $config['slow_query_threshold']) {
            $this->logger->warning('Slow cache operation detected', [
                'operation' => $operation,
                'key' => $key,
                'duration' => $duration,
                'threshold' => $config['slow_query_threshold']
            ]);
        }
    }

    public function __destruct()
    {
        if ($this->isConnected) {
            // Close connection (persistent connections are managed by PHP)
            $this->redis->close();
        }
    }
}
