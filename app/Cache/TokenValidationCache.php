<?php

declare(strict_types=1);

namespace App\Cache;

use App\Config\AppConfig;
use Predis\Client;
use Exception;

class TokenValidationCache
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

        $this->ttl = $this->config->get('cache.token_validation_ttl', 300);
    }

    /**
     * Store validated token payload in cache
     */
    /**
     * @param array<string, mixed> $payload
     */
    public function storeValidatedToken(string $tokenHash, array $payload, int $tokenExpiry): void
    {
        try {
            $key = $this->getTokenKey($tokenHash);

            // Cache until token expires or cache TTL, whichever is shorter
            $cacheExpiry = min(
                $this->ttl,
                max(0, $tokenExpiry - time() - 60) // 1 minute before token expires
            );

            if ($cacheExpiry > 0) {
                $this->redis->setex($key, $cacheExpiry, json_encode($payload));
            }
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Token cache store failed: " . $e->getMessage());
        }
    }

    /**
     * Retrieve validated token payload from cache
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getValidatedToken(string $tokenHash): ?array
    {
        try {
            $key = $this->getTokenKey($tokenHash);
            $cached = $this->redis->get($key);

            if ($cached === null) {
                return null;
            }

            $payload = json_decode($cached, true);
            if ($payload === null) {
                // Invalid JSON, remove from cache
                $this->redis->del($key);
                return null;
            }

            // Check if token is still valid
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->redis->del($key);
                return null;
            }

            return $payload;
        } catch (Exception $e) {
            // Log error but don't fail the request
            error_log("Token cache retrieval failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Invalidate a specific token
     */
    public function invalidateToken(string $tokenHash): void
    {
        try {
            $key = $this->getTokenKey($tokenHash);
            $this->redis->del($key);
        } catch (Exception $e) {
            error_log("Token cache invalidation failed: " . $e->getMessage());
        }
    }

    /**
     * Invalidate all tokens for a user
     */
    public function invalidateUserTokens(int $userId): void
    {
        try {
            $pattern = "token_validation:*:user:{$userId}";
            $keys = $this->redis->keys($pattern);

            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } catch (Exception $e) {
            error_log("User token cache invalidation failed: " . $e->getMessage());
        }
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
            $pattern = "token_validation:*";
            $keys = $this->redis->keys($pattern);

            return [
                'total_cached_tokens' => count($keys),
                'cache_ttl' => $this->ttl
            ];
        } catch (Exception $e) {
            return [
                'total_cached_tokens' => 0,
                'cache_ttl' => $this->ttl,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getTokenKey(string $tokenHash): string
    {
        return "token_validation:{$tokenHash}";
    }
}
