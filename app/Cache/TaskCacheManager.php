<?php

declare(strict_types=1);

namespace App\Cache;

use App\Entities\Task;

class TaskCacheManager
{
    private CacheInterface $cache;

    // Cache TTL constants (in seconds)
    private const SINGLE_TASK_TTL = 3600;      // 1 hour
    private const TASK_LIST_TTL = 1800;        // 30 minutes
    private const OVERDUE_TASKS_TTL = 900;     // 15 minutes
    private const STATISTICS_TTL = 1800;       // 30 minutes

    // Cache key patterns
    private const KEY_TASK = 'task:';
    private const KEY_ALL_TASKS = 'tasks:all';
    private const KEY_OVERDUE_TASKS = 'tasks:overdue';
    private const KEY_STATISTICS = 'tasks:statistics';
    private const KEY_PATTERN_ALL = 'task*';

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get cached task by ID
     */
    public function getTask(int $id): ?Task
    {
        $key = self::KEY_TASK . $id;
        return $this->cache->get($key);
    }

    /**
     * Cache a task
     */
    public function setTask(Task $task): bool
    {
        $key = self::KEY_TASK . $task->getId();
        return $this->cache->set($key, $task, self::SINGLE_TASK_TTL);
    }

    /**
     * Get cached all tasks
     * @return array<Task>|null
     */
    public function getAllTasks(?string $userKey = null): ?array
    {
        $key = $userKey ? self::KEY_ALL_TASKS . ':' . $userKey : self::KEY_ALL_TASKS;
        return $this->cache->get($key);
    }

    /**
     * Cache all tasks
     * @param array<Task> $tasks
     */
    public function setAllTasks(array $tasks, ?string $userKey = null): bool
    {
        $key = $userKey ? self::KEY_ALL_TASKS . ':' . $userKey : self::KEY_ALL_TASKS;
        return $this->cache->set($key, $tasks, self::TASK_LIST_TTL);
    }

    /**
     * Get cached overdue tasks
     * @return array<Task>|null
     */
    public function getOverdueTasks(?string $userKey = null): ?array
    {
        $key = $userKey ? self::KEY_OVERDUE_TASKS . ':' . $userKey : self::KEY_OVERDUE_TASKS;
        return $this->cache->get($key);
    }

    /**
     * Cache overdue tasks
     * @param array<Task> $tasks
     */
    public function setOverdueTasks(array $tasks, ?string $userKey = null): bool
    {
        $key = $userKey ? self::KEY_OVERDUE_TASKS . ':' . $userKey : self::KEY_OVERDUE_TASKS;
        return $this->cache->set($key, $tasks, self::OVERDUE_TASKS_TTL);
    }

    /**
     * Get cached statistics
     * @return array<string, mixed>|null
     */
    public function getStatistics(?string $userKey = null): ?array
    {
        $key = $userKey ? self::KEY_STATISTICS . ':' . $userKey : self::KEY_STATISTICS;
        return $this->cache->get($key);
    }

    /**
     * Cache statistics
     * @param array<string, mixed> $statistics
     */
    public function setStatistics(array $statistics, ?string $userKey = null): bool
    {
        $key = $userKey ? self::KEY_STATISTICS . ':' . $userKey : self::KEY_STATISTICS;
        return $this->cache->set($key, $statistics, self::STATISTICS_TTL);
    }

    /**
     * Invalidate specific task cache
     */
    public function invalidateTask(int $id): bool
    {
        $key = self::KEY_TASK . $id;
        return $this->cache->delete($key);
    }

    /**
     * Invalidate all task-related caches
     */
    public function invalidateAllTasks(): int
    {
        return $this->cache->deletePattern(self::KEY_PATTERN_ALL);
    }

    /**
     * Invalidate user-specific list caches (when tasks are modified)
     */
    public function invalidateUserListCaches(int $userId): bool
    {
        $userKey = 'user_' . $userId;
        $deleted = 0;

        // Invalidate user-specific caches
        $deleted += $this->cache->delete(self::KEY_ALL_TASKS . ':' . $userKey) ? 1 : 0;
        $deleted += $this->cache->delete(self::KEY_OVERDUE_TASKS . ':' . $userKey) ? 1 : 0;
        $deleted += $this->cache->delete(self::KEY_STATISTICS . ':' . $userKey) ? 1 : 0;

        return $deleted > 0;
    }

    /**
     * Invalidate list caches (when tasks are modified) - legacy method for backward compatibility
     */
    public function invalidateListCaches(): bool
    {
        $deleted = 0;
        $deleted += $this->cache->delete(self::KEY_ALL_TASKS) ? 1 : 0;
        $deleted += $this->cache->delete(self::KEY_OVERDUE_TASKS) ? 1 : 0;
        $deleted += $this->cache->delete(self::KEY_STATISTICS) ? 1 : 0;

        return $deleted > 0;
    }

