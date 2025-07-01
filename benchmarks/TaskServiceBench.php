<?php

declare(strict_types=1);

namespace Benchmarks;

use App\Services\TaskService;
use App\Repositories\TaskRepository;
use App\Models\Database;
use App\Cache\CacheFactory;
use PhpBench\Attributes as Bench;

/**
 * Benchmark tests for TaskService performance
 */
class TaskServiceBench
{
    private TaskService $taskService;
    private int $testUserId = 1;
    private array $testTaskData;

    public function __construct()
    {
        // Setup test environment
        $database = new Database(
            host: getenv('DB_HOST') ?: 'db',
            dbname: getenv('DB_NAME') ?: 'task_manager',
            username: getenv('DB_USER') ?: 'taskuser',
            password: getenv('DB_PASS') ?: 'taskpass'
        );
        $cache = CacheFactory::create();
        $taskRepository = new TaskRepository($database, $cache);
        $this->taskService = new TaskService($taskRepository);
        
        $this->testTaskData = [
            'title' => 'Benchmark Test Task',
            'description' => 'Performance testing task creation',
            'due_date' => '2025-12-31 23:59:59',
            'priority' => 'medium'
        ];
    }

    /**
     * Benchmark task creation performance
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCreateTask(): void
    {
        $this->taskService->createTask($this->testUserId, $this->testTaskData);
    }

    /**
     * Benchmark task listing performance
     */
    #[Bench\Revs(500)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'createTestTasks'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchListTasks(): void
    {
        $this->taskService->getAllTasks($this->testUserId);
    }

    /**
     * Benchmark task statistics calculation
     */
    #[Bench\Revs(200)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'createTestTasks'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchTaskStatistics(): void
    {
        $this->taskService->getTaskStatistics($this->testUserId);
    }

    /**
     * Benchmark overdue task filtering
     */
    #[Bench\Revs(300)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'createTestTasks'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchOverdueTasks(): void
    {
        $this->taskService->getOverdueTasks($this->testUserId);
    }

    public function setUp(): void
    {
        // Clean any existing test data
        $this->tearDown();
    }

    public function createTestTasks(): void
    {
        // Create some test tasks for listing benchmarks
        for ($i = 0; $i < 10; $i++) {
            $taskData = $this->testTaskData;
            $taskData['title'] = "Benchmark Task $i";
            $this->taskService->createTask($this->testUserId, $taskData);
        }
    }

    public function tearDown(): void
    {
        // Clean up test data (simplified for benchmark purposes)
        // In a real scenario, you'd want proper cleanup
    }
}