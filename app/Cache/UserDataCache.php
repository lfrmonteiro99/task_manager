<?php

declare(strict_types=1);

namespace App\Cache;

use App\Config\AppConfig;
use App\Entities\User;
use Predis\Client;
use Exception;

class UserDataCache
{
    private Client $redis;
    private AppConfig $config;
    private int $ttl;

    public function __construct(?Client $redis = null, ?AppConfig $config = null)
    {
        $this->config = $config ?? AppConfig::getInstance();
        $redisConfig = $this->config->getRedisConfig();

        $this->redis = $redis ?? new Client([
            'scheme' => 'tcp',
            'host' => $redisConfig['host'],
            'port' => $redisConfig['port'],
            'database' => $redisConfig['database']
        ]);

        $this->ttl = $this->config->get('cache.user_data_ttl', 600);
    }

    /**
     * Store user data in cache
     */
    public function storeUser(User $user): void
    {
        try {
            $key = $this->getUserKey($user->getId());
            $userData = [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'created_at' => $user->getCreatedAt()->format('Y-m-d H:i:s'),
                'cached_at' => time()
            ];

            $this->redis->setex($key, $this->ttl, json_encode($userData));
        } catch (Exception $e) {
            error_log("User cache store failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieve user data from cache
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getUser(int $userId): ?array
    {
        try {
            $key = $this->getUserKey($userId);
            $cached = $this->redis->get($key);

            if ($cached === null) {
                return null;
            }

            $userData = json_decode($cached, true);
            if ($userData === null) {
                $this->redis->del($key);
                return null;
            }

            return $userData;
        } catch (Exception $e) {
            error_log("User cache retrieval failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if user exists in cache
     */
    public function hasUser(int $userId): bool
    {
        try {
            $key = $this->getUserKey($userId);
            return $this->redis->exists($key) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Invalidate user cache
     */
    public function invalidateUser(int $userId): void
    {
        try {
            $key = $this->getUserKey($userId);
            $this->redis->del($key);
        } catch (Exception $e) {
            error_log("User cache invalidation failed: " . $e->getMessage());
        }
    }

    /**
     * Warm up cache with user data
     */
    public function warmUpUser(User $user): void
    {
        $this->storeUser($user);
    }

    /**
     * Get cache statistics
     */
    /**
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        try {
            $pattern = "user_data:*";
            $keys = $this->redis->keys($pattern);

            return [
                'total_cached_users' => count($keys),
                'cache_ttl' => $this->ttl
            ];
        } catch (Exception $e) {
            return [
                'total_cached_users' => 0,
                'cache_ttl' => $this->ttl,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getUserKey(int $userId): string
    {
        return "user_data:{$userId}";
    }
}
