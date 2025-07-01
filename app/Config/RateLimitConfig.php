<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Optimized rate limiting configuration with user-based tiers and dynamic limits
 */
class RateLimitConfig
{
    private bool $enabled;
    private int $maxRequests;
    private int $windowSeconds;
    private string $userTier;
    /** @var array<string, array<string, int>> */
    private array $tierLimits;

    // Default rate limits for different user tiers
    private const TIER_LIMITS = [
        'basic' => ['requests' => 100, 'window' => 3600],      // 100 requests/hour
        'premium' => ['requests' => 500, 'window' => 3600],    // 500 requests/hour
        'enterprise' => ['requests' => 2000, 'window' => 3600], // 2000 requests/hour
        'admin' => ['requests' => 10000, 'window' => 3600],    // 10000 requests/hour
    ];

    // Burst allowances for different operations
    private const OPERATION_MULTIPLIERS = [
        'read' => 1.0,     // Normal rate for read operations
        'write' => 0.5,    // Half rate for write operations (create, update, delete)
        'bulk' => 0.1,     // Tenth rate for bulk operations
        'auth' => 2.0,     // Double rate for authentication endpoints
    ];

    public function __construct(
        ?bool $enabled = null,
        ?int $maxRequests = null,
        ?int $windowSeconds = null,
        string $userTier = 'basic'
    ) {
        $this->enabled = $enabled ?? !$this->isTestEnvironment();
        $this->userTier = $userTier;
        $this->tierLimits = self::TIER_LIMITS;

        // Use tier-based limits if not explicitly provided
        if ($maxRequests === null || $windowSeconds === null) {
            $tierConfig = $this->getTierConfig($userTier);
            $this->maxRequests = $maxRequests ?? $tierConfig['requests'];
            $this->windowSeconds = $windowSeconds ?? $tierConfig['window'];
        } else {
            $this->maxRequests = $maxRequests;
            $this->windowSeconds = $windowSeconds;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getMaxRequests(): int
    {
        return $this->maxRequests;
    }

    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function getUserTier(): string
    {
        return $this->userTier;
    }

    /**
     * Get rate limit for specific operation type
     */
    public function getOperationLimit(string $operation = 'read'): int
    {
        $multiplier = self::OPERATION_MULTIPLIERS[$operation] ?? 1.0;
        return (int)($this->maxRequests * $multiplier);
    }

    /**
     * Get burst allowance (20% of normal limit for short bursts)
     */
    public function getBurstLimit(): int
    {
        return (int)($this->maxRequests * 0.2);
    }

    /**
     * Get configuration for a specific user tier
     */
    /** @return array<string, int> */
    private function getTierConfig(string $tier): array
    {
        return $this->tierLimits[$tier] ?? $this->tierLimits['basic'];
    }

    /**
     * Create config for specific user tier
     */
    public static function forUserTier(string $tier): self
    {
        return new self(null, null, null, $tier);
    }

    /**
     * Create config for testing with custom limits
     */
    public static function forTesting(int $requests = 100, int $window = 3600): self
    {
        return new self(true, $requests, $window);
    }

    /**
     * Create permissive config for admin/system operations
     */
    public static function forAdmin(): self
    {
        return new self(true, 10000, 3600, 'admin');
    }

    /**
     * Check if operation should have reduced rate limit
     */
    public function isRestrictedOperation(string $endpoint): bool
    {
        $restrictedPatterns = [
            '/task/create',
            '/task/update',
            '/task/delete',
            '/task/bulk',
            '/auth/register'
        ];

        foreach ($restrictedPatterns as $pattern) {
            if (str_contains($endpoint, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get rate limit configuration summary
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_requests' => $this->maxRequests,
            'window_seconds' => $this->windowSeconds,
            'user_tier' => $this->userTier,
            'burst_limit' => $this->getBurstLimit(),
            'operation_limits' => [
                'read' => $this->getOperationLimit('read'),
                'write' => $this->getOperationLimit('write'),
                'bulk' => $this->getOperationLimit('bulk'),
                'auth' => $this->getOperationLimit('auth')
            ]
        ];
    }

    /**
     * Get all available user tiers
     * @return array<string>
     */
    public static function getAvailableTiers(): array
    {
        return array_keys(self::TIER_LIMITS);
    }

    /**
     * Validate if tier exists
     */
    public static function isValidTier(string $tier): bool
    {
        return array_key_exists($tier, self::TIER_LIMITS);
    }

    private function isTestEnvironment(): bool
    {
        return getenv('APP_ENV') === 'test' || getenv('TESTING') === 'true';
    }
}
