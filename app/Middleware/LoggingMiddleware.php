<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\Logger;

class LoggingMiddleware
{
    private Logger $logger;
    private float $startTime;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->startTime = microtime(true);
    }

    public function logRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $clientIp = $this->getClientIp();
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;

        // Log request start
        $this->logger->info('Request started', [
            'method' => $method,
            'uri' => $uri,
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
            'content_length' => (int)$contentLength,
            'type' => 'request_start'
        ]);
    }

    public function logResponse(int $statusCode, string $contentType = 'application/json'): void
    {
        $duration = microtime(true) - $this->startTime;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
        $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $clientIp = $this->getClientIp();

        // Determine log level based on status code
        $logLevel = match (true) {
            $statusCode >= 500 => 'error',
            $statusCode >= 400 => 'warning',
            default => 'info'
        };

        $context = [
            'method' => $method,
            'uri' => $uri,
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
            'client_ip' => $clientIp,
            'content_type' => $contentType,
            'memory_usage' => memory_get_usage(true),
            'type' => 'request_end'
        ];

        // Add performance warnings
        if ($duration > 2.0) {
            $context['performance_warning'] = 'slow_request';
        }

        $message = sprintf('%s %s - %d (%.2fms)', $method, $uri, $statusCode, $context['duration_ms']);

        // Log using appropriate level
        switch ($logLevel) {
            case 'error':
                $this->logger->error($message, $context);
                break;
            case 'warning':
                $this->logger->warning($message, $context);
                break;
            default:
                $this->logger->info($message, $context);
        }
    }

    public function logAuthFailure(string $reason, string $apiKey = 'unknown'): void
    {
        $clientIp = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $this->logger->security('Authentication failed', [
            'reason' => $reason,
            'api_key_hint' => $this->maskApiKey($apiKey),
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'type' => 'auth_failure'
        ]);
    }

    public function logRateLimitExceeded(string $apiKey, int $currentCount, int $limit): void
    {
        $clientIp = $this->getClientIp();

        $this->logger->security('Rate limit exceeded', [
            'api_key_hint' => $this->maskApiKey($apiKey),
            'current_count' => $currentCount,
            'limit' => $limit,
            'client_ip' => $clientIp,
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'type' => 'rate_limit_exceeded'
        ]);
    }

    public function logSecurityViolation(string $violationType, string $field, string $value): void
    {
        $clientIp = $this->getClientIp();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $this->logger->security('Security violation detected', [
            'violation_type' => $violationType,
            'field' => $field,
            'value_hint' => substr($value, 0, 50) . (strlen($value) > 50 ? '...' : ''),
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'type' => 'security_violation'
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function logDatabaseQuery(string $query, float $duration, array $params = []): void
    {
        // Only log slow queries or in debug mode
        $shouldLog = $duration > 0.5 || getenv('LOG_LEVEL') === 'debug';

        if ($shouldLog) {
            $logLevel = $duration > 1.0 ? 'warning' : 'debug';

            $context = [
                'query' => $this->sanitizeQuery($query),
                'duration_ms' => round($duration * 1000, 2),
                'params_count' => count($params),
                'type' => 'database_query'
            ];

            if ($duration > 0.5) {
                $context['performance_warning'] = 'slow_query';
            }

            $message = sprintf('Database query executed (%.2fms)', $context['duration_ms']);

            if ($logLevel === 'warning') {
                $this->logger->warning($message, $context);
            } else {
                $this->logger->debug($message, $context);
            }
        }
    }

    private function getClientIp(): string
    {
        // Check for IP from various headers (considering reverse proxies)
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Handle comma-separated list (X-Forwarded-For)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function maskApiKey(string $apiKey): string
    {
        if (strlen($apiKey) <= 8) {
            return str_repeat('*', strlen($apiKey));
        }

        return substr($apiKey, 0, 4) . str_repeat('*', strlen($apiKey) - 8) . substr($apiKey, -4);
    }

    private function sanitizeQuery(string $query): string
    {
        // Remove sensitive data from queries for logging
        $sanitized = preg_replace('/\b(password|token|key|secret)\s*=\s*[\'"][^\'"]*[\'"]/', '$1=***', $query);

        if ($sanitized === null) {
            $sanitized = $query;
        }

        // Limit query length for logs
        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 497) . '...';
        }

        return $sanitized;
    }
}
