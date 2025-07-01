<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\Task;

interface TaskServiceInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function createTask(int $userId, array $data): bool;

    /**
     * @return array<Task>
     */
    public function getAllTasks(int $userId): array;

    /**
     * Get tasks for a user with optional pagination
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function getTasks(int $userId, array $queryParams = []): array;

    /**
     * Get paginated tasks for a user with optional search/filtering
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function getPaginatedTasks(int $userId, array $queryParams = []): array;

    public function getTaskById(int $id, int $userId): ?Task;

    /**
     * @param array<string, mixed> $data
     */
    public function updateTask(int $id, int $userId, array $data): bool;

    public function markTaskAsDone(int $id, int $userId): bool;

    public function deleteTask(int $id, int $userId): bool;

    /**
     * @return array<Task>
     */
    public function getOverdueTasks(int $userId): array;

    /**
     * @return array<string, mixed>
     */
    public function getTaskStatistics(int $userId): array;
}
