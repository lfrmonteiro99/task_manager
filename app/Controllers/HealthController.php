<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Database;
use App\Utils\Logger;
use App\Config\AppConfig;
use App\Enums\HttpStatusCode;
use PDO;
use Predis\Client;
use Exception;

class HealthController extends BaseController
{
    public function __construct(
        private readonly Database $database,
        private readonly Logger $logger,
        private readonly AppConfig $config
    ) {
    }

    public function health(): void
    {
        $startTime = microtime(true);
        $checks = [];
        $overallStatus = 'healthy';

        // Database health check
        $checks['database'] = $this->checkDatabase();

        // Redis health check
        $checks['redis'] = $this->checkRedis();

        // Disk space check
        $checks['disk_space'] = $this->checkDiskSpace();

        // Memory usage check
        $checks['memory'] = $this->checkMemory();

        // Environment check
        $checks['environment'] = $this->checkEnvironment();

        // Determine overall status
        foreach ($checks as $check) {
            if ($check['status'] !== 'healthy') {
                $overallStatus = $check['status'] === 'critical' ? 'critical' : 'degraded';
                if ($overallStatus === 'critical') {
                    break;
                }
            }
        }

        $duration = microtime(true) - $startTime;

        $response = [
            'status' => $overallStatus,
            'timestamp' => date('c'),
            'duration_ms' => round($duration * 1000, 2),
            'version' => '1.0.0',
            'environment' => getenv('APP_ENV') ?: 'unknown',
            'checks' => $checks,
            'system' => [
                'uptime' => $this->getUptime(),
                'load_average' => $this->getLoadAverage(),
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true))
            ]
        ];

        // Set appropriate HTTP status code
        $statusCode = match ($overallStatus) {
            'healthy' => HttpStatusCode::OK,
            'degraded' => HttpStatusCode::OK, // Still operational
            'critical' => HttpStatusCode::INTERNAL_SERVER_ERROR // Service unavailable
        };

        // Log health check
        $this->logger->info('Health check performed', [
            'overall_status' => $overallStatus,
            'duration_ms' => $response['duration_ms'],
            'failed_checks' => array_keys(array_filter($checks, fn($check) => $check['status'] !== 'healthy'))
        ]);

        $this->sendJsonResponse($response, $statusCode);
    }

    /**
     * @return array{status: string, message: string, details: array<string, mixed>}
     */
    private function checkDatabase(): array
    {
        try {
            $pdo = $this->database->getConnection();

            // Test connection with a simple query
            $stmt = $pdo->query('SELECT 1 as health_check');
            if ($stmt === false) {
                throw new Exception('Failed to execute health check query');
            }

            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result === false) {
                throw new Exception('Failed to fetch health check result');
            }

            if ($result['health_check'] === 1) {
                // Check database stats
                $stmt = $pdo->query('SELECT COUNT(*) as task_count FROM tasks');
                if ($stmt === false) {
                    throw new Exception('Failed to execute task count query');
                }

                $taskResult = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($taskResult === false) {
                    throw new Exception('Failed to fetch task count');
                }

                $taskCount = $taskResult['task_count'];

                return [
                    'status' => 'healthy',
                    'message' => 'Database is responsive',
                    'details' => [
                        'task_count' => (int)$taskCount,
                        'connection_status' => 'connected',
                        'pool_stats' => Database::getConnectionPoolStats()
                    ]
                ];
            }

            return [
                'status' => 'critical',
                'message' => 'Database query failed',
                'details' => ['error' => 'Health check query returned unexpected result']
            ];
        } catch (Exception $e) {
            return [
                'status' => 'critical',
                'message' => 'Database connection failed',
                'details' => [
                    'error' => getenv('APP_ENV') === 'production'
                        ? 'Connection error'
                        : $e->getMessage()
                ]
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details: array<string, mixed>}
     */
    private function checkRedis(): array
    {
        try {
            $redisConfig = $this->config->getRedisConfig();
            $redis = new Client([
                'scheme' => 'tcp',
                'host' => $redisConfig['host'],
                'port' => $redisConfig['port'],
                'database' => $redisConfig['database'],
                'timeout' => 2.0,
                'read_write_timeout' => 2.0
            ]);

            // Test Redis with ping
            $response = $redis->ping();

            if ($response->getPayload() === 'PONG') {
                // Get Redis info
                $info = $redis->info();
                $memoryUsage = $info['used_memory_human'] ?? 'unknown';

                return [
                    'status' => 'healthy',
                    'message' => 'Redis is responsive',
                    'details' => [
                        'memory_usage' => $memoryUsage,
                        'connection_status' => 'connected'
                    ]
                ];
            }

            return [
                'status' => 'critical',
                'message' => 'Redis ping failed',
                'details' => ['error' => 'Unexpected ping response']
            ];
        } catch (Exception $e) {
            return [
                'status' => 'degraded',
                'message' => 'Redis connection failed',
                'details' => [
                    'error' => getenv('APP_ENV') === 'production'
                        ? 'Connection error'
                        : $e->getMessage(),
                    'impact' => 'Rate limiting unavailable'
                ]
            ];
        }
    }

    /**
     * @return array{status: string, message: string, details: array<string, mixed>}
     */
    private function checkDiskSpace(): array
    {
        $path = '/var/www/html';
        $totalBytes = disk_total_space($path);
        $freeBytes = disk_free_space($path);

        if ($totalBytes === false || $freeBytes === false) {
            return [
                'status' => 'unknown',
                'message' => 'Could not determine disk space',
                'details' => ['error' => 'disk_*_space functions failed']
            ];
        }

        $usedBytes = $totalBytes - $freeBytes;
        $usagePercent = ($usedBytes / $totalBytes) * 100;

        $status = match (true) {
            $usagePercent >= 95 => 'critical',
            $usagePercent >= 85 => 'degraded',
            default => 'healthy'
        };

        return [
            'status' => $status,
            'message' => sprintf('Disk usage: %.1f%%', $usagePercent),
            'details' => [
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'free' => $this->formatBytes($freeBytes),
                'usage_percent' => round($usagePercent, 1)
            ]
        ];
    }

    /**
     * @return array{status: string, message: string, details: array<string, mixed>}
     */
    private function checkMemory(): array
    {
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->parseBytes(ini_get('memory_limit'));

        if ($memoryLimit > 0) {
            $usagePercent = ($memoryUsage / $memoryLimit) * 100;

            $status = match (true) {
                $usagePercent >= 90 => 'critical',
                $usagePercent >= 75 => 'degraded',
                default => 'healthy'
            };
        } else {
            $status = 'healthy';
            $usagePercent = 0;
        }

        return [
            'status' => $status,
            'message' => sprintf('Memory usage: %.1f%%', $usagePercent),
            'details' => [
                'current' => $this->formatBytes($memoryUsage),
                'peak' => $this->formatBytes($memoryPeak),
                'limit' => $memoryLimit > 0 ? $this->formatBytes($memoryLimit) : 'unlimited',
                'usage_percent' => round($usagePercent, 1)
            ]
        ];
    }

    /**
     * @return array{status: string, message: string, details: array<string, mixed>}
     */
    private function checkEnvironment(): array
    {
        $requiredVars = ['DB_HOST', 'DB_NAME', 'API_KEY'];
        $missing = [];

        foreach ($requiredVars as $var) {
            if (getenv($var) === false || empty(getenv($var))) {
                $missing[] = $var;
            }
        }

        $status = empty($missing) ? 'healthy' : 'critical';

        return [
            'status' => $status,
            'message' => empty($missing)
                ? 'All required environment variables are set'
                : 'Missing required environment variables',
            'details' => [
                'missing_variables' => $missing,
                'app_env' => getenv('APP_ENV') ?: 'not_set'
            ]
        ];
    }

    private function getUptime(): string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = floatval(file_get_contents('/proc/uptime'));
            return $this->formatDuration($uptime);
        }

        return 'unknown';
    }

    /**
     * @return array<float>|string
     */
    private function getLoadAverage(): array|string
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load !== false ? $load : 'unknown';
        }

        return 'unknown';
    }

    private function formatBytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = (int)floor(log($bytes, 1024));
        $factor = max(0, min($factor, count($units) - 1)); // Ensure valid array index
        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }

    private function parseBytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number
        };
    }

    private function formatDuration(float $seconds): string
    {
        $intSeconds = (int)$seconds;
        $days = intval($intSeconds / 86400);
        $hours = intval(($intSeconds % 86400) / 3600);
        $minutes = intval(($intSeconds % 3600) / 60);

        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
    }

    public function debug(): void
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0 || strpos($key, 'REDIRECT_') === 0) {
                $headers[$key] = $value;
            }
        }

        $this->sendJsonResponse([
            'headers' => $headers,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'auth_header_direct' => $_SERVER['HTTP_AUTHORIZATION'] ?? 'not_set',
            'auth_header_redirect' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'not_set'
        ]);
    }
}
