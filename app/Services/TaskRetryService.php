<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\RetryManager;
use App\Utils\Logger;
use Exception;
use InvalidArgumentException;

/**
 * Task-specific retry service with operation classification
 */
class TaskRetryService
{
    private RetryManager $databaseRetryManager;
    private RetryManager $cacheRetryManager;
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->databaseRetryManager = RetryManager::forDatabaseOperations();
        $this->cacheRetryManager = RetryManager::forCacheOperations();
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Execute task creation with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskCreation(callable $operation, array $context = []): bool
    {
        $operationName = 'task_creation';
        $startTime = microtime(true);

        try {
            $this->logger->info("Starting task creation with retry", [
                'operation' => $operationName,
                'context' => $context,
                'retry_config' => $this->databaseRetryManager->getConfig()
            ]);

            $result = $this->databaseRetryManager->execute($operation, [
                'Duplicate entry',
                'Data too long',
                'Connection lost',
                'Transaction deadlock'
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            $this->logFailure($operationName, $startTime, $e, $context);
            throw $e;
        }
    }

    /**
     * Execute task update with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskUpdate(callable $operation, array $context = []): bool
    {
        $operationName = 'task_update';
        $startTime = microtime(true);

        try {
            $this->logger->info("Starting task update with retry", [
                'operation' => $operationName,
                'context' => $context
            ]);

            // Custom retry config for updates (more aggressive due to optimistic locking)
            $result = $this->databaseRetryManager->executeWithConfig($operation, [
                'max_attempts' => 5,
                'base_delay_ms' => 100,
                'retryable_exceptions' => [
                    'Lock wait timeout',
                    'Deadlock found',
                    'Row was updated by another transaction'
                ]
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            $this->logFailure($operationName, $startTime, $e, $context);
            throw $e;
        }
    }

    /**
     * Execute cache operations with retry logic
     * @template T
     * @param callable(): T $operation
     * @param array<string, mixed> $context
     * @return T
     */
    public function executeCacheOperation(callable $operation, array $context = [])
    {
        $operationName = 'cache_operation';
        $startTime = microtime(true);

        try {
            $this->logger->debug("Starting cache operation with retry", [
                'operation' => $operationName,
                'context' => $context
            ]);

            $result = $this->cacheRetryManager->execute($operation, [
                'Redis connection',
                'Cache server',
                'Connection refused'
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            // Cache failures are often non-critical, log but don't necessarily fail
            $this->logger->warning("Cache operation failed after retries", [
                'operation' => $operationName,
                'context' => $context,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            throw $e;
        }
    }

    /**
     * Execute task deletion with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskDeletion(callable $operation, array $context = []): bool
    {
        $operationName = 'task_deletion';
        $startTime = microtime(true);

        try {
            $this->logger->info("Starting task deletion with retry", [
                'operation' => $operationName,
                'context' => $context
            ]);

            // Deletion operations are usually idempotent, but we still retry for transient failures
            $result = $this->databaseRetryManager->executeWithConfig($operation, [
                'max_attempts' => 3,
                'base_delay_ms' => 150,
                'retryable_exceptions' => [
                    'Foreign key constraint',
                    'Lock wait timeout',
                    'Connection lost'
                ]
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            $this->logFailure($operationName, $startTime, $e, $context);
            throw $e;
        }
    }

    /**
     * Execute batch operations with retry logic
     * @param callable(): mixed $operation
     * @param array<string, mixed> $context
     */
    public function executeBatchOperation(callable $operation, array $context = []): mixed
    {
        $operationName = 'batch_operation';
        $startTime = microtime(true);

        try {
            $this->logger->info("Starting batch operation with retry", [
                'operation' => $operationName,
                'context' => $context
            ]);

            // Batch operations need longer delays and more attempts
            $result = $this->databaseRetryManager->executeWithConfig($operation, [
                'max_attempts' => 4,
                'base_delay_ms' => 300,
                'max_delay_ms' => 8000,
                'backoff_multiplier' => 2.5,
                'retryable_exceptions' => [
                    'Deadlock found',
                    'Lock wait timeout',
                    'Transaction was deadlocked',
                    'Connection lost'
                ]
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            $this->logFailure($operationName, $startTime, $e, $context);
            throw $e;
        }
    }

    /**
     * Execute with circuit breaker pattern (fail fast after consecutive failures)
     * @param callable(): mixed $operation
     * @param array<string, mixed> $context
     */
    public function executeWithCircuitBreaker(callable $operation, array $context = []): mixed
    {
        // This would integrate with a circuit breaker implementation
        // For now, use standard retry with more conservative settings
        $operationName = 'circuit_breaker_operation';
        $startTime = microtime(true);

        try {
            $this->logger->info("Starting circuit breaker operation", [
                'operation' => $operationName,
                'context' => $context
            ]);

            $result = $this->databaseRetryManager->executeWithConfig($operation, [
                'max_attempts' => 2, // Fail faster with circuit breaker
                'base_delay_ms' => 100,
                'max_delay_ms' => 1000
            ]);

            $this->logSuccess($operationName, $startTime, $context);
            return $result;
        } catch (Exception $e) {
            $this->logFailure($operationName, $startTime, $e, $context);
            throw $e;
        }
    }

    /**
     * Log successful operation completion
     * @param array<string, mixed> $context
     */
    private function logSuccess(string $operation, float $startTime, array $context): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info("Operation completed successfully", [
            'operation' => $operation,
            'duration_ms' => $duration,
            'context' => $context
        ]);
    }

    /**
     * Log operation failure
     * @param array<string, mixed> $context
     */
    private function logFailure(string $operation, float $startTime, Exception $exception, array $context): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->error("Operation failed after retries", [
            'operation' => $operation,
            'duration_ms' => $duration,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'context' => $context,
            'stack_trace' => $exception->getTraceAsString()
        ]);
    }

    /**
     * Get retry statistics for monitoring
     * @return array<string, mixed>
     */
    public function getRetryConfig(): array
    {
        return [
            'database_retry' => $this->databaseRetryManager->getConfig(),
            'cache_retry' => $this->cacheRetryManager->getConfig()
        ];
    }
}
