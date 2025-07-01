<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Utils\RetryManager;

/**
 * Unit tests for RetryManager
 */
class RetryManagerUnitTest extends TestCase
{
    public function testSuccessfulOperationOnFirstAttempt(): void
    {
        $retryManager = new RetryManager(maxAttempts: 3);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            return 'success';
        };

        $result = $retryManager->execute($operation);

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testSuccessfulOperationAfterRetries(): void
    {
        $retryManager = new RetryManager(maxAttempts: 3, baseDelayMs: 1); // Fast for testing
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \PDOException('Connection lost');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation);

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $callCount);
    }

    public function testFailureAfterMaxAttempts(): void
    {
        $retryManager = new RetryManager(maxAttempts: 2, baseDelayMs: 1);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            throw new \PDOException('Persistent error');
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Operation failed after 2 attempts');

        try {
            $retryManager->execute($operation);
        } finally {
            $this->assertEquals(2, $callCount);
        }
    }

    public function testNonRetryableExceptionNotRetried(): void
    {
        $retryManager = new RetryManager(maxAttempts: 3, baseDelayMs: 1);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            throw new \InvalidArgumentException('Invalid data');
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid data');

        try {
            $retryManager->execute($operation);
        } finally {
            $this->assertEquals(1, $callCount); // Should not retry
        }
    }

    public function testCustomRetryableExceptions(): void
    {
        $retryManager = new RetryManager(maxAttempts: 3, baseDelayMs: 1);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \Exception('Custom retryable error');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation, ['Custom retryable error']);

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testRetryableByMessageContent(): void
    {
        $retryManager = new RetryManager(maxAttempts: 3, baseDelayMs: 1);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \Exception('Database connection timeout occurred');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation, ['connection timeout']);

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testExponentialBackoffDelay(): void
    {
        $retryManager = new RetryManager(
            maxAttempts: 3,
            baseDelayMs: 100,
            backoffMultiplier: 2.0,
            jitterMaxMs: 0 // No jitter for predictable testing
        );

        $startTime = microtime(true);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \PDOException('Temporary error');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation);
        $endTime = microtime(true);
        $totalTimeMs = ($endTime - $startTime) * 1000;

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $callCount);
        // Should have delays: 100ms + 200ms = 300ms minimum
        $this->assertGreaterThan(250, $totalTimeMs);
    }

    public function testMaxDelayLimit(): void
    {
        $retryManager = new RetryManager(
            maxAttempts: 5,
            baseDelayMs: 1000,
            maxDelayMs: 1500,
            backoffMultiplier: 3.0,
            jitterMaxMs: 0
        );

        $startTime = microtime(true);
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \PDOException('Temporary error');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation);
        $endTime = microtime(true);
        $totalTimeMs = ($endTime - $startTime) * 1000;

        $this->assertEquals('success', $result);
        // Delays should be capped at maxDelayMs
        $this->assertLessThan(3500, $totalTimeMs); // 1000 + 1500 + buffer
    }

    public function testExecuteWithConfig(): void
    {
        $retryManager = new RetryManager();
        $callCount = 0;

        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \Exception('Custom error for config test');
            }
            return 'success';
        };

        $config = [
            'max_attempts' => 5,
            'base_delay_ms' => 1,
            'retryable_exceptions' => ['Custom error']
        ];

        $result = $retryManager->executeWithConfig($operation, $config);

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testDatabaseOperationsFactory(): void
    {
        $retryManager = RetryManager::forDatabaseOperations();
        $config = $retryManager->getConfig();

        $this->assertEquals(3, $config['max_attempts']);
        $this->assertEquals(200, $config['base_delay_ms']);
        $this->assertEquals(2000, $config['max_delay_ms']);
        $this->assertEquals(2.0, $config['backoff_multiplier']);
    }

    public function testCacheOperationsFactory(): void
    {
        $retryManager = RetryManager::forCacheOperations();
        $config = $retryManager->getConfig();

        $this->assertEquals(2, $config['max_attempts']);
        $this->assertEquals(50, $config['base_delay_ms']);
        $this->assertEquals(500, $config['max_delay_ms']);
        $this->assertEquals(1.5, $config['backoff_multiplier']);
    }

    public function testNetworkOperationsFactory(): void
    {
        $retryManager = RetryManager::forNetworkOperations();
        $config = $retryManager->getConfig();

        $this->assertEquals(5, $config['max_attempts']);
        $this->assertEquals(500, $config['base_delay_ms']);
        $this->assertEquals(10000, $config['max_delay_ms']);
        $this->assertEquals(2.5, $config['backoff_multiplier']);
    }

    public function testInvalidMaxAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max attempts must be at least 1');

        new RetryManager(maxAttempts: 0);
    }

    public function testGetConfigReturnsCorrectValues(): void
    {
        $retryManager = new RetryManager(
            maxAttempts: 5,
            baseDelayMs: 250,
            maxDelayMs: 8000,
            backoffMultiplier: 1.8,
            jitterMaxMs: 75
        );

        $config = $retryManager->getConfig();

        $this->assertEquals(5, $config['max_attempts']);
        $this->assertEquals(250, $config['base_delay_ms']);
        $this->assertEquals(8000, $config['max_delay_ms']);
        $this->assertEquals(1.8, $config['backoff_multiplier']);
        $this->assertEquals(75, $config['jitter_max_ms']);
    }

    public function testDefaultRetryableExceptions(): void
    {
        $retryManager = new RetryManager(maxAttempts: 2, baseDelayMs: 1);
        $callCount = 0;

        // Test PDOException is retryable by default
        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \PDOException('Connection error');
            }
            return 'success';
        };

        $result = $retryManager->execute($operation);
        $this->assertEquals('success', $result);
        $this->assertEquals(2, $callCount);
    }

    public function testJitterAddsRandomness(): void
    {
        $retryManager = new RetryManager(
            maxAttempts: 3,
            baseDelayMs: 100,
            maxDelayMs: 1000,
            backoffMultiplier: 2.0,
            jitterMaxMs: 50
        );

        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $startTime = microtime(true);
            $callCount = 0;

            $operation = function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw new \PDOException('Test error');
                }
                return 'success';
            };

            $retryManager->execute($operation);
            $endTime = microtime(true);
            $delays[] = ($endTime - $startTime) * 1000;
        }

        // With jitter, delays should vary
        $uniqueDelays = array_unique(array_map('intval', $delays));
        $this->assertGreaterThan(1, count($uniqueDelays), 'Jitter should create variation in delays');
    }
}