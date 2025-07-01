<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Centralized cache configuration for multi-user performance optimization
 */
class CacheConfig
{
    // Cache TTL configuration (in seconds)
    public const SINGLE_TASK_TTL = 3600;          // 1 hour - individual tasks
    public const TASK_LIST_TTL = 1800;            // 30 minutes - task lists
    public const OVERDUE_TASKS_TTL = 900;         // 15 minutes - overdue tasks (more dynamic)
    public const STATISTICS_TTL = 1800;           // 30 minutes - user statistics
    public const USER_SESSION_TTL = 86400;        // 24 hours - user session data

    // Connection pool settings
    public const REDIS_CONNECTION_TIMEOUT = 2.0;   // 2 seconds
    public const REDIS_READ_TIMEOUT = 1.0;         // 1 second
    public const REDIS_MAX_CONNECTIONS = 50;       // Connection pool size
    public const REDIS_IDLE_TIMEOUT = 300;         // 5 minutes

    // Cache key patterns for multi-user isolation
    public const KEY_PREFIX = 'tm:';               // Task Manager prefix
    public const USER_KEY_PREFIX = 'user:';        // User-specific prefix
    public const TASK_KEY_PREFIX = 'task:';        // Task-specific prefix
    public const STATS_KEY_PREFIX = 'stats:';      // Statistics prefix
    public const LIST_KEY_PREFIX = 'list:';        // List cache prefix

    // Performance optimization settings
    public const CACHE_WARMING_ENABLED = true;     // Enable cache warming
    public const BATCH_INVALIDATION_SIZE = 100;    // Batch size for cache invalidation
    public const COMPRESSION_ENABLED = true;       // Enable cache value compression
    public const SERIALIZATION_FORMAT = 'json';    // json, php, msgpack

    // Memory management
    public const MAX_MEMORY_USAGE_MB = 512;        // Maximum Redis memory (MB)
    public const EVICTION_POLICY = 'allkeys-lru';  // Redis eviction policy
    public const MEMORY_WARNING_THRESHOLD = 80;    // Warning at 80% memory usage

    /**
     * Get Redis connection configuration
     * @return array<string, mixed>
     */
    public static function getRedisConfig(): array
    {
        return [
            'host' => getenv('REDIS_HOST') ?: 'redis',
            'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            'timeout' => self::REDIS_CONNECTION_TIMEOUT,
            'read_timeout' => self::REDIS_READ_TIMEOUT,
            'database' => (int)(getenv('REDIS_DB') ?: 0),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'persistent_id' => 'task_manager',
            'tcp_keepalive' => 60,
            'compression' => (getenv('REDIS_COMPRESSION') === 'true') ? 'gzip' : null
        ];
    }

    /**
     * Get cache key for user-specific data
     */
    public static function getUserCacheKey(string $type, int $userId, ?string $suffix = null): string
    {
        $key = self::KEY_PREFIX . self::USER_KEY_PREFIX . $userId . ':' . $type;
        return $suffix ? $key . ':' . $suffix : $key;
    }

    /**
     * Get cache key for task-specific data
     */
    public static function getTaskCacheKey(int $taskId, ?string $suffix = null): string
    {
        $key = self::KEY_PREFIX . self::TASK_KEY_PREFIX . $taskId;
        return $suffix ? $key . ':' . $suffix : $key;
    }

    /**
     * Get TTL based on cache type and user activity
     */
    public static function getDynamicTTL(string $cacheType, bool $isActiveUser = false): int
    {
        $baseTTL = match ($cacheType) {
            'task_list' => self::TASK_LIST_TTL,
            'overdue_tasks' => self::OVERDUE_TASKS_TTL,
            'statistics' => self::STATISTICS_TTL,
            'single_task' => self::SINGLE_TASK_TTL,
            default => self::TASK_LIST_TTL
        };

        // Reduce TTL for active users to ensure fresher data
        return $isActiveUser ? (int)($baseTTL * 0.7) : $baseTTL;
    }

    /**
     * Check if cache warming should be enabled for environment
     */
    public static function shouldEnableCacheWarming(): bool
    {
        $environment = getenv('APP_ENV') ?: 'production';
        $warmingEnabled = getenv('CACHE_WARMING_ENABLED') !== 'false';
        return $warmingEnabled && $environment !== 'test';
    }

    /**
     * Get cache tags for cache invalidation grouping
     * @return array<string>
     */
    public static function getCacheTags(int $userId, ?int $taskId = null): array
    {
        $tags = ['user:' . $userId];

        if ($taskId !== null) {
            $tags[] = 'task:' . $taskId;
        }

        return $tags;
    }

    /**
     * Get performance monitoring configuration
     * @return array<string, mixed>
     */
    public static function getMonitoringConfig(): array
    {
        return [
            'enable_metrics' => getenv('CACHE_METRICS_ENABLED') !== 'false',
            'metrics_interval' => (int)(getenv('CACHE_METRICS_INTERVAL') ?: 60),
            'slow_query_threshold' => (float)(getenv('CACHE_SLOW_THRESHOLD') ?: 0.1),
            'memory_warning_threshold' => self::MEMORY_WARNING_THRESHOLD,
            'log_cache_misses' => getenv('LOG_CACHE_MISSES') === 'true'
        ];
    }
}
