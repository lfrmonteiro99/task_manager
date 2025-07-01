<?php

declare(strict_types=1);

namespace App\Services;

interface TaskRetryServiceInterface
{
    /**
     * Execute task creation with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskCreation(callable $operation, array $context = []): bool;

    /**
     * Execute task update with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskUpdate(callable $operation, array $context = []): bool;

    /**
     * Execute cache operation with retry logic
     * @param callable(): mixed $operation
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function executeCacheOperation(callable $operation, array $context = []);

    /**
     * Execute task deletion with retry logic
     * @param callable(): bool $operation
     * @param array<string, mixed> $context
     */
    public function executeTaskDeletion(callable $operation, array $context = []): bool;

    /**
     * Execute batch operation with retry logic
     * @param callable(): mixed $operation
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function executeBatchOperation(callable $operation, array $context = []): mixed;

    /**
     * Execute operation with circuit breaker pattern
     * @param callable(): mixed $operation
     * @param array<string, mixed> $context
     * @return mixed
     */
    public function executeWithCircuitBreaker(callable $operation, array $context = []): mixed;

    /**
     * Get retry configuration
     * @return array<string, mixed>
     */
    public function getRetryConfig(): array;
}
