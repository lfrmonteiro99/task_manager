<?php

declare(strict_types=1);

namespace App\Utils;

use Exception;
use PDOException;
use InvalidArgumentException;

/**
 * Retry Manager with exponential backoff and failure classification
 */
class RetryManager
{
    private const DEFAULT_MAX_ATTEMPTS = 3;
    private const DEFAULT_BASE_DELAY_MS = 100;
    private const DEFAULT_MAX_DELAY_MS = 5000;
    private const DEFAULT_BACKOFF_MULTIPLIER = 2.0;
    private const DEFAULT_JITTER_MAX_MS = 50;

    public function __construct(
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        private readonly int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        private readonly int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
        private readonly float $backoffMultiplier = self::DEFAULT_BACKOFF_MULTIPLIER,
        private readonly int $jitterMaxMs = self::DEFAULT_JITTER_MAX_MS
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Max attempts must be at least 1');
        }
    }

    /**
     * Execute operation with retry logic
     * @template T
     * @param callable(): T $operation
     * @param array<string> $retryableExceptions
     * @return T
     * @throws Exception
     */
    public function execute(callable $operation, array $retryableExceptions = [])
    {
        $lastException = null;
        $defaultRetryableExceptions = [
            PDOException::class,
            'Connection refused',
            'Connection timed out',
            'Deadlock found',
            'Lock wait timeout',
            'Server has gone away'
        ];

        $allRetryableExceptions = array_merge($defaultRetryableExceptions, $retryableExceptions);

        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (Exception $exception) {
                $lastException = $exception;

                // Check if this exception is retryable
                if (!$this->isRetryableException($exception, $allRetryableExceptions)) {
                    throw $exception;
                }

                // Don't sleep after the last attempt
                if ($attempt < $this->maxAttempts) {
                    $this->sleep($attempt);
                }
            }
        }

        // All retries exhausted, throw the last exception
        $message = "Operation failed after {$this->maxAttempts} attempts. Last error: " .
                   ($lastException?->getMessage() ?? 'Unknown error');
        throw new Exception($message, 0, $lastException);
    }

    /**
     * Execute with custom retry configuration
     * @template T
     * @param callable(): T $operation
     * @param array<string, mixed> $config
     * @return T
     */
    public function executeWithConfig(callable $operation, array $config = [])
    {
        $retryManager = new self(
            maxAttempts: $config['max_attempts'] ?? $this->maxAttempts,
            baseDelayMs: $config['base_delay_ms'] ?? $this->baseDelayMs,
            maxDelayMs: $config['max_delay_ms'] ?? $this->maxDelayMs,
            backoffMultiplier: $config['backoff_multiplier'] ?? $this->backoffMultiplier,
            jitterMaxMs: $config['jitter_max_ms'] ?? $this->jitterMaxMs
        );

        return $retryManager->execute($operation, $config['retryable_exceptions'] ?? []);
    }

    /**
     * Check if exception is retryable
     * @param array<string> $retryableExceptions
     */
    private function isRetryableException(Exception $exception, array $retryableExceptions): bool
    {
        $exceptionClass = get_class($exception);
        $exceptionMessage = $exception->getMessage();

        foreach ($retryableExceptions as $retryable) {
            // Check by class name
            if (is_a($exception, $retryable)) {
                return true;
            }

            // Check by message content
            if (stripos($exceptionMessage, $retryable) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate delay with exponential backoff and jitter
     */
    private function sleep(int $attempt): void
    {
        // Exponential backoff: delay = base_delay * (multiplier ^ (attempt - 1))
        $exponentialDelay = $this->baseDelayMs * pow($this->backoffMultiplier, $attempt - 1);

        // Apply max delay limit
        $cappedDelay = min($exponentialDelay, $this->maxDelayMs);

        // Add jitter to prevent thundering herd
        $jitter = rand(0, $this->jitterMaxMs);
        $finalDelay = $cappedDelay + $jitter;

        // Convert to microseconds and sleep
        usleep((int)($finalDelay * 1000));
    }

    /**
     * Create retry manager with database-specific configuration
     */
    public static function forDatabaseOperations(): self
    {
        return new self(
            maxAttempts: 3,
            baseDelayMs: 200,
            maxDelayMs: 2000,
            backoffMultiplier: 2.0,
            jitterMaxMs: 100
        );
    }

    /**
     * Create retry manager with cache-specific configuration
     */
    public static function forCacheOperations(): self
    {
        return new self(
            maxAttempts: 2,
            baseDelayMs: 50,
            maxDelayMs: 500,
            backoffMultiplier: 1.5,
            jitterMaxMs: 25
        );
    }

    /**
     * Create retry manager with network-specific configuration
     */
    public static function forNetworkOperations(): self
    {
        return new self(
            maxAttempts: 5,
            baseDelayMs: 500,
            maxDelayMs: 10000,
            backoffMultiplier: 2.5,
            jitterMaxMs: 200
        );
    }

    /**
     * Get retry statistics for monitoring
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'base_delay_ms' => $this->baseDelayMs,
            'max_delay_ms' => $this->maxDelayMs,
            'backoff_multiplier' => $this->backoffMultiplier,
            'jitter_max_ms' => $this->jitterMaxMs
        ];
    }
}
