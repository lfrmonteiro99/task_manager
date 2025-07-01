<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Task;
use App\Factories\TaskFactory;
use App\Models\Database;
use App\Cache\TaskCacheManager;
use App\Repositories\Filters\FilterChain;
use App\Repositories\Filters\FilterFactory;
use App\Services\TaskRetryService;
use DateTime;
use PDO;
use PDOException;
use Exception;

class TaskRepository implements TaskRepositoryInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly TaskCacheManager $cache,
        private readonly TaskRetryService $retryService = new TaskRetryService()
    ) {
    }

    public function create(int $userId, string $title, string $description, DateTime $dueDate): bool
    {
        $context = [
            'user_id' => $userId,
            'Title' => $title,
            'operation' => 'task_create'
        ];

        return $this->retryService->executeTaskCreation(function () use ($userId, $title, $description, $dueDate) {
            try {
                $sql = "INSERT INTO tasks (user_id, title, description, due_date, created_at) " .
                       "VALUES (?, ?, ?, ?, NOW())";
                $stmt = $this->database->getConnection()->prepare($sql);

                $result = $stmt->execute([
                    $userId,
                    $title,
                    $description,
                    $dueDate->format('Y-m-d H:i:s')
                ]);

                // Handle cache invalidation with separate retry logic
                if ($result) {
                    $this->invalidateCacheWithRetry($userId, 'create');
                }

                return $result;
            } catch (PDOException $e) {
                throw new Exception("Failed to create task: " . $e->getMessage());
            }
        }, $context);
    }

    /**
     * @return array<Task>
     */
    public function findAll(): array
    {
        throw new Exception("Use findAllByUserId() instead of findAll() for user-scoped queries");
    }

    /**
     * @return array<Task>
     */
    public function findAllByUserId(int $userId): array
    {
        // Try to get from cache first (user-specific cache key)
        $cachedTasks = $this->cache->getAllTasks('user_' . $userId);
        if ($cachedTasks !== null) {
            return $cachedTasks;
        }

        try {
            // Use direct table query for task listing
            $sql = "SELECT id, user_id, title, description, 
                           due_date, status, priority, created_at,
                           updated_at, done
                    FROM tasks 
                    WHERE user_id = ?
                    ORDER BY due_date ASC";

            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$userId]);

            $tasks = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $task = $this->mapRowToTaskFromView($row);
                $tasks[] = $task;

                // Cache individual task as well
                $this->cache->setTask($task);
            }

            // Cache the result with user-specific key
            $this->cache->setAllTasks($tasks, 'user_' . $userId);

            return $tasks;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve tasks: " . $e->getMessage());
        }
    }

    /**
     * Get paginated tasks for a user with total count and optional search/filtering
     * @param array<string, mixed> $searchParams
     * @return array{tasks: array<Task>, total: int}
     */
    public function findPaginatedByUserId(int $userId, int $limit, int $offset, array $searchParams = []): array
    {
        try {
            $queryBuilder = $this->createQueryBuilder($userId, $searchParams);

            // Get total count for pagination
            $totalCount = $this->getTotalCount($queryBuilder);

            // Get paginated results
            $tasks = $this->getPaginatedResults($queryBuilder, $limit, $offset);

            return [
                'tasks' => $tasks,
                'total' => $totalCount
            ];
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve paginated tasks: " . $e->getMessage());
        }
    }

    /**
     * Create query builder with WHERE conditions and parameters
     * @param array<string, mixed> $searchParams
     * @return array{table: string, where: string, params: array<mixed>, orderBy: string, useFullTable: bool}
     */
    private function createQueryBuilder(int $userId, array $searchParams): array
    {
        // Determine table to use based on search parameters
        $useFullTable = $this->shouldUseFullTable($searchParams);
        $tableName = 'tasks';

        // Build WHERE clause and parameters
        $whereBuilder = $this->buildWhereClause($userId, $searchParams, $useFullTable);

        // Build ORDER BY clause
        $orderBy = $this->buildOrderByClause($searchParams);

        return [
            'table' => $tableName,
            'where' => $whereBuilder['clause'],
            'params' => $whereBuilder['params'],
            'orderBy' => $orderBy,
            'useFullTable' => $useFullTable
        ];
    }

    /**
     * Determine if we should use the full tasks table instead of the optimized view
     * @param array<string, mixed> $searchParams
     */
    private function shouldUseFullTable(array $searchParams): bool
    {
        return !empty($searchParams['status']) && $searchParams['status'] === 'completed';
    }

    /**
     * Build WHERE clause with parameters using Filter Chain pattern
     * @param array<string, mixed> $searchParams
     * @return array{clause: string, params: array<mixed>}
     */
    private function buildWhereClause(int $userId, array $searchParams, bool $useFullTable): array
    {
        $conditions = ['user_id = ?'];
        $parameters = [$userId];

        // Use Strategy + Chain of Responsibility patterns for filters
        $filterChain = FilterFactory::createForTaskSearch();
        $filterChain->apply($conditions, $parameters, $searchParams, $useFullTable);

        return [
            'clause' => implode(' AND ', $conditions),
            'params' => $parameters
        ];
    }


    /**
     * Get total count for pagination
     * @param array{
     *     table: string,
     *     where: string,
     *     params: array<mixed>,
     *     orderBy: string,
     *     useFullTable: bool
     * } $queryBuilder
     */
    private function getTotalCount(array $queryBuilder): int
    {
        $countSql = "SELECT COUNT(*) as total FROM {$queryBuilder['table']} WHERE {$queryBuilder['where']}";
        $countStmt = $this->database->getConnection()->prepare($countSql);
        $countStmt->execute($queryBuilder['params']);
        return (int) $countStmt->fetchColumn();
    }

    /**
     * Get paginated results
     * @param array{
     *     table: string,
     *     where: string,
     *     params: array<mixed>,
     *     orderBy: string,
     *     useFullTable: bool
     * } $queryBuilder
     * @return array<Task>
     */
    private function getPaginatedResults(array $queryBuilder, int $limit, int $offset): array
    {
        $selectClause = $this->buildSelectClause($queryBuilder['useFullTable']);

        $sql = "SELECT {$selectClause}
                FROM {$queryBuilder['table']} 
                WHERE {$queryBuilder['where']}
                {$queryBuilder['orderBy']}
                LIMIT ? OFFSET ?";

        $parameters = array_merge($queryBuilder['params'], [$limit, $offset]);

        $stmt = $this->database->getConnection()->prepare($sql);
        $stmt->execute($parameters);

        $tasks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tasks[] = $this->mapRowToTaskFromView($row);
        }

        return $tasks;
    }

    /**
     * Build SELECT clause based on table type
     */
    private function buildSelectClause(bool $useFullTable): string
    {
        $baseFields = "id as id, user_id, title as title, description as description, 
                       due_date as due_date, status, priority, created_at, updated_at";

        if ($useFullTable) {
            return "{$baseFields}, done as done,
                    CASE 
                        WHEN due_date < NOW() THEN 'overdue'
                        WHEN due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
                        WHEN due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'due_this_week'
                        ELSE 'normal'
                    END as urgency_status,
                    TIMESTAMPDIFF(HOUR, NOW(), due_date) as hours_remaining";
        }

        return "{$baseFields}, 
                CASE 
                    WHEN due_date < NOW() THEN 'overdue'
                    WHEN due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
                    WHEN due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'due_this_week'
                    ELSE 'normal'
                END as urgency_status,
                TIMESTAMPDIFF(HOUR, NOW(), due_date) as hours_remaining,
                CASE WHEN status = 'completed' THEN 1 ELSE 0 END as done";
    }

    public function findById(int $id): ?Task
    {
        throw new Exception("Use findByIdAndUserId() instead of findById() for user-scoped queries");
    }

    public function findByIdAndUserId(int $id, int $userId): ?Task
    {
        // Try to get from cache first
        $cachedTask = $this->cache->getTask($id);
        if ($cachedTask !== null && $cachedTask->getUserId() === $userId) {
            return $cachedTask;
        }

        try {
            $sql = "SELECT id as id, user_id, title as title, description as description, 
                           due_date as due_date, done as done, created_at 
                    FROM tasks 
                    WHERE id = ? AND user_id = ?";

            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$id, $userId]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $task = $this->mapRowToTask($row);
                // Cache the task
                $this->cache->setTask($task);
                return $task;
            }

            return null;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve task: " . $e->getMessage());
        }
    }

    public function markAsDone(int $id, int $userId): bool
    {
        $context = [
            'task_id' => $id,
            'user_id' => $userId,
            'operation' => 'mark_done'
        ];

        return $this->retryService->executeTaskUpdate(function () use ($id, $userId) {
            try {
                $sql = "UPDATE tasks SET done = 1 WHERE id = ? AND user_id = ?";
                $stmt = $this->database->getConnection()->prepare($sql);

                $result = $stmt->execute([$id, $userId]);

                // Handle cache invalidation with separate retry logic
                if ($result) {
                    $this->invalidateCacheWithRetry($userId, 'Status_change', $id);
                }

                return $result;
            } catch (PDOException $e) {
                throw new Exception("Failed to mark task as Done: " . $e->getMessage());
            }
        }, $context);
    }

    public function delete(int $id, int $userId): bool
    {
        $context = [
            'task_id' => $id,
            'user_id' => $userId,
            'operation' => 'delete'
        ];

        return $this->retryService->executeTaskDeletion(function () use ($id, $userId) {
            try {
                $sql = "DELETE FROM tasks WHERE id = ? AND user_id = ?";
                $stmt = $this->database->getConnection()->prepare($sql);

                $result = $stmt->execute([$id, $userId]);

                // Handle cache invalidation with separate retry logic
                if ($result) {
                    $this->invalidateCacheWithRetry($userId, 'delete', $id);
                }

                return $result;
            } catch (PDOException $e) {
                throw new Exception("Failed to delete task: " . $e->getMessage());
            }
        }, $context);
    }

    public function update(int $id, int $userId, string $title, string $description, DateTime $dueDate): bool
    {
        try {
            $sql = "UPDATE tasks SET title = ?, description = ?, due_date = ? WHERE id = ? AND user_id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);

            $result = $stmt->execute([
                $title,
                $description,
                $dueDate->format('Y-m-d H:i:s'),
                $id,
                $userId
            ]);

            // Invalidate caches on successful update
            if ($result) {
                $this->cache->invalidateOnTaskUpdate($id, $userId);
            }

            return $result;
        } catch (PDOException $e) {
            throw new Exception("Failed to update task: " . $e->getMessage());
        }
    }

    /**
     * @return array<Task>
     */
    public function findOverdue(): array
    {
        throw new Exception("Use findOverdueByUserId() instead of findOverdue() for user-scoped queries");
    }

    /**
     * @return array<Task>
     */
    public function findOverdueByUserId(int $userId): array
    {
        // Try to get from cache first (user-specific cache key)
        $cachedTasks = $this->cache->getOverdueTasks('user_' . $userId);
        if ($cachedTasks !== null) {
            return $cachedTasks;
        }

        try {
            // Get overdue tasks directly from tasks table
            $sql = "SELECT id, user_id, title, description, 
                           due_date, status, priority, created_at,
                           updated_at, done
                    FROM tasks 
                    WHERE user_id = ? AND due_date < NOW() AND done = 0
                    ORDER BY due_date ASC";

            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$userId]);

            $tasks = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $task = $this->mapRowToTaskFromView($row);
                $tasks[] = $task;

                // Cache individual task as well
                $this->cache->setTask($task);
            }

            // Cache the result with user-specific key
            $this->cache->setOverdueTasks($tasks, 'user_' . $userId);

            return $tasks;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve overdue tasks: " . $e->getMessage());
        }
    }

    /**
     * Get comprehensive user statistics using optimized view
     * @return array<string, mixed>
     */
    public function getUserStatistics(int $userId): array
    {
        try {
            $sql = "SELECT * FROM user_task_statistics WHERE user_id = ?";
            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$userId]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'user_id' => (int)$row['user_id'],
                    'user_name' => $row['name'],
                    'user_email' => $row['email'],
                    'total_tasks' => (int)$row['total_tasks'],
                    'completed_tasks' => (int)$row['completed_tasks'],
                    'active_tasks' => (int)$row['active_tasks'],
                    'overdue_tasks' => (int)$row['overdue_tasks'],
                    'urgent_pending' => (int)$row['urgent_pending'],
                    'high_priority_pending' => (int)$row['high_priority_pending'],
                    'avg_completion_hours' => (float)$row['avg_completion_hours'],
                    'completion_rate_percent' => (float)$row['completion_rate_percent'],
                    'last_task_activity' => $row['last_task_activity'],
                    'tasks_created_this_week' => (int)$row['tasks_created_this_week']
                ];
            }

            // Return empty statistics if user has no data
            return [
                'user_id' => $userId,
                'user_name' => '',
                'user_email' => '',
                'total_tasks' => 0,
                'completed_tasks' => 0,
                'active_tasks' => 0,
                'overdue_tasks' => 0,
                'urgent_pending' => 0,
                'high_priority_pending' => 0,
                'avg_completion_hours' => 0.0,
                'completion_rate_percent' => 0.0,
                'last_task_activity' => null,
                'tasks_created_this_week' => 0
            ];
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve user statistics: " . $e->getMessage());
        }
    }

    /**
     * Get tasks by urgency Status using optimized view
     * @return array<Task>
     */
    public function findByUrgencyAndUserId(string $urgencyStatus, int $userId): array
    {
        try {
            $sql = "SELECT id as id, user_id, title as title, description as description, 
                           due_date as due_date, status, priority, created_at,
                           updated_at,
                           CASE 
                               WHEN due_date < NOW() THEN 'overdue'
                               WHEN due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
                               WHEN due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'due_this_week'
                               ELSE 'normal'
                           END as urgency_status,
                           TIMESTAMPDIFF(HOUR, NOW(), due_date) as hours_remaining,
                           CASE WHEN status = 'completed' THEN 1 ELSE 0 END as done
                    FROM tasks 
                    WHERE user_id = ? AND (
                        CASE 
                            WHEN due_date < NOW() THEN 'overdue'
                            WHEN due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
                            WHEN due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'due_this_week'
                            ELSE 'normal'
                        END
                    ) = ?
                    ORDER BY due_date ASC";

            $stmt = $this->database->getConnection()->prepare($sql);
            $stmt->execute([$userId, $urgencyStatus]);

            $tasks = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tasks[] = $this->mapRowToTaskFromView($row);
            }

            return $tasks;
        } catch (PDOException $e) {
            throw new Exception("Failed to retrieve tasks by urgency: " . $e->getMessage());
        }
    }


    /**
     * Build ORDER BY clause based on search parameters
     * @param array<string, mixed> $searchParams
     */
    private function buildOrderByClause(array $searchParams): string
    {
        $validSortFields = [
            'title' => 'title',
            'due_date' => 'due_date',
            'priority' => 'priority',
            'status' => 'status',
            'created_at' => 'created_at',
            'urgency' => 'urgency_status'
        ];

        $sortField = $searchParams['sort_by'] ?? 'due_date';
        $sortDirection = strtoupper($searchParams['sort_direction'] ?? 'ASC');

        // Validate sort direction
        if (!in_array($sortDirection, ['ASC', 'DESC'])) {
            $sortDirection = 'ASC';
        }

        // Validate sort field
        if (!isset($validSortFields[$sortField])) {
            $sortField = 'due_date';
        }

        $dbField = $validSortFields[$sortField];

        // Special handling for priority sorting (high -> medium -> low -> urgent)
        if ($sortField === 'priority') {
            $priorityOrder = $sortDirection === 'ASC'
                ? "FIELD(priority, 'urgent', 'high', 'medium', 'low')"
                : "FIELD(priority, 'low', 'medium', 'high', 'urgent')";
            return "ORDER BY {$priorityOrder}";
        }

        // Special handling for urgency status
        if ($sortField === 'urgency') {
            $urgencyOrder = $sortDirection === 'ASC'
                ? "FIELD(urgency_status, 'overdue', 'due_soon', 'due_this_week', 'normal')"
                : "FIELD(urgency_status, 'normal', 'due_this_week', 'due_soon', 'overdue')";
            return "ORDER BY {$urgencyOrder}";
        }

        return "ORDER BY {$dbField} {$sortDirection}";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToTask(array $row): Task
    {
        return TaskFactory::createFromDatabaseRow($row);
    }

    /**
     * Map row from optimized view to Task entity
     * @param array<string, mixed> $row
     */
    private function mapRowToTaskFromView(array $row): Task
    {
        // Create basic task from standard fields
        $task = TaskFactory::createFromDatabaseRow($row);

        // Add additional view-specific properties if the Task entity supports them
        // For now, we'll use the standard mapping but this can be extended
        return $task;
    }

    /**
     * Handle cache invalidation with retry logic
     */
    private function invalidateCacheWithRetry(int $userId, string $operation, ?int $taskId = null): void
    {
        try {
            $this->retryService->executeCacheOperation(function () use ($userId, $operation, $taskId) {
                switch ($operation) {
                    case 'create':
                        $this->cache->invalidateOnTaskCreate($userId);
                        break;
                    case 'Status_change':
                        if ($taskId !== null) {
                            $this->cache->invalidateOnTaskStatusChange($taskId, $userId);
                        }
                        break;
                    case 'delete':
                        if ($taskId !== null) {
                            $this->cache->invalidateOnTaskDelete($taskId, $userId);
                        }
                        break;
                    case 'update':
                        if ($taskId !== null) {
                            $this->cache->invalidateOnTaskUpdate($taskId, $userId);
                        }
                        break;
                    default:
                        // Fallback: invalidate general user cache
                        $this->cache->invalidateOnTaskCreate($userId);
                }
                return true;
            }, [
                'user_id' => $userId,
                'operation' => $operation,
                'task_id' => $taskId
            ]);
        } catch (Exception $e) {
            // Cache invalidation failures shouldn't break the main operation
            // Log the error but continue
            error_log("Cache invalidation failed for user {$userId}, operation {$operation}: " . $e->getMessage());
        }
    }
}
