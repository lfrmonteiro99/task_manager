<?php

declare(strict_types=1);

namespace App\Cache;

interface CacheInterface
{
    /**
     * Get value from cache
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key): mixed;

    /**
     * Set value in cache with TTL
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds
     * @return bool
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Delete key from cache
     *
     * @param string $key
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Delete multiple keys matching pattern
     *
     * @param string $pattern
     * @return int Number of keys deleted
     */
    public function deletePattern(string $pattern): int;

    /**
     * Check if key exists in cache
     *
     * @param string $key
     * @return bool
     */
    public function exists(string $key): bool;

    /**
     * Get remaining TTL for key
     *
     * @param string $key
     * @return int TTL in seconds, -1 if no expire, -2 if key doesn't exist
     */
    public function ttl(string $key): int;

    /**
     * Flush all cache
     *
     * @return bool
     */
    public function flush(): bool;
}
