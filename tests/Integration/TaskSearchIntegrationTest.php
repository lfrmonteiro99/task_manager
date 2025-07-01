<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Models\TestDatabase;
use App\Services\TaskService;
use App\Services\PaginationService;
use App\Repositories\TaskRepository;
use App\Controllers\TaskController;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;
use App\Views\TaskView;

class TaskSearchIntegrationTest extends TestCase
{
    private TestDatabase $database;
    private TaskCacheManager $cacheManager;
    private TaskService $taskService;
    private TaskController $taskController;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        $this->database = new TestDatabase();
        $this->database->createTestTable();
        
        $this->cacheManager = new TaskCacheManager(new NullCache());
        $taskRepository = new TaskRepository($this->database, $this->cacheManager);
        $paginationService = new PaginationService();
        $taskView = new TaskView();
        
        $this->taskService = new TaskService(
            $taskRepository,
            $this->cacheManager,
            $paginationService,
            $taskView
        );
        
        $this->taskController = new TaskController($this->taskService);
        
        // Set authenticated user for controller
        $_SERVER['AUTHENTICATED_USER_ID'] = $this->testUserId;
        
        $this->seedTestData();
    }

    protected function tearDown(): void
    {
        $this->database->cleanTestTable();
        unset($_SERVER['AUTHENTICATED_USER_ID']);
    }

    public static function setUpBeforeClass(): void
    {
        try {
            $testDb = new TestDatabase();
            $testDb->getConnection()->exec("CREATE DATABASE IF NOT EXISTS task_manager_test");
        } catch (Exception $e) {
            self::markTestSkipped('Cannot create test database: ' . $e->getMessage());
        }
    }

    private function seedTestData(): void
    {
        // First create a test user
        $connection = $this->database->getConnection();
        $stmt = $connection->prepare("INSERT INTO users (id, email, password_hash, name) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email)");
        $stmt->execute([$this->testUserId, 'test@example.com', password_hash('testpass', PASSWORD_DEFAULT), 'Test User']);

        $tasks = [
            [
                'title' => 'Important Meeting Preparation',
                'description' => 'Prepare slides for the quarterly business meeting',
                'due_date' => '2025-09-15 14:00:00'
            ],
            [
                'title' => 'Code Review Process',
                'description' => 'Review pull requests for the new feature implementation',
                'due_date' => '2025-08-28 17:00:00'
            ],
            [
                'title' => 'Database Optimization',
                'description' => 'Optimize database queries for better performance',
                'due_date' => '2025-10-10 12:00:00'
            ],
            [
                'title' => 'Client Project Update',
                'description' => 'Send project status update to the client team',
                'due_date' => '2025-07-30 10:00:00'
            ],
            [
                'title' => 'Team Meeting Notes',
                'description' => 'Compile and share meeting notes with the development team',
                'due_date' => '2025-08-15 16:00:00'
            ]
        ];

        foreach ($tasks as $taskData) {
            $this->taskService->createTask($this->testUserId, $taskData);
        }
        
        // Mark one task as completed for filtering tests
        $allTasks = $this->taskService->getAllTasks($this->testUserId);
        if (!empty($allTasks)) {
            $this->taskService->markTaskAsDone($allTasks[0]->getId(), $this->testUserId);
        }
        
        // Create some tasks with past due dates for overdue testing
        $pastDate = (new DateTime('-5 days'))->format('Y-m-d H:i:s');
        $this->taskService->createTask($this->testUserId, [
            'title' => 'Overdue Report',
            'description' => 'Monthly report that is now overdue',
            'due_date' => '2025-12-31 23:59:59' // Create with future date first
        ]);
        
        // Manually update to past date to simulate overdue task
        $stmt = $connection->prepare("UPDATE tasks SET due_date = ? WHERE title = ? AND user_id = ?");
        $stmt->execute([$pastDate, 'Overdue Report', $this->testUserId]);
    }

    public function testSearchByTitle(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'meeting'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // Check that returned tasks contain the search term
        $foundMatchingTask = false;
        foreach ($tasks as $task) {
            if (stripos($task['title'], 'meeting') !== false || 
                stripos($task['description'], 'meeting') !== false) {
                $foundMatchingTask = true;
                break;
            }
        }
        $this->assertTrue($foundMatchingTask, 'No tasks found matching search term "meeting"');
    }

    public function testSearchByDescription(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'database'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // Check that returned tasks contain the search term in title or description
        $foundMatchingTask = false;
        foreach ($tasks as $task) {
            if (stripos($task['title'], 'database') !== false || 
                stripos($task['description'], 'database') !== false) {
                $foundMatchingTask = true;
                break;
            }
        }
        $this->assertTrue($foundMatchingTask, 'No tasks found matching search term "database"');
    }

    public function testFilterByStatus(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'status' => 'pending'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // All returned tasks should be pending
        foreach ($tasks as $task) {
            $this->assertFalse($task['done'], 'Found completed task in pending filter');
        }
    }

    public function testFilterByCompletedStatus(): void
    {
        // Create and complete a task specifically for this test
        $this->taskService->createTask($this->testUserId, [
            'title' => 'Completed Test Task',
            'description' => 'This task will be marked as completed',
            'due_date' => '2025-12-31 23:59:59'
        ]);
        
        // Find and mark the task as completed
        $allTasks = $this->taskService->getAllTasks($this->testUserId);
        foreach ($allTasks as $task) {
            if ($task->getTitle() === 'Completed Test Task') {
                $this->taskService->markTaskAsDone($task->getId(), $this->testUserId);
                break;
            }
        }

        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'status' => 'completed'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // All returned tasks should be completed
        foreach ($tasks as $task) {
            $this->assertTrue($task['done'], 'Found pending task in completed filter');
        }
    }

    public function testFilterByDueDateRange(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'due_date_from' => '2025-08-01',
            'due_date_to' => '2025-09-30'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // Check that all returned tasks are within the date range
        $fromDate = new DateTime('2025-08-01');
        $toDate = new DateTime('2025-09-30 23:59:59');
        
        foreach ($tasks as $task) {
            $taskDueDate = new DateTime($task['due_date']);
            $this->assertGreaterThanOrEqual(
                $fromDate->getTimestamp(), 
                $taskDueDate->getTimestamp(),
                'Task due date is before the from date'
            );
            $this->assertLessThanOrEqual(
                $toDate->getTimestamp(), 
                $taskDueDate->getTimestamp(),
                'Task due date is after the to date'
            );
        }
    }

    public function testFilterByOverdueOnly(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'overdue_only' => '1'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // All returned tasks should be overdue
        $now = new DateTime();
        foreach ($tasks as $task) {
            $taskDueDate = new DateTime($task['due_date']);
            $this->assertLessThan(
                $now->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Found non-overdue task in overdue filter'
            );
        }
    }

    public function testCombinedSearchAndFilters(): void
    {
        // Create a specific test task for this test
        $this->taskService->createTask($this->testUserId, [
            'title' => 'Test Project Planning',
            'description' => 'Planning for the test project implementation',
            'due_date' => '2025-08-15 14:00:00'
        ]);

        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'project',
            'status' => 'pending',
            'due_date_from' => '2025-07-01',
            'due_date_to' => '2025-12-31'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        foreach ($tasks as $task) {
            // Should match search term
            $hasSearchTerm = stripos($task['title'], 'project') !== false || 
                           stripos($task['description'], 'project') !== false;
            $this->assertTrue($hasSearchTerm, 'Task does not match search term');
            
            // Should be pending
            $this->assertFalse($task['done'], 'Found completed task in pending filter');
            
            // Should be within date range
            $taskDueDate = new DateTime($task['due_date']);
            $fromDate = new DateTime('2025-07-01');
            $toDate = new DateTime('2025-12-31 23:59:59');
            
            $this->assertGreaterThanOrEqual(
                $fromDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date is before range'
            );
            $this->assertLessThanOrEqual(
                $toDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date is after range'
            );
        }
    }

    public function testSearchWithSorting(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'sort_by' => 'due_date',
            'sort_direction' => 'asc'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(1, count($tasks));
        
        // Check that tasks are sorted by due date ascending
        for ($i = 1; $i < count($tasks); $i++) {
            $prevDate = new DateTime($tasks[$i - 1]['due_date']);
            $currDate = new DateTime($tasks[$i]['due_date']);
            
            $this->assertLessThanOrEqual(
                $currDate->getTimestamp(),
                $prevDate->getTimestamp(),
                'Tasks are not sorted by due date ascending'
            );
        }
    }

    public function testSearchWithSortingDescending(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'sort_by' => 'due_date',
            'sort_direction' => 'desc'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $this->assertGreaterThan(1, count($tasks));
        
        // Check that tasks are sorted by due date descending
        for ($i = 1; $i < count($tasks); $i++) {
            $prevDate = new DateTime($tasks[$i - 1]['due_date']);
            $currDate = new DateTime($tasks[$i]['due_date']);
            
            $this->assertGreaterThanOrEqual(
                $currDate->getTimestamp(),
                $prevDate->getTimestamp(),
                'Tasks are not sorted by due date descending'
            );
        }
    }

    public function testSearchWithPagination(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '2'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        
        $tasks = $result['data'];
        $pagination = $result['pagination'];
        
        $this->assertLessThanOrEqual(2, count($tasks));
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertGreaterThan(0, $pagination['total_items']);
    }

    public function testSearchWithEmptyResults(): void
    {
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'nonexistent_term_xyz'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $tasks = $result['data'];
        $pagination = $result['pagination'];
        
        $this->assertEmpty($tasks);
        $this->assertEquals(0, $pagination['total_items']);
    }

    public function testControllerIntegrationWithSearch(): void
    {
        // Simulate GET request with search parameters
        $_GET = [
            'page' => '1',
            'limit' => '10',
            'search' => 'meeting'
        ];

        // Capture output
        ob_start();
        $this->taskController->list();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertNotNull($response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('pagination', $response);
        
        $tasks = $response['data'];
        $this->assertGreaterThan(0, count($tasks));
        
        // Verify search functionality works through controller
        $foundMatchingTask = false;
        foreach ($tasks as $task) {
            if (stripos($task['title'], 'meeting') !== false || 
                stripos($task['description'], 'meeting') !== false) {
                $foundMatchingTask = true;
                break;
            }
        }
        $this->assertTrue($foundMatchingTask);
        
        // Clean up
        $_GET = [];
    }

    public function testControllerIntegrationWithFilters(): void
    {
        // Simulate GET request with filters
        $_GET = [
            'page' => '1',
            'limit' => '10',
            'status' => 'pending',
            'due_date_from' => '2025-07-01'
        ];

        // Capture output
        ob_start();
        $this->taskController->list();
        $output = ob_get_clean();
        
        $response = json_decode($output, true);
        
        $this->assertNotNull($response);
        $this->assertArrayHasKey('data', $response);
        
        $tasks = $response['data'];
        
        foreach ($tasks as $task) {
            $this->assertFalse($task['done'], 'Found completed task in pending filter');
            
            $taskDueDate = new DateTime($task['due_date']);
            $fromDate = new DateTime('2025-07-01');
            $this->assertGreaterThanOrEqual(
                $fromDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date is before the from date'
            );
        }
        
        // Clean up
        $_GET = [];
    }

    public function testPerformanceWithLargeDataset(): void
    {
        // Create additional test data with future dates
        for ($i = 1; $i <= 50; $i++) {
            $this->taskService->createTask($this->testUserId, [
                'title' => "Performance Test Task {$i}",
                'description' => "Description for performance test task number {$i}",
                'due_date' => (new DateTime("+{$i} days"))->format('Y-m-d H:i:s')
            ]);
        }

        $startTime = microtime(true);
        
        $queryParams = [
            'page' => '1',
            'limit' => '10',
            'search' => 'performance',
            'sort_by' => 'due_date',
            'sort_direction' => 'asc'
        ];

        $result = $this->taskService->getPaginatedTasks($this->testUserId, $queryParams);
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Should complete within reasonable time (1 second for this test)
        $this->assertLessThan(1.0, $executionTime, 'Search query took too long to execute');
        
        $tasks = $result['data'];
        $this->assertGreaterThan(0, count($tasks));
        $this->assertLessThanOrEqual(10, count($tasks));
    }
}