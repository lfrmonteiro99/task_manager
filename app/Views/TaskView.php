<?php

declare(strict_types=1);

namespace App\Views;

use App\Entities\Task;

class TaskView
{
    /**
     * @param array<Task> $tasks
     * @return array<string, mixed>
     */
    public function formatTaskList(array $tasks): array
    {
        return [
            'tasks' => array_map(fn(Task $task) => $this->formatTaskData($task), $tasks)
        ];
    }

    /**
     * @param array<Task> $tasks
     * @return array<string, mixed>
     */
    public function formatOverdueTaskList(array $tasks): array
    {
        return [
            'overdue_tasks' => array_map(fn(Task $task) => $this->formatTaskData($task, false), $tasks)
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSingleTask(Task $task): array
    {
        return [
            'task' => $this->formatTaskData($task)
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatTaskData(Task $task, bool $includeDoneStatus = true): array
    {
        $data = [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'due_date' => $task->getDueDate()->format('Y-m-d H:i:s'),
            'created_at' => $task->getSetDate()->format('Y-m-d H:i:s'),
            'priority' => $task->getPriority()->value,
            'priority_label' => $task->getPriority()->getLabel(),
            'status' => $task->getStatus()->value,
            'status_label' => $task->getStatus()->getLabel()
        ];

        if ($includeDoneStatus) {
            $data['done'] = $task->isDone();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function formatErrorResponse(string $message): array
    {
        return ['error' => $message];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function formatSuccessResponse(string $message, array $data = []): array
    {
        $response = ['success' => true, 'message' => $message];
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        return $response;
    }
}
