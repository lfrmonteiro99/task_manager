<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TaskRepositoryInterface;
use App\Factories\TaskFactory;
use App\Entities\Task;
use App\Cache\TaskCacheManager;
use App\Services\PaginationService;
use App\Services\PaginationServiceInterface;
use App\Views\TaskView;
use DateTime;
use InvalidArgumentException;
use Exception;

class TaskService implements TaskServiceInterface
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly TaskCacheManager $cache,
        private readonly PaginationServiceInterface $paginationService = new PaginationService(),
        private readonly TaskView $taskView = new TaskView()
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createTask(int $userId, array $data): bool
    {
        // Use factory to create and validate task
        $task = TaskFactory::createFromValidatedData($data, $userId);

        // Business rule: Due date cannot be in the past
        $now = new DateTime();
        if ($task->getDueDate() <= $now) {
            throw new InvalidArgumentException('Due date must be in the future');
        }

        $result = $this->taskRepository->create(
            $userId,
            $task->getTitle(),
            $task->getDescription(),
            $task->getDueDate()
        );

        // Invalidate user-specific caches
        if ($result) {
            $this->cache->invalidateOnTaskCreate($userId);
        }

        return $result;
    }

    /**
     * @return array<Task>
     */
    public function getAllTasks(int $userId): array
    {
        // Try to get from cache first
        $userKey = 'user_' . $userId;
        $cachedTasks = $this->cache->getAllTasks($userKey);
        if ($cachedTasks !== null) {
            return $cachedTasks;
        }

        $tasks = $this->taskRepository->findAllByUserId($userId);

        // Business logic: Sort tasks by priority (overdue first, then by due date)
        usort($tasks, function (Task $a, Task $b) {
            // Check if tasks are overdue
            $aOverdue = $a->isOverdue();
            $bOverdue = $b->isOverdue();

            // Overdue tasks come first
            if ($aOverdue && !$bOverdue) {
                return -1;
            }
            if (!$aOverdue && $bOverdue) {
                return 1;
            }

            // Then sort by due date
            return $a->getDueDate() <=> $b->getDueDate();
        });

        // Cache the sorted results
        $this->cache->setAllTasks($tasks, $userKey);

        return $tasks;
    }

    /**
     * Get tasks for a user with optional pagination and search
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function getTasks(int $userId, array $queryParams = []): array
    {
        // Check if pagination is requested
        $isPaginationRequested = isset($queryParams['page']) || isset($queryParams['limit']);

        if ($isPaginationRequested) {
            return $this->getPaginatedTasks($userId, $queryParams);
        }

        // Return non-paginated tasks using TaskView for consistent formatting
        $tasks = $this->getAllTasks($userId);
        return $this->taskView->formatTaskList($tasks);
    }

    /**
     * Get paginated tasks for a user with optional search/filtering
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function getPaginatedTasks(int $userId, array $queryParams = []): array
    {
        // Get pagination parameters
        $paginationParams = $this->paginationService->getPaginationParams($queryParams);

        // Extract search parameters (everything except pagination params)
        $searchParams = array_diff_key($queryParams, array_flip(['page', 'limit']));

        // Fetch paginated data from repository with search parameters
        $result = $this->taskRepository->findPaginatedByUserId(
            $userId,
            $paginationParams['limit'],
            $paginationParams['offset'],
            $searchParams
        );

        // Create paginator instance
        $paginator = $this->paginationService->createPaginator(
            $result['total'],
            $paginationParams['limit'],
            $paginationParams['page']
        );

        // Format tasks using TaskView
        $formattedTasks = array_map(fn(Task $task) => $this->taskView->formatTaskData($task), $result['tasks']);

        // Format and return response
        return $this->paginationService->formatPaginationResponse($formattedTasks, $paginator);
    }

    public function getTaskById(int $id, int $userId): ?Task
    {
        // Business rule: Validate ID
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid task ID');
        }

        return $this->taskRepository->findByIdAndUserId($id, $userId);
    }

    public function markTaskAsDone(int $id, int $userId): bool
    {
        // Business rule: Validate ID
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid task ID');
        }

        // Business rule: Check if task exists and belongs to user
        $task = $this->taskRepository->findByIdAndUserId($id, $userId);
        if (!$task) {
            throw new Exception('Task not found');
        }

        // Business rule: Check if task is already done
        if ($task->isDone()) {
            throw new Exception('Task is already marked as done');
        }

        $result = $this->taskRepository->markAsDone($id, $userId);

        // Invalidate user-specific caches
        if ($result) {
            $this->cache->invalidateOnTaskStatusChange($id, $userId);
        }

        return $result;
    }

    public function deleteTask(int $id, int $userId): bool
    {
        // Business rule: Validate ID
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid task ID');
        }

        // Business logic: Check if task exists and belongs to user before deletion
        $task = $this->taskRepository->findByIdAndUserId($id, $userId);
        if (!$task) {
            throw new Exception('Task not found');
        }

        $result = $this->taskRepository->delete($id, $userId);

        // Invalidate user-specific caches
        if ($result) {
            $this->cache->invalidateOnTaskDelete($id, $userId);
        }

        return $result;
    }

    /**
     * @return array<Task>
     */
    public function getOverdueTasks(int $userId): array
    {
        // Try to get from cache first
        $userKey = 'user_' . $userId;
        $cachedTasks = $this->cache->getOverdueTasks($userKey);
        if ($cachedTasks !== null) {
            return $cachedTasks;
        }

        $tasks = $this->taskRepository->findOverdueByUserId($userId);

        // Cache the results
        $this->cache->setOverdueTasks($tasks, $userKey);

        return $tasks;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTask(int $id, int $userId, array $data): bool
    {
        // Business rule: Validate ID
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid task ID');
        }

        // Business logic: Check if task exists and belongs to user
        $existingTask = $this->taskRepository->findByIdAndUserId($id, $userId);
        if (!$existingTask) {
            return false;
        }

        // Use factory to create updated task
        $updatedTask = TaskFactory::createFromValidatedData($data, $userId);

        // Business rule: Due date cannot be in the past (unless task is already completed)
        $now = new DateTime();
        if ($updatedTask->getDueDate() <= $now && !$existingTask->isDone()) {
            throw new InvalidArgumentException('Due date must be in the future for pending tasks');
        }

        $result = $this->taskRepository->update(
            $id,
            $userId,
            $updatedTask->getTitle(),
            $updatedTask->getDescription(),
            $updatedTask->getDueDate()
        );

        // Invalidate user-specific caches
        if ($result) {
            $this->cache->invalidateOnTaskUpdate($id, $userId);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTaskStatistics(int $userId): array
    {
        // Try to get from cache first (user-specific cache key)
        $cachedStats = $this->cache->getStatistics('user_' . $userId);
        if ($cachedStats !== null) {
            return $cachedStats;
        }

        // Use optimized view-based statistics from repository
        $viewStats = $this->taskRepository->getUserStatistics($userId);

        // Format for backward compatibility and add business logic calculations
        $statistics = [
            'total_tasks' => $viewStats['total_tasks'],
            'completed_tasks' => $viewStats['completed_tasks'],
            'pending_tasks' => $viewStats['active_tasks'], // active_tasks = pending tasks in view
            'overdue_tasks' => $viewStats['overdue_tasks'],
            'completion_rate' => $viewStats['completion_rate_percent'],
            'average_completion_hours' => $viewStats['avg_completion_hours'],
            // Convert hours to days for backward compatibility
            'average_days_to_completion' => round($viewStats['avg_completion_hours'] / 24, 1),
            // Additional enhanced statistics from view
            'urgent_pending' => $viewStats['urgent_pending'],
            'high_priority_pending' => $viewStats['high_priority_pending'],
            'last_activity' => $viewStats['last_task_activity'],
            'tasks_created_this_week' => $viewStats['tasks_created_this_week']
        ];

        // Cache the result with user-specific key
        $this->cache->setStatistics($statistics, 'user_' . $userId);

        return $statistics;
    }

    /**
     * Get tasks by urgency level
     * @return array<Task>
     */
    public function getTasksByUrgency(int $userId, string $urgencyStatus): array
    {
        // Validate urgency status
        $validStatuses = ['overdue', 'due_soon', 'due_this_week', 'normal'];
        if (!in_array($urgencyStatus, $validStatuses)) {
            throw new InvalidArgumentException('Invalid urgency status');
        }

        return $this->taskRepository->findByUrgencyAndUserId($urgencyStatus, $userId);
    }
}
