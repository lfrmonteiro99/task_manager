<?php

declare(strict_types=1);

namespace App\Factories;

use App\Entities\Task;
use App\Enums\TaskPriority;
use DateTime;
use InvalidArgumentException;

class TaskFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function create(array $data): Task
    {
        $task = new Task();

        if (isset($data['id'])) {
            $task->setId((int) $data['id']);
        }

        if (isset($data['user_id'])) {
            $task->setUserId((int) $data['user_id']);
        }

        if (isset($data['title'])) {
            $task->setTitle($data['title']);
        }

        if (isset($data['description'])) {
            $task->setDescription($data['description']);
        }

        if (isset($data['due_date'])) {
            $dueDate = $data['due_date'] instanceof DateTime
                ? $data['due_date']
                : new DateTime($data['due_date']);
            $task->setDueDate($dueDate);
        }

        if (isset($data['done'])) {
            $task->setDone((bool) $data['done']);
        }

        if (isset($data['created_at'])) {
            $setDate = $data['created_at'] instanceof DateTime
                ? $data['created_at']
                : new DateTime($data['created_at']);
            $task->setSetDate($setDate);
        }

        if (isset($data['priority'])) {
            $priority = $data['priority'] instanceof TaskPriority
                ? $data['priority']
                : TaskPriority::from($data['priority']);
            $task->setPriority($priority);
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function createFromDatabaseRow(array $row): Task
    {
        return self::create([
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'due_date' => new DateTime($row['due_date']),
            'done' => (bool) $row['done'],
            'created_at' => new DateTime($row['created_at'])
        ]);
    }

    /**
     * @param array<string, mixed> $validatedData
     */
    public static function createFromValidatedData(array $validatedData, int $userId): Task
    {
        $task = new Task();

        $task->setUserId($userId)
             ->setTitle($validatedData['title'])
             ->setDescription($validatedData['description'])
             ->setDueDate(new DateTime($validatedData['due_date']));

        if (isset($validatedData['priority'])) {
            $priority = TaskPriority::from($validatedData['priority']);
            $task->setPriority($priority);
        }

        return $task;
    }
}
