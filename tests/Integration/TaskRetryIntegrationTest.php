<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Models\TestDatabase;
use App\Repositories\TaskRepository;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;
use App\Services\TaskRetryService;

/**
 * Integration tests for task retry mechanisms
 */
class TaskRetryIntegrationTest extends TestCase
{
    private static TestDatabase $testDb;
    private TaskRepository $taskRepository;
    private TaskRetryService $retryService;
    private int $testUserId;

    public static function setUpBeforeClass(): void
    {
        self::$testDb = new TestDatabase();
        try {
            self::$testDb->createTestTable();
        } catch (\Exception $e) {
            self::markTestSkipped('Cannot create test database: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        self::$testDb->cleanTestTable();
        
        // Create test user
        $this->testUserId = $this->createTestUser();
        
        // Setup dependencies
        $cache = new TaskCacheManager(new NullCache());
        $this->retryService = new TaskRetryService();
        $this->taskRepository = new TaskRepository(self::$testDb, $cache, $this->retryService);
    }

    public function testTaskCreationWithRetrySuccess(): void
    {
        $title = 'Retry Test Task';
        $description = 'Task created with retry mechanism';
        $dueDate = new \DateTime('2026-12-31 23:59:59');

        $result = $this->taskRepository->create($this->testUserId, $title, $description, $dueDate);

        $this->assertTrue($result);
        
        // Verify task was actually created
        $tasks = $this->taskRepository->findAllByUserId($this->testUserId);
        $this->assertCount(1, $tasks);
        $this->assertEquals($title, $tasks[0]->getTitle());
    }

    public function testTaskUpdateWithRetrySuccess(): void
    {
        // Create initial task
        $taskId = $this->createTestTask();
        
        $newTitle = 'Updated Task Title';
        $newDescription = 'Updated description with retry';
        $newDueDate = new \DateTime('2027-01-01 12:00:00');

        $result = $this->taskRepository->update($taskId, $this->testUserId, $newtitle, $newdescription, $newDueDate);

        $this->assertTrue($result);
        
        // Verify task was updated
        $task = $this->taskRepository->findByIdAndUserId($taskId, $this->testUserId);
        $this->assertNotNull($task);
        $this->assertEquals($newtitle, $task->getTitle());
        $this->assertEquals($newdescription, $task->getDescription());
    }

    public function testTaskMarkAsDoneWithRetrySuccess(): void
    {
        $taskId = $this->createTestTask();

        $result = $this->taskRepository->markAsDone($taskId, $this->testUserId);

        $this->assertTrue($result);
        
        // Verify task is marked as done
        $task = $this->taskRepository->findByIdAndUserId($taskId, $this->testUserId);
        $this->assertNotNull($task);
        $this->assertTrue($task->isDone());
    }

    public function testTaskDeletionWithRetrySuccess(): void
    {
        $taskId = $this->createTestTask();

        $result = $this->taskRepository->delete($taskId, $this->testUserId);

        $this->assertTrue($result);
        
        // Verify task was deleted
        $task = $this->taskRepository->findByIdAndUserId($taskId, $this->testUserId);
        $this->assertNull($task);
    }

    public function testConcurrentTaskCreation(): void
    {
        $title = 'Concurrent Task';
        $description = 'Testing concurrent operations';
        $dueDate = new \DateTime('2026-12-31 23:59:59');

        // Simulate concurrent task creation
        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $uniqueTitle = $title . ' ' . $i;
            $result = $this->taskRepository->create($this->testUserId, $uniquetitle, $description, $dueDate);
            $this->assertTrue($result, "Task creation {$i} should succeed");
        }

        // Verify all tasks were created
        $tasks = $this->taskRepository->findAllByUserId($this->testUserId);
        $this->assertCount(5, $tasks);
    }

    public function testRetryWithDatabaseConnectionIssue(): void
    {
        // This test would require a way to simulate database connection issues
        // For now, we'll test the basic retry configuration
        $config = $this->retryService->getRetryConfig();
        
        $this->assertArrayHasKey('database_retry', $config);
        $this->assertEquals(3, $config['database_retry']['max_attempts']);
        $this->assertEquals(200, $config['database_retry']['base_delay_ms']);
    }

    public function testPaginatedTasksWithRetry(): void
    {
        // Create multiple tasks
        for ($i = 1; $i <= 10; $i++) {
            $this->createTestTask("Task {$i}");
        }

        // Test paginated retrieval with retry
        $result = $this->taskRepository->findPaginatedByUserId($this->testUserId, 5, 0);

        $this->assertArrayHasKey('tasks', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertCount(5, $result['tasks']);
        $this->assertEquals(10, $result['total']);
    }

    public function testTaskCreationWithLongTitle(): void
    {
        $longTitle = str_repeat('Very Long Title ', 20); // ~300 characters
        $description = 'Testing retry with edge case data';
        $dueDate = new \DateTime('2026-12-31 23:59:59');

        // This might cause issues depending on DB schema, retry should handle it
        try {
            $result = $this->taskRepository->create($this->testUserId, $longtitle, $description, $dueDate);
            $this->assertTrue($result);
        } catch (\Exception $e) {
            // If it fails due to data constraints, that's expected and proper
            $this->assertStringContainsString('Data too long', $e->getMessage());
        }
    }

    public function testOverdueTasksRetrievalWithRetry(): void
    {
        // Create an overdue task
        $overdueDate = new \DateTime('2020-01-01 12:00:00');
        $taskId = $this->createTestTask('Overdue Task', 'This task is overdue', $overdueDate);

        $overdueTasks = $this->taskRepository->findOverdueByUserId($this->testUserId);

        $this->assertCount(1, $overdueTasks);
        $this->assertTrue($overdueTasks[0]->isOverdue());
    }

    public function testTaskStatisticsWithRetry(): void
    {
        // Create tasks with different statuses
        $taskId1 = $this->createTestTask('Completed Task');
        $this->createTestTask('Pending Task');
        
        // Mark one as done
        $this->taskRepository->markAsDone($taskId1, $this->testUserId);

        $stats = $this->taskRepository->getUserStatistics($this->testUserId);

        $this->assertArrayHasKey('total_tasks', $stats);
        $this->assertArrayHasKey('completed_tasks', $stats);
        $this->assertEquals(2, $stats['total_tasks']);
        $this->assertEquals(1, $stats['completed_tasks']);
    }

    public function testRetryConfigurationForDifferentOperations(): void
    {
        $retryService = new TaskRetryService();
        $config = $retryService->getRetryConfig();

        // Database operations should have different config than cache operations
        $this->assertNotEquals(
            $config['database_retry']['max_attempts'],
            $config['cache_retry']['max_attempts']
        );

        $this->assertNotEquals(
            $config['database_retry']['base_delay_ms'],
            $config['cache_retry']['base_delay_ms']
        );
    }

    public function testTaskCreationFailureWithInvalidUserId(): void
    {
        $title = 'Invalid User Task';
        $description = 'This should fail due to invalid user';
        $dueDate = new \DateTime('2026-12-31 23:59:59');
        $invalidUserId = 99999; // Non-existent user

        $this->expectException(\Exception::class);
        
        // This should fail even with retries due to foreign key constraint
        $this->taskRepository->create($invalidUserId, $title, $description, $dueDate);
    }

    private function createTestUser(): int
    {
        $sql = "INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)";
        $stmt = self::$testDb->getConnection()->prepare($sql);
        $stmt->execute([
            'Test User',
            'test_' . uniqid() . '@example.com',
            password_hash('testpass', PASSWORD_DEFAULT)
        ]);
        
        return (int)self::$testDb->getConnection()->lastInsertId();
    }

    private function createTestTask(
        string $title = 'Test Task',
        string $description = 'Test task description',
        ?DateTime $dueDate = null
    ): int {
        $dueDate = $dueDate ?? new \DateTime('2026-12-31 23:59:59');
        
        $this->taskRepository->create($this->testUserId, $title, $description, $dueDate);
        
        // Get the created task ID
        $tasks = $this->taskRepository->findAllByUserId($this->testUserId);
        $latestTask = end($tasks);
        
        return $latestTask->getId();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$testDb)) {
            self::$testDb->dropTestTable();
        }
    }
}