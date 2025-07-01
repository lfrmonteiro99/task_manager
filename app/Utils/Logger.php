<?php

declare(strict_types=1);

namespace App\Utils;

class Logger
{
    private string $logPath;
    private string $level;

    public function __construct()
    {
        $this->logPath = getenv('LOG_PATH') ?: '/tmp/task-manager-logs';
        $this->level = getenv('LOG_LEVEL') ?: 'info';

        // Ensure log directory exists
        if (!file_exists($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function security(string $message, array $context = []): void
    {
        $context['security_event'] = true;
        $this->log('SECURITY', $message, $context);

        // Also write to separate security log
        $this->writeToFile($this->logPath . '/security.log', $this->formatMessage('SECURITY', $message, $context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function request(string $method, string $uri, int $statusCode, float $duration, array $context = []): void
    {
        $context = array_merge($context, [
            'method' => $method,
            'uri' => $uri,
            'status_code' => $statusCode,
            'duration_ms' => round($duration * 1000, 2),
            'type' => 'request'
        ]);

        $this->log('INFO', 'HTTP Request', $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $formattedMessage = $this->formatMessage($level, $message, $context);

        // Write to appropriate log file
        $logFile = match ($level) {
            'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => 'error.log',
            'SECURITY' => 'security.log',
            default => 'app.log'
        };

        $this->writeToFile($this->logPath . '/' . $logFile, $formattedMessage);

        // Also write to error_log in development (not stdout to avoid header issues)
        if (getenv('APP_ENV') === 'development') {
            error_log($formattedMessage);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function formatMessage(string $level, string $message, array $context): string
    {
        $logEntry = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'request_id' => $this->getRequestId(),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];

        $encoded = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : '{"error":"Failed to encode log message"}';
    }

    private function writeToFile(string $filePath, string $message): void
    {
        $result = file_put_contents($filePath, $message . "\n", FILE_APPEND | LOCK_EX);

        if ($result === false) {
            // Fallback to error_log if file write fails
            error_log("Logger: Failed to write to {$filePath}: {$message}");
        }
    }

    private function shouldLog(string $level): bool
    {
        $levels = [
            'DEBUG' => 0,
            'INFO' => 1,
            'NOTICE' => 2,
            'WARNING' => 3,
            'ERROR' => 4,
            'CRITICAL' => 5,
            'ALERT' => 6,
            'EMERGENCY' => 7,
            'SECURITY' => 8
        ];

        $currentLevelValue = $levels[strtoupper($this->level)] ?? 1;
        $messageLevelValue = $levels[$level] ?? 1;

        return $messageLevelValue >= $currentLevelValue;
    }

    private function getRequestId(): string
    {
        static $requestId = null;

        if ($requestId === null) {
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
        }

        return $requestId;
    }
}
