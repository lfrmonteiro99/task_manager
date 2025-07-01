<?php

declare(strict_types=1);

namespace App\Cache;

class CacheFactory
{
    /**
     * Create cache instance based on configuration
     */
    public static function create(): CacheInterface
    {
        $cacheType = getenv('CACHE_TYPE') ?: 'redis';

        switch ($cacheType) {
            case 'redis':
                return new RedisCache();
            case 'null':
                return new NullCache();
            default:
                throw new \InvalidArgumentException("Unsupported cache type: {$cacheType}");
        }
    }

    /**
     * Create task cache manager
     */
    public static function createTaskCacheManager(): TaskCacheManager
    {
        $cache = self::create();
        return new TaskCacheManager($cache);
    }
}
