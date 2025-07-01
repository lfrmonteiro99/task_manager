<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Enums\HttpStatusCode;
use App\Config\RateLimitConfig;
use App\Config\AppConfig;
use Predis\Client;

class RateLimitMiddleware
{
    private Client $redis;
    private RateLimitConfig $config;
    private AppConfig $appConfig;

    public function __construct(?RateLimitConfig $config = null, ?AppConfig $appConfig = null)
    {
        $this->appConfig = $appConfig ?? AppConfig::getInstance();
        $redisConfig = $this->appConfig->getRedisConfig();

        $this->redis = new Client([
            'scheme' => 'tcp',
            'host' => $redisConfig['host'],
            'port' => $redisConfig['port'],
            'database' => $redisConfig['database'],
        ]);

        $this->config = $config ?? new RateLimitConfig();
    }

    /**
     * Enhanced rate limit check with operation-specific limits and user tiers
     * Uses Redis pipeline for better performance
     */
    public function checkRateLimit(string $apiKey, string $endpoint = '', string $operation = 'read'): bool
    {
        if (!$this->config->isEnabled()) {
            return true;
        }

        // Get operation-specific limit
        $maxRequests = $this->config->isRestrictedOperation($endpoint)
            ? $this->config->getOperationLimit('write')
            : $this->config->getOperationLimit($operation);

        $key = $this->getRateLimitKey($apiKey, $operation);
        $burstKey = $this->getBurstKey($apiKey);

        // Use Redis pipeline for atomic operations
        /** @var \Predis\Pipeline\Pipeline $pipeline */
        $pipeline = $this->redis->pipeline();
        $pipeline->get($key);
        $pipeline->get($burstKey);
        /** @var array<mixed> $results */
        $results = $pipeline->execute();

        $current = $results[0];
        $burstCount = (int)($results[1] ?? 0);

        if ($current === null) {
            // First request in window
            $this->redis->setex($key, $this->config->getWindowSeconds(), '1');
            return true;
        }

        $requestCount = (int)$current;

        // Check for burst allowance if near limit
        if ($requestCount >= $maxRequests) {
            if ($burstCount < $this->config->getBurstLimit()) {
                // Use pipeline for atomic burst increment
                /** @var \Predis\Pipeline\Pipeline $pipeline */
                $pipeline = $this->redis->pipeline();
                $pipeline->incr($burstKey);
                $pipeline->expire($burstKey, 60); // 1-minute burst window
                $pipeline->incr($key);
                /** @var array<mixed> $pipelineResults */
                $pipelineResults = $pipeline->execute();
                return true;
            }

            $this->sendRateLimitExceededResponse($requestCount, $maxRequests, $operation);
            return false;
        }

        // Increment counter
        $this->redis->incr($key);
        return true;
    }

    public function getRemainingRequests(string $apiKey, string $operation = 'read'): int
    {
        if (!$this->config->isEnabled()) {
            // When rate limiting is disabled, return a static value to indicate unlimited access
            return $this->config->getOperationLimit($operation);
        }

        $key = $this->getRateLimitKey($apiKey, $operation);
        $current = $this->redis->get($key);
        $maxRequests = $this->config->getOperationLimit($operation);

        if ($current === null) {
            return $maxRequests;
        }

        return max(0, $maxRequests - (int)$current);
    }

    public function getResetTime(string $apiKey, string $operation = 'read'): int
    {
        $key = $this->getRateLimitKey($apiKey, $operation);
        $ttl = $this->redis->ttl($key);

        return $ttl > 0 ? time() + $ttl : time() + $this->config->getWindowSeconds();
    }

    public function getMaxRequests(string $operation = 'read'): int
    {
        return $this->config->getOperationLimit($operation);
    }

    /**
     * Set user tier for dynamic rate limiting
     */
    public function setUserTier(string $tier): void
    {
        if (RateLimitConfig::isValidTier($tier)) {
            $this->config = RateLimitConfig::forUserTier($tier);
        }
    }

