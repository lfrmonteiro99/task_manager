<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Entities\Task;
use App\Factories\TaskFactory;
use App\Repositories\TaskRepositoryInterface;
use App\Services\TaskService;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;

class TaskUnitTest extends TestCase
{
    private TaskRepositoryInterface|MockObject $mockRepository;
    private TaskCacheManager $cacheManager;
    private TaskService $taskService;
    private int $testUserId = 1; // Default test user ID

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(TaskRepositoryInterface::class);
        $this->cacheManager = new TaskCacheManager(new NullCache());
        $this->taskService = new TaskService($this->mockRepository, $this->cacheManager);
    }
    
    protected function tearDown(): void
    {
        TestHelper::cleanup();
    }

    public function testCreateTask(): void
    {
        $taskData = [
            'title' => 'Unit Test Task',
            'description' => 'This is a unit test task with enough characters for validation',
            'due_date' => '2025-12-31 23:59:59'
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->testUserId,
                'Unit Test Task',
                'This is a unit test task with enough characters for validation',
                $this->isInstanceOf(DateTime::class)
            )
            ->willReturn(true);

        $result = $this->taskService->createTask($this->testUserId, $taskData);
        $this->assertTrue($result);
    }

    public function testGetAllTasksEmpty(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('findAllByUserId')
            ->with($this->testUserId)
            ->willReturn([]);

        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertIsArray($tasks);
        $this->assertEmpty($tasks);
    }

    public function testMarkTaskAsDone(): void
    {
        $taskId = 1;
        $task = TaskFactory::create([
            'id' => $taskId,
            'title' => 'Task to Mark done',
            'description' => 'This task will be marked as done during the test process',
            'due_date' => '2025-12-31 23:59:59',
            'done' => false
        ]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findByIdAndUserId')
            ->with($taskId, $this->testUserId)
            ->willReturn($task);

        $this->mockRepository
            ->expects($this->once())
            ->method('markAsDone')
            ->with($taskId, $this->testUserId)
            ->willReturn(true);

        $result = $this->taskService->markTaskAsDone($taskId, $this->testUserId);
        $this->assertTrue($result);
    }

    public function testDeleteTask(): void
    {
        $taskId = 1;
        $task = TaskFactory::create([
            'id' => $taskId,
            'title' => 'Task to Delete',
            'description' => 'This task will be deleted during the test process execution',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findByIdAndUserId')
            ->with($taskId, $this->testUserId)
            ->willReturn($task);

        $this->mockRepository
            ->expects($this->once())
            ->method('delete')
            ->with($taskId, $this->testUserId)
            ->willReturn(true);

        $result = $this->taskService->deleteTask($taskId, $this->testUserId);
        $this->assertTrue($result);
    }

    public function testGetTaskById(): void
    {
        $taskId = 1;
        $task = TaskFactory::create([
            'id' => $taskId,
            'title' => 'Findable Task',
            'description' => 'This task should be findable by its unique identifier',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findByIdAndUserId')
            ->with($taskId, $this->testUserId)
            ->willReturn($task);

        $foundTask = $this->taskService->getTaskById($taskId, $this->testUserId);
        $this->assertNotNull($foundTask);
        $this->assertEquals('Findable Task', $foundTask->getTitle());
    }

    public function testGetTaskByIdNotFound(): void
    {
        $this->mockRepository
            ->expects($this->once())
            ->method('findByIdAndUserId')
            ->with(999, $this->testUserId)
            ->willReturn(null);

        $task = $this->taskService->getTaskById(999, $this->testUserId);
        $this->assertNull($task);
    }

    public function testGetTaskStatistics(): void
    {
        // Mock the optimized view-based statistics
        $viewStats = [
            'user_id' => $this->testUserId,
            'user_name' => 'Test User',
            'user_email' => 'test@example.com',
            'total_tasks' => 2,
            'completed_tasks' => 1,
            'active_tasks' => 1,
            'overdue_tasks' => 0,
            'urgent_pending' => 0,
            'high_priority_pending' => 0,
            'avg_completion_hours' => 48.0,
            'completion_rate_percent' => 50.0,
            'last_task_activity' => '2025-06-27 12:00:00',
            'tasks_created_this_week' => 2
        ];

        $this->mockRepository
            ->expects($this->once())
            ->method('getUserStatistics')
            ->with($this->testUserId)
            ->willReturn($viewStats);

        $stats = $this->taskService->getTaskStatistics($this->testUserId);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_tasks', $stats);
        $this->assertArrayHasKey('completed_tasks', $stats);
        $this->assertArrayHasKey('pending_tasks', $stats);
        $this->assertArrayHasKey('overdue_tasks', $stats);
        $this->assertArrayHasKey('completion_rate', $stats);
        $this->assertEquals(2, $stats['total_tasks']);
        $this->assertEquals(1, $stats['completed_tasks']);
        $this->assertEquals(1, $stats['pending_tasks']);
        
        // Test enhanced statistics from view
        $this->assertArrayHasKey('urgent_pending', $stats);
        $this->assertArrayHasKey('high_priority_pending', $stats);
        $this->assertArrayHasKey('last_activity', $stats);
        $this->assertArrayHasKey('tasks_created_this_week', $stats);
        $this->assertEquals(0, $stats['urgent_pending']);
        $this->assertEquals(0, $stats['high_priority_pending']);
        $this->assertEquals(2, $stats['tasks_created_this_week']);
    }

    public function testTaskFactoryCreatesValidTask(): void
    {
        $task = TaskFactory::create([
            'title' => 'Factory Test Task',
            'description' => 'Testing factory creation',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        $this->assertEquals('Factory Test Task', $task->getTitle());
        $this->assertEquals('Testing factory creation', $task->getDescription());
        $this->assertFalse($task->isDone());
        $this->assertInstanceOf(DateTime::class, $task->getDueDate());
    }

    public function testTaskMarkAsDone(): void
    {
        $task = TaskFactory::create([
            'title' => 'Test Task',
            'description' => 'Testing task behavior',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        $this->assertFalse($task->isDone());
        $task->markAsDone();
        $this->assertTrue($task->isDone());
    }
}