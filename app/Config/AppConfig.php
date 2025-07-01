<?php

declare(strict_types=1);

namespace App\Config;

use InvalidArgumentException;

class AppConfig
{
    private static ?self $instance = null;
    /** @var array<string, mixed> */
    private array $config = [];

    private function __construct()
    {
        $this->loadConfiguration();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfiguration(): void
    {
        // JWT Configuration
        $jwtSecret = getenv('JWT_SECRET');
        if (!$jwtSecret || strlen($jwtSecret) < 32) {
            throw new InvalidArgumentException(
                'JWT_SECRET environment variable must be set and at least 32 characters long'
            );
        }
        $this->config['jwt']['secret'] = $jwtSecret;
        $this->config['jwt']['expiration'] = (int)(getenv('JWT_EXPIRATION') ?: 3600);
        $this->config['jwt']['algorithm'] = 'HS256';

        // Database Configuration
        $requiredDbVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($requiredDbVars as $var) {
            $value = getenv($var);
            if ($value === false) {
                throw new InvalidArgumentException("Required environment variable {$var} is not set");
            }
            $this->config['database'][strtolower(str_replace('DB_', '', $var))] = $value;
        }

        // Redis Configuration
        $this->config['redis']['host'] = getenv('REDIS_HOST') ?: 'localhost';
        $this->config['redis']['port'] = (int)(getenv('REDIS_PORT') ?: 6379);
        $this->config['redis']['database'] = (int)(getenv('REDIS_DB') ?: 0);

        // Rate Limiting Configuration
        $this->config['rate_limit']['requests_per_hour'] = (int)(getenv('RATE_LIMIT_REQUESTS') ?: 100);
        $this->config['rate_limit']['window_seconds'] = (int)(getenv('RATE_LIMIT_WINDOW') ?: 3600);
        $this->config['rate_limit']['burst_limit'] = (int)(getenv('RATE_LIMIT_BURST') ?: 10);

        // Application Configuration
        $this->config['app']['env'] = getenv('APP_ENV') ?: 'development';
        $this->config['app']['debug'] = filter_var(getenv('APP_DEBUG') ?: 'true', FILTER_VALIDATE_BOOLEAN);
        $this->config['app']['log_level'] = getenv('LOG_LEVEL') ?: 'info';

        // Security Configuration
        $this->config['security']['max_input_length'] = 2000;
        $this->config['security']['max_title_length'] = 255;
        $this->config['security']['password_min_length'] = 8;
        $this->config['security']['enable_xss_protection'] = true;
        $this->config['security']['enable_sql_injection_detection'] = true;

        // Cache Configuration
        $this->config['cache']['default_ttl'] = (int)(getenv('CACHE_TTL') ?: 1800); // 30 minutes
        $this->config['cache']['token_validation_ttl'] = 300; // 5 minutes
        $this->config['cache']['user_data_ttl'] = 600; // 10 minutes
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $keyPart) {
            if (!isset($value[$keyPart])) {
                return $default;
            }
            $value = $value[$keyPart];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function isProduction(): bool
    {
        return $this->get('app.env') === 'production';
    }

    public function isDebug(): bool
    {
        return $this->get('app.debug', false);
    }

    /** @return array<string, mixed> */
    public function getJwtConfig(): array
    {
        return $this->config['jwt'];
    }

    /** @return array<string, mixed> */
    public function getDatabaseConfig(): array
    {
        return $this->config['database'];
    }

    /** @return array<string, mixed> */
    public function getRedisConfig(): array
    {
        return $this->config['redis'];
    }

    /** @return array<string, mixed> */
    public function getRateLimitConfig(): array
    {
        return $this->config['rate_limit'];
    }

    /** @return array<string, mixed> */
    public function getSecurityConfig(): array
    {
        return $this->config['security'];
    }

    /** @return array<string, mixed> */
    public function getCacheConfig(): array
    {
        return $this->config['cache'];
    }
}
