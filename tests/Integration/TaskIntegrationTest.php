<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Models\TestDatabase;
use App\Entities\Task;
use App\Factories\TaskFactory;
use App\Repositories\TaskRepository;
use App\Services\TaskService;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;

class TaskIntegrationTest extends TestCase
{
    private TestDatabase $database;
    private TaskCacheManager $cacheManager;
    private TaskService $taskService;
    private int $testUserId = 1; // Default test user ID

    protected function setUp(): void
    {
        $this->database = new TestDatabase();
        $this->database->createTestTable();
        
        // Create a test user
        $connection = $this->database->getConnection();
        $stmt = $connection->prepare("INSERT INTO users (id, email, password_hash, name) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email)");
        $stmt->execute([$this->testUserId, 'test@example.com', password_hash('testpass', PASSWORD_DEFAULT), 'Test User']);
        
        $this->cacheManager = new TaskCacheManager(new NullCache());
        $taskRepository = new TaskRepository($this->database, $this->cacheManager);
        $this->taskService = new TaskService($taskRepository, $this->cacheManager);
    }

    protected function tearDown(): void
    {
        $this->database->cleanTestTable();
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

    public function testCreateTask(): void
    {
        $taskData = [
            'title' => 'Test Task',
            'description' => 'This is a test task for integration testing',
            'due_date' => '2025-12-31 23:59:59'
        ];

        $result = $this->taskService->createTask($this->testUserId, $taskData);
        $this->assertTrue($result);

        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertCount(1, $tasks);
        $this->assertEquals($taskData['title'], $tasks[0]->getTitle());
        $this->assertEquals($taskData['description'], $tasks[0]->getDescription());
        $this->assertFalse($tasks[0]->isDone());
    }

    public function testCreateMultipleTasks(): void
    {
        $date1 = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
        $date2 = (new DateTime('+3 days'))->format('Y-m-d H:i:s');
        $date3 = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
        
        $this->taskService->createTask($this->testUserId, ['title' => 'Task 1', 'description' => 'First task description', 'due_date' => $date1]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Task 2', 'description' => 'Second task description', 'due_date' => $date2]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Task 3', 'description' => 'Third task description', 'due_date' => $date3]);

        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertCount(3, $tasks);
        
        // Tasks should be ordered by priority (overdue first, then by due date)
        $this->assertEquals('Task 3', $tasks[0]->getTitle());
        $this->assertEquals('Task 2', $tasks[1]->getTitle());
        $this->assertEquals('Task 1', $tasks[2]->getTitle());
    }

    public function testGetAllTasksEmpty(): void
    {
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertIsArray($tasks);
        $this->assertEmpty($tasks);
    }

    public function testMarkTaskAsDone(): void
    {
        $this->taskService->createTask($this->testUserId, ['title' => 'Test Task', 'description' => 'Description', 'due_date' => '2025-12-31 23:59:59']);
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $taskId = $tasks[0]->getId();

        $result = $this->taskService->markTaskAsDone($taskId, $this->testUserId);
        $this->assertTrue($result);

        $updatedTask = $this->taskService->getTaskById($taskId, $this->testUserId);
        $this->assertTrue($updatedTask->isDone());
    }

    public function testMarkNonExistentTaskAsDone(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Task not found');
        
        $this->taskService->markTaskAsDone(999, $this->testUserId);
    }

    public function testDeleteTask(): void
    {
        $this->taskService->createTask($this->testUserId, ['title' => 'Task to Delete', 'description' => 'Will be deleted', 'due_date' => '2025-12-31 23:59:59']);
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertCount(1, $tasks);
        
        $taskId = $tasks[0]->getId();
        $result = $this->taskService->deleteTask($taskId, $this->testUserId);
        $this->assertTrue($result);

        $remainingTasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertEmpty($remainingTasks);
    }

    public function testDeleteNonExistentTask(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Task not found');
        
        $this->taskService->deleteTask(999, $this->testUserId);
    }

