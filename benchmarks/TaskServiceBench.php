<?php

declare(strict_types=1);

namespace Benchmarks;

use App\Services\TaskService;
use App\Repositories\TaskRepository;
use App\Models\Database;
use App\Cache\CacheFactory;
use App\Cache\TaskCacheManager;
use App\Services\TaskRetryService;
use App\Services\PaginationService;
use App\Views\TaskView;
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
        $database = new Database();
        $cache = CacheFactory::create();
        $taskCacheManager = new TaskCacheManager($cache);
        $retryService = new TaskRetryService();
        $taskRepository = new TaskRepository($database, $taskCacheManager, $retryService);
        $paginationService = new PaginationService();
        $taskView = new TaskView();
        $this->taskService = new TaskService($taskRepository, $taskCacheManager, $paginationService, $taskView);
        
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
    #[Bench\Revs(10)]
    #[Bench\Iterations(3)]
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCreateTask(): void
    {
        $this->taskService->createTask($this->testUserId, $this->testTaskData);
    }

    /**
     * Benchmark task listing performance
     */
    #[Bench\Revs(5)]
    #[Bench\Iterations(3)]
    #[Bench\BeforeMethods(['setUp', 'createTestTasks'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchListTasks(): void
    {
        $this->taskService->getAllTasks($this->testUserId);
    }

    /**
     * Benchmark task statistics calculation
     */
    #[Bench\Revs(5)]
    #[Bench\Iterations(3)]
    #[Bench\BeforeMethods(['setUp', 'createTestTasks'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchTaskStatistics(): void
    {
        $this->taskService->getTaskStatistics($this->testUserId);
    }

    /**
     * Benchmark overdue task filtering
     */
    #[Bench\Revs(5)]
    #[Bench\Iterations(3)]
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