<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\TaskRetryService;
use App\Utils\Logger;

/**
 * Unit tests for TaskRetryService
 */
class TaskRetryServiceUnitTest extends TestCase
{
    private TaskRetryService $retryService;
    private Logger $mockLogger;

    protected function setUp(): void
    {
        $this->mockLogger = $this->createMock(Logger::class);
        $this->retryService = new TaskRetryService($this->mockLogger);
    }

    public function testExecuteTaskCreationSuccess(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            return true;
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting task creation with retry',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeTaskCreation($operation, ['user_id' => 123]);

        $this->assertTrue($result);
        $this->assertEquals(1, $callCount);
    }

    public function testExecuteTaskCreationWithRetries(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw new \PDOException('Deadlock found');
            }
            return true;
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting task creation with retry',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeTaskCreation($operation, ['user_id' => 123]);

        $this->assertTrue($result);
        $this->assertEquals(3, $callCount);
    }

    public function testExecuteTaskCreationFailureAfterRetries(): void
    {
        $operation = function () {
            throw new \PDOException('Persistent deadlock');
        };

        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('Starting task creation with retry');

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Operation failed after retries');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Operation failed after 3 attempts');

        $this->retryService->executeTaskCreation($operation, ['user_id' => 123]);
    }

    public function testExecuteTaskUpdateWithCustomConfig(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 4) {
                throw new \Exception('Lock wait timeout');
            }
            return true;
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting task update with retry',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeTaskUpdate($operation, ['task_id' => 456]);

        $this->assertTrue($result);
        $this->assertEquals(4, $callCount); // Should use max_attempts: 5 for updates
    }

    public function testExecuteCacheOperationWithFallback(): void
    {
        $operation = function () {
            return 'cache_result';
        };

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with('Starting cache operation with retry');

        $result = $this->retryService->executeCacheOperation($operation, ['cache_key' => 'test']);

        $this->assertEquals('cache_result', $result);
    }

    public function testExecuteCacheOperationFailure(): void
    {
        $operation = function () {
            throw new \Exception('Redis connection failed');
        };

        $this->mockLogger->expects($this->once())
            ->method('debug')
            ->with('Starting cache operation with retry');

        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with('Cache operation failed after retries');

        $this->expectException(\Exception::class);

        $this->retryService->executeCacheOperation($operation, ['cache_key' => 'test']);
    }

    public function testExecuteTaskDeletion(): void
    {
        $operation = function () {
            return true;
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting task deletion with retry',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeTaskDeletion($operation, ['task_id' => 789]);

        $this->assertTrue($result);
    }

    public function testExecuteBatchOperation(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            if ($callCount < 2) {
                throw new \PDOException('Transaction was deadlocked');
            }
            return ['processed' => 10];
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting batch operation with retry',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeBatchOperation($operation, ['batch_size' => 10]);

        $this->assertEquals(['processed' => 10], $result);
        $this->assertEquals(2, $callCount);
    }

    public function testExecuteWithCircuitBreaker(): void
    {
        $operation = function () {
            return 'circuit_result';
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) {
                $this->assertContains($message, [
                    'Starting circuit breaker operation',
                    'Operation completed successfully'
                ]);
            });

        $result = $this->retryService->executeWithCircuitBreaker($operation, ['service' => 'external']);

        $this->assertEquals('circuit_result', $result);
    }

    public function testExecuteWithCircuitBreakerFailsFaster(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            throw new \PDOException('Connection refused'); // Use a retryable exception
        };

        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('Starting circuit breaker operation');

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Operation failed after retries');

        $this->expectException(\Exception::class);

        try {
            $this->retryService->executeWithCircuitBreaker($operation, ['service' => 'external']);
        } finally {
            // Circuit breaker should fail faster (max_attempts: 2)
            $this->assertEquals(2, $callCount);
        }
    }

    public function testGetRetryConfig(): void
    {
        $config = $this->retryService->getRetryConfig();

        $this->assertArrayHasKey('database_retry', $config);
        $this->assertArrayHasKey('cache_retry', $config);

        $this->assertEquals(3, $config['database_retry']['max_attempts']);
        $this->assertEquals(2, $config['cache_retry']['max_attempts']);
    }

    public function testNonRetryableExceptionNotRetried(): void
    {
        $callCount = 0;
        $operation = function () use (&$callCount) {
            $callCount++;
            throw new \InvalidArgumentException('Invalid input data');
        };

        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with('Starting task creation with retry');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->retryService->executeTaskCreation($operation);
        } finally {
            $this->assertEquals(1, $callCount); // Should not retry validation errors
        }
    }

    public function testContextInformationLogged(): void
    {
        $operation = function () {
            return true;
        };

        $context = [
            'user_id' => 123,
            'title' => 'Test Task',
            'operation' => 'task_create'
        ];

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $logData = null) use ($context) {
                if ($message === 'Starting task creation with retry' && $logData) {
                    $this->assertEquals($context, $logData['context']);
                } elseif ($message === 'Operation completed successfully') {
                    $this->assertArrayHasKey('duration_ms', $logData);
                }
            });

        $this->retryService->executeTaskCreation($operation, $context);
    }

    public function testDurationTracking(): void
    {
        $operation = function () {
            usleep(10000); // 10ms delay
            return true;
        };

        $this->mockLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message, $logData = null) {
                if ($message === 'Starting task creation with retry') {
                    $this->assertArrayHasKey('operation', $logData);
                } elseif ($message === 'Operation completed successfully') {
                    $this->assertArrayHasKey('duration_ms', $logData);
                    $this->assertGreaterThan(5, $logData['duration_ms']);
                }
            });

        $this->retryService->executeTaskCreation($operation);
    }

    public function testStackTraceInErrorLog(): void
    {
        $operation = function () {
            throw new \PDOException('Database error with stack trace');
        };

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with(
                'Operation failed after retries',
                $this->callback(function ($logData) {
                    return isset($logData['stack_trace']) && 
                           is_string($logData['stack_trace']) &&
                           strlen($logData['stack_trace']) > 0;
                })
            );

        $this->expectException(\Exception::class);
        $this->retryService->executeTaskCreation($operation);
    }
}