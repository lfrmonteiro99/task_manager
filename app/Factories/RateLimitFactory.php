<?php

declare(strict_types=1);

namespace App\Factories;

use App\Config\RateLimitConfig;
use App\Middleware\RateLimitMiddleware;

class RateLimitFactory
{
    public static function create(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(new RateLimitConfig());
    }

    public static function createForTesting(): RateLimitMiddleware
    {
        // Rate limiting enabled for tests with reasonable limits
        $config = new RateLimitConfig(
            enabled: true,
            maxRequests: 100,
            windowSeconds: 3600
        );

        return new RateLimitMiddleware($config);
    }

    public static function createDisabled(): RateLimitMiddleware
    {
        // Completely disabled for performance tests
        $config = new RateLimitConfig(
            enabled: false,
            maxRequests: 100,
            windowSeconds: 3600
        );

        return new RateLimitMiddleware($config);
    }
}
