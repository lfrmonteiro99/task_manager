<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Task;
use DateTime;

interface TaskRepositoryInterface
{
    public function create(int $userId, string $title, string $description, DateTime $dueDate): bool;
    /**
     * @return array<Task>
     */
    public function findAll(): array;
    /**
     * @return array<Task>
     */
    public function findAllByUserId(int $userId): array;

    /**
     * Get paginated tasks for a user with total count and optional search/filtering
     * @param array<string, mixed> $searchParams
     * @return array{tasks: array<Task>, total: int}
     */
    public function findPaginatedByUserId(int $userId, int $limit, int $offset, array $searchParams = []): array;
    public function findById(int $id): ?Task;
    public function findByIdAndUserId(int $id, int $userId): ?Task;
    public function update(int $id, int $userId, string $title, string $description, DateTime $dueDate): bool;
    public function markAsDone(int $id, int $userId): bool;
    public function delete(int $id, int $userId): bool;
    /**
     * @return array<Task>
     */
    public function findOverdue(): array;
    /**
     * @return array<Task>
     */
    public function findOverdueByUserId(int $userId): array;

    /**
     * Get comprehensive user statistics using optimized view
     * @return array<string, mixed>
     */
    public function getUserStatistics(int $userId): array;

    /**
     * Get tasks by urgency status using optimized view
     * @return array<Task>
     */
    public function findByUrgencyAndUserId(string $urgencyStatus, int $userId): array;
}
