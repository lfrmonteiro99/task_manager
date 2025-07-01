<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * Null cache implementation for testing or when cache is disabled
 */
class NullCache implements CacheInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function deletePattern(string $pattern): int
    {
        return 0;
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function ttl(string $key): int
    {
        return -2;
    }

    public function flush(): bool
    {
        return true;
    }
}