    public function testGetTaskById(): void
    {
        $taskData = [
            'title' => 'Specific Task',
            'description' => 'Task for ID testing',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $this->taskService->createTask($this->testUserId, $taskData);
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $taskId = $tasks[0]->getId();

        $foundTask = $this->taskService->getTaskById($taskId, $this->testUserId);
        
        $this->assertNotNull($foundTask);
        $this->assertEquals($taskData['title'], $foundTask->getTitle());
        $this->assertEquals($taskData['description'], $foundTask->getDescription());
    }

    public function testGetTaskByIdNotFound(): void
    {
        $task = $this->taskService->getTaskById(999, $this->testUserId);
        $this->assertNull($task);
    }

    public function testGetOverdueTasks(): void
    {
        $futureDate = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
        
        // Create tasks with future dates first (to pass validation)
        $this->taskService->createTask($this->testUserId, ['title' => 'Overdue Task', 'description' => 'This is overdue', 'due_date' => $futureDate]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Future Task', 'description' => 'This is not overdue', 'due_date' => $futureDate]);
        
        // Manually update one task to have past due date for testing
        $pastDate = (new DateTime('-1 day'))->format('Y-m-d H:i:s');
        $connection = $this->database->getConnection();
        $stmt = $connection->prepare("UPDATE tasks SET due_date = ? WHERE title = ? AND user_id = ?");
        $stmt->execute([$pastDate, 'Overdue Task', $this->testUserId]);
        
        $overdueTasks = $this->taskService->getOverdueTasks($this->testUserId);
        
        $this->assertCount(1, $overdueTasks);
        $this->assertEquals('Overdue Task', $overdueTasks[0]->getTitle());
    }

    public function testGetOverdueTasksExcludesDoneTasks(): void
    {
        $futureDate = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
        
        // Create tasks with future dates first (to pass validation)
        $this->taskService->createTask($this->testUserId, ['title' => 'Overdue Done Task', 'description' => 'This is overdue but done', 'due_date' => $futureDate]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Overdue Pending Task', 'description' => 'This is overdue and pending', 'due_date' => $futureDate]);
        
        // Manually update both tasks to have past due dates for testing
        $pastDate = (new DateTime('-1 day'))->format('Y-m-d H:i:s');
        $connection = $this->database->getConnection();
        $stmt = $connection->prepare("UPDATE tasks SET due_date = ? WHERE user_id = ?");
        $stmt->execute([$pastDate, $this->testUserId]);
        
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->taskService->markTaskAsDone($tasks[0]->getId(), $this->testUserId);
        
        $overdueTasks = $this->taskService->getOverdueTasks($this->testUserId);
        
        $this->assertCount(1, $overdueTasks);
        $this->assertEquals('Overdue Pending Task', $overdueTasks[0]->getTitle());
    }

    public function testTaskOrderByDueDate(): void
    {
        $latestDate = (new DateTime('+7 days'))->format('Y-m-d H:i:s');
        $earliestDate = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
        $middleDate = (new DateTime('+3 days'))->format('Y-m-d H:i:s');
        
        $this->taskService->createTask($this->testUserId, ['title' => 'Latest Task', 'description' => 'Due last', 'due_date' => $latestDate]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Earliest Task', 'description' => 'Due first', 'due_date' => $earliestDate]);
        $this->taskService->createTask($this->testUserId, ['title' => 'Middle Task', 'description' => 'Due middle', 'due_date' => $middleDate]);

        $tasks = $this->taskService->getAllTasks($this->testUserId);
        
        // Service sorts by overdue first, then by due date
        $this->assertEquals('Earliest Task', $tasks[0]->getTitle());
        $this->assertEquals('Middle Task', $tasks[1]->getTitle());
        $this->assertEquals('Latest Task', $tasks[2]->getTitle());
    }

    public function testTaskFactoryIntegration(): void
    {
        // Test factory creates tasks that integrate properly with database
        $task = TaskFactory::create([
            'title' => 'Factory Integration Test',
            'description' => 'Testing factory integration with database',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        $this->assertEquals('Factory Integration Test', $task->getTitle());
        $this->assertEquals('Testing factory integration with database', $task->getDescription());
        $this->assertFalse($task->isDone());
        $this->assertInstanceOf(DateTime::class, $task->getDueDate());
        
        // Test task behavior
        $task->markAsDone();
        $this->assertTrue($task->isDone());
    }

    public function testCreateTaskWithValidatedData(): void
    {
        // Test the service uses factory internally for validated data
        $taskData = [
            'title' => 'Service Factory Test',
            'description' => 'Testing service using factory for validated data',
            'due_date' => '2025-12-31 23:59:59'
        ];

        $result = $this->taskService->createTask($this->testUserId, $taskData);
        $this->assertTrue($result);

        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $this->assertCount(1, $tasks);
        
        $createdTask = $tasks[0];
        $this->assertEquals($taskData['title'], $createdTask->getTitle());
        $this->assertEquals($taskData['description'], $createdTask->getDescription());
    }

    public function testCreateTaskWithPastDueDate(): void
    {
        $taskData = [
            'title' => 'Past Due Task',
            'description' => 'This task has a past due date',
            'due_date' => '2020-01-01 00:00:00'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Due date must be in the future');
        
        $this->taskService->createTask($this->testUserId, $taskData);
    }

    public function testCreateTaskWithInvalidData(): void
    {
        // Test missing required fields - should fail during factory creation
        $invalidData = [
            'title' => '',
            'description' => '',
            'due_date' => ''
        ];

        $this->expectException(Exception::class);
        $this->taskService->createTask($this->testUserId, $invalidData);
    }

    public function testMarkTaskAsDoneWithInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID');
        
        $this->taskService->markTaskAsDone(0, $this->testUserId);
    }

    public function testMarkTaskAsDoneWithNegativeId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID');
        
        $this->taskService->markTaskAsDone(-1, $this->testUserId);
    }

    public function testDeleteTaskWithInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID');
        
        $this->taskService->deleteTask(0, $this->testUserId);
    }

    public function testGetTaskByIdWithInvalidId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID');
        
        $this->taskService->getTaskById(-5, $this->testUserId);
    }

    public function testUpdateTaskWithInvalidId(): void
    {
        $taskData = [
            'title' => 'Updated Task',
            'description' => 'Updated description',
            'due_date' => '2025-12-31 23:59:59'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid task ID');
        
        $this->taskService->updateTask(0, $this->testUserId, $taskData);
    }

    public function testUpdateTaskWithPastDueDate(): void
    {
        // Create a task first
        $this->taskService->createTask($this->testUserId, [
            'title' => 'Task to Update',
            'description' => 'This task will be updated',
            'due_date' => '2025-12-31 23:59:59'
        ]);
        
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $taskId = $tasks[0]->getId();

        $updateData = [
            'title' => 'Updated Task',
            'description' => 'Updated with past due date',
            'due_date' => '2020-01-01 00:00:00'
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Due date must be in the future for pending tasks');
        
        $this->taskService->updateTask($taskId, $this->testUserId, $updateData);
    }

    public function testMarkAlreadyDoneTaskAsDone(): void
    {
        // Create and mark task as done
        $this->taskService->createTask($this->testUserId, [
            'title' => 'Already Done Task',
            'description' => 'This task is already completed',
            'due_date' => '2025-12-31 23:59:59'
        ]);
        
        $tasks = $this->taskService->getAllTasks($this->testUserId);
        $taskId = $tasks[0]->getId();
        
        // Mark as done first time
        $this->taskService->markTaskAsDone($taskId, $this->testUserId);
        
        // Try to mark as done again
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Task is already marked as done');
        
        $this->taskService->markTaskAsDone($taskId, $this->testUserId);
    }

}