    /**
     * Get recommended retry delay based on current load
     */
    public function getRecommendedRetryDelay(string $apiKey): int
    {
        $status = $this->getRateLimitStatus($apiKey);
        $highestUsage = 0;

        foreach ($status['operations'] as $opStatus) {
            $highestUsage = max($highestUsage, $opStatus['percentage_used']);
        }

        // Exponential backoff based on usage
        if ($highestUsage > 90) {
            return 300; // 5 minutes for very high usage
        } elseif ($highestUsage > 75) {
            return 120; // 2 minutes for high usage
        } elseif ($highestUsage > 50) {
            return 60;  // 1 minute for medium usage
        }

        return 30; // 30 seconds for low usage
    }

    private function getRateLimitKey(string $apiKey, string $operation = 'read'): string
    {
        $hashedKey = hash('sha256', $apiKey);
        $window = $this->getCurrentWindow();
        return "rate_limit:{$operation}:{$hashedKey}:{$window}";
    }

    private function getBurstKey(string $apiKey): string
    {
        $hashedKey = hash('sha256', $apiKey);
        return "burst_limit:{$hashedKey}:" . (int)(time() / 60); // 1-minute burst windows
    }

    private function getCurrentWindow(): int
    {
        return (int)(time() / $this->config->getWindowSeconds());
    }

    private function sendRateLimitExceededResponse(int $currentRequests, int $maxRequests, string $operation): void
    {
        http_response_code(HttpStatusCode::TOO_MANY_REQUESTS->value);
        header('Content-Type: application/json');
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . (time() + $this->config->getWindowSeconds()));
        header('X-RateLimit-User-Tier: ' . $this->config->getUserTier());
        header('X-RateLimit-Operation: ' . $operation);
        header('Retry-After: ' . $this->config->getWindowSeconds());

        echo json_encode([
            'error' => 'Rate limit exceeded',
            'message' => "Too many {$operation} requests. Limit: {$maxRequests} " .
                         "requests per {$this->config->getWindowSeconds()} seconds.",
            'current_requests' => $currentRequests,
            'limit' => $maxRequests,
            'operation' => $operation,
            'user_tier' => $this->config->getUserTier(),
            'window_seconds' => $this->config->getWindowSeconds(),
            'reset_time' => time() + $this->config->getWindowSeconds(),
            'burst_limit' => $this->config->getBurstLimit(),
            'upgrade_info' => [
                'message' => 'Upgrade your plan for higher rate limits',
                'available_tiers' => RateLimitConfig::getAvailableTiers()
            ],
            'status_code' => HttpStatusCode::TOO_MANY_REQUESTS->value
        ], JSON_PRETTY_PRINT);

        exit;
    }

    /**
     * Get comprehensive rate limit status for monitoring
     * @return array<string, mixed>
     */
    public function getRateLimitStatus(string $apiKey): array
    {
        $operations = ['read', 'write', 'bulk', 'auth'];
        $status = [
            'user_tier' => $this->config->getUserTier(),
            'enabled' => $this->config->isEnabled(),
            'window_seconds' => $this->config->getWindowSeconds(),
            'operations' => []
        ];

        foreach ($operations as $operation) {
            $key = $this->getRateLimitKey($apiKey, $operation);
            $current = (int)($this->redis->get($key) ?? 0);
            $maxRequests = $this->config->getOperationLimit($operation);

            $status['operations'][$operation] = [
                'limit' => $maxRequests,
                'current' => $current,
                'remaining' => max(0, $maxRequests - $current),
                'reset_time' => $this->getResetTime($apiKey, $operation),
                'percentage_used' => $maxRequests > 0 ? round(($current / $maxRequests) * 100, 2) : 0
            ];
        }

        // Add burst status
        $burstKey = $this->getBurstKey($apiKey);
        $burstUsed = (int)($this->redis->get($burstKey) ?? 0);
        $burstLimit = $this->config->getBurstLimit();

        $status['burst'] = [
            'limit' => $burstLimit,
            'used' => $burstUsed,
            'remaining' => max(0, $burstLimit - $burstUsed)
        ];

        return $status;
    }
}