    /**
     * Invalidate caches when task is created for specific user
     */
    public function invalidateOnTaskCreate(int $userId): bool
    {
        return $this->invalidateUserListCaches($userId);
    }

    /**
     * Invalidate caches when task is updated for specific user
     */
    public function invalidateOnTaskUpdate(int $id, int $userId): bool
    {
        $this->invalidateTask($id);
        return $this->invalidateUserListCaches($userId);
    }

    /**
     * Invalidate caches when task is deleted for specific user
     */
    public function invalidateOnTaskDelete(int $id, int $userId): bool
    {
        $this->invalidateTask($id);
        return $this->invalidateUserListCaches($userId);
    }

    /**
     * Invalidate caches when task status changes (marked as done) for specific user
     */
    public function invalidateOnTaskStatusChange(int $id, int $userId): bool
    {
        $this->invalidateTask($id);
        return $this->invalidateUserListCaches($userId);
    }

    /**
     * Check if cache is available
     */
    public function isAvailable(): bool
    {
        try {
            // Special case: NullCache is always considered available
            if ($this->cache instanceof NullCache) {
                return true;
            }

            $testKey = 'cache:health:' . time();
            $testValue = 'test';

            if (!$this->cache->set($testKey, $testValue, 1)) {
                return false;
            }

            $retrieved = $this->cache->get($testKey);
            $this->cache->delete($testKey);

            return $retrieved === $testValue;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get user-specific cache statistics
     * @return array<string, mixed>
     */
    public function getUserCacheInfo(int $userId): array
    {
        $userKey = 'user_' . $userId;
        $info = [
            'available' => $this->isAvailable(),
            'user_id' => $userId,
            'keys' => []
        ];

        if ($info['available']) {
            $keys = [
                'all_tasks' => self::KEY_ALL_TASKS . ':' . $userKey,
                'overdue_tasks' => self::KEY_OVERDUE_TASKS . ':' . $userKey,
                'statistics' => self::KEY_STATISTICS . ':' . $userKey
            ];

            foreach ($keys as $name => $key) {
                $info['keys'][$name] = [
                    'exists' => $this->cache->exists($key),
                    'ttl' => $this->cache->ttl($key)
                ];
            }
        }

        return $info;
    }

    /**
     * Get cache statistics - legacy method for backward compatibility
     * @return array<string, mixed>
     */
    public function getCacheInfo(): array
    {
        $info = [
            'available' => $this->isAvailable(),
            'keys' => []
        ];

        if ($info['available']) {
            $keys = [
                'all_tasks' => self::KEY_ALL_TASKS,
                'overdue_tasks' => self::KEY_OVERDUE_TASKS,
                'statistics' => self::KEY_STATISTICS
            ];

            foreach ($keys as $name => $key) {
                $info['keys'][$name] = [
                    'exists' => $this->cache->exists($key),
                    'ttl' => $this->cache->ttl($key)
                ];
            }
        }

        return $info;
    }

    /**
     * Get cache memory usage and performance metrics
     * @return array<string, mixed>
     */
    public function getCacheMetrics(): array
    {
        $metrics = [
            'hit_ratio' => 0,
            'memory_usage' => 0,
            'key_count' => 0,
            'connection_status' => $this->isAvailable()
        ];

        try {
            // Try to get Redis-specific metrics if available
            if (method_exists($this->cache, 'getInfo')) {
                $redisInfo = $this->cache->getInfo();

                if (isset($redisInfo['keyspace_hits'], $redisInfo['keyspace_misses'])) {
                    $hits = (int)$redisInfo['keyspace_hits'];
                    $misses = (int)$redisInfo['keyspace_misses'];
                    $total = $hits + $misses;
                    $metrics['hit_ratio'] = $total > 0 ? round(($hits / $total) * 100, 2) : 0;
                }

                if (isset($redisInfo['used_memory'])) {
                    $metrics['memory_usage'] = (int)$redisInfo['used_memory'];
                }

                if (isset($redisInfo['db0'])) {
                    // Parse db0 info like "keys=123,expires=45"
                    preg_match('/keys=(\d+)/', $redisInfo['db0'], $matches);
                    $metrics['key_count'] = isset($matches[1]) ? (int)$matches[1] : 0;
                }
            }
        } catch (\Exception $e) {
            // Fallback to basic metrics
        }

        return $metrics;
    }

    /**
     * Warm up cache for user with common queries
     */
    public function warmUpUserCache(int $userId): bool
    {
        try {
            $userKey = 'user_' . $userId;
            $warmedUp = 0;

            // Check if common caches exist, if not they'll be populated on next request
            $commonKeys = [
                self::KEY_ALL_TASKS . ':' . $userKey,
                self::KEY_STATISTICS . ':' . $userKey
            ];

            foreach ($commonKeys as $key) {
                if (!$this->cache->exists($key)) {
                    // Mark for warming up (actual warming happens in service layer)
                    $this->cache->set($key . ':warming', true, 60);
                    $warmedUp++;
                }
            }

            return $warmedUp > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}
