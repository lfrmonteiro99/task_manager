<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Entities\Task;
use App\Factories\TaskFactory;
use App\Repositories\TaskRepositoryInterface;
use App\Services\TaskService;
use App\Services\PaginationService;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;
use App\Views\TaskView;
use JasonGrimes\Paginator;

class TaskSearchUnitTest extends TestCase
{
    private TaskRepositoryInterface|MockObject $mockRepository;
    private TaskCacheManager $cacheManager;
    private PaginationService|MockObject $mockPaginationService;
    private TaskView|MockObject $mockTaskView;
    private TaskService $taskService;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(TaskRepositoryInterface::class);
        $this->cacheManager = new TaskCacheManager(new NullCache());
        $this->mockPaginationService = $this->createMock(PaginationService::class);
        $this->mockTaskView = $this->createMock(TaskView::class);
        
        // Set up TaskView mock to return formatted task data
        $this->mockTaskView->method('formatTaskData')
            ->willReturnCallback(function($task) {
                return [
                    'id' => $task->getId(),
                    'title' => $task->getTitle(),
                    'description' => $task->getDescription(),
                    'due_date' => $task->getDueDate()->format('Y-m-d H:i:s'),
                    'done' => $task->isDone()
                ];
            });
        
        $this->taskService = new TaskService(
            $this->mockRepository,
            $this->cacheManager,
            $this->mockPaginationService,
            $this->mockTaskView
        );
    }

    public function testGetPaginatedTasksWithSearch(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'test task',
            'status' => 'pending',
            'priority' => 'high'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 1,
                'title' => 'Test Task 1',
                'description' => 'Description for test task 1',
                'due_date' => '2025-12-31 23:59:59'
            ]),
            TaskFactory::create([
                'id' => 2,
                'title' => 'Test Task 2',
                'description' => 'Description for test task 2',
                'due_date' => '2025-12-30 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 2];
        $expectedSearchParams = [
            'search' => 'test task',
            'status' => 'pending',
            'priority' => 'high'
        ];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 1, 'limit' => 10, 'offset' => 0]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 10, 0, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(2, 10, 1)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPaginatedTasksWithTextSearch(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '5',
            'search' => 'important meeting'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 1,
                'title' => 'Important Meeting Preparation',
                'description' => 'Prepare slides for the important meeting',
                'due_date' => '2025-12-31 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 1];
        $expectedSearchParams = ['search' => 'important meeting'];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 1, 'limit' => 5, 'offset' => 0]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 5, 0, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(1, 5, 1)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPaginatedTasksWithDateRangeFilters(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'due_date_from' => '2025-01-01',
            'due_date_to' => '2025-12-31',
            'created_from' => '2024-01-01',
            'urgency' => 'overdue'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 1,
                'title' => 'Overdue Task',
                'description' => 'This task is overdue',
                'due_date' => '2024-12-01 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 1];
        $expectedSearchParams = [
            'due_date_from' => '2025-01-01',
            'due_date_to' => '2025-12-31',
            'created_from' => '2024-01-01',
            'urgency' => 'overdue'
        ];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 1, 'limit' => 10, 'offset' => 0]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 10, 0, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(1, 10, 1)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPaginatedTasksWithOverdueFilter(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'overdue_only' => '1'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 1,
                'title' => 'Overdue Task',
                'description' => 'This task is past its due date',
                'due_date' => '2024-12-01 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 1];
        $expectedSearchParams = ['overdue_only' => '1'];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 1, 'limit' => 10, 'offset' => 0]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 10, 0, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(1, 10, 1)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPaginatedTasksWithComplexFilters(): void
    {
        $queryParams = [
            'page' => '2',
            'limit' => '5',
            'search' => 'project',
            'status' => 'pending',
            'priority' => 'urgent',
            'due_date_from' => '2025-01-01',
            'due_date_to' => '2025-06-30',
            'sort_by' => 'priority',
            'sort_direction' => 'desc'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 6,
                'title' => 'Project Planning',
                'description' => 'Plan the next project phase',
                'due_date' => '2025-03-15 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 1];
        $expectedSearchParams = [
            'search' => 'project',
            'status' => 'pending',
            'priority' => 'urgent',
            'due_date_from' => '2025-01-01',
            'due_date_to' => '2025-06-30',
            'sort_by' => 'priority',
            'sort_direction' => 'desc'
        ];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 2, 'limit' => 5, 'offset' => 5]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 5, 5, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(1, 5, 2)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }

    public function testGetPaginatedTasksWithoutSearchFilters(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10'
        ];

        $mockTasks = [
            TaskFactory::create([
                'id' => 1,
                'title' => 'Regular Task',
                'description' => 'A regular task without filters',
                'due_date' => '2025-12-31 23:59:59'
            ])
        ];

        $repositoryResult = ['tasks' => $mockTasks, 'total' => 1];
        $expectedSearchParams = [];

        $this->mockPaginationService
            ->expects($this->once())
            ->method('getPaginationParams')
            ->with($queryParams)
            ->willReturn(['page' => 1, 'limit' => 10, 'offset' => 0]);

        $this->mockRepository
            ->expects($this->once())
            ->method('findPaginatedByUserId')
            ->with($this->testUserId, 10, 0, $expectedSearchParams)
            ->willReturn($repositoryResult);

        $mockPaginator = $this->createMock(Paginator::class);
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('createPaginator')
            ->with(1, 10, 1)
            ->willReturn($mockPaginator);

        $expectedResponse = ['tasks' => $mockTasks, 'pagination' => $mockPaginator];
        
        $this->mockPaginationService
            ->expects($this->once())
            ->method('formatPaginationResponse')
            ->with($this->callback(function($tasks) {
                return is_array($tasks) && (count($tasks) == 0 || is_array($tasks[0]));
            }), $mockPaginator)
            ->willReturn($expectedResponse);

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertEquals($expectedResponse, $result);
    }
}