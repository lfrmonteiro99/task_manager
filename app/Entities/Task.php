<?php

declare(strict_types=1);

namespace App\Entities;

use App\Enums\TaskStatus;
use App\Enums\TaskPriority;
use DateTime;

class Task
{
    private int $id;
    private int $userId;
    private string $title;
    private string $description;
    private DateTime $dueDate;
    private bool $done;
    private DateTime $setDate;
    private TaskPriority $priority;
    private TaskStatus $status;

    public function __construct()
    {
        $this->done = false;
        $this->setDate = new DateTime();
        $this->priority = TaskPriority::MEDIUM;
        $this->status = TaskStatus::PENDING;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setDueDate(DateTime $dueDate): self
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function setDone(bool $done): self
    {
        $this->done = $done;
        return $this;
    }

    public function setSetDate(DateTime $setDate): self
    {
        $this->setDate = $setDate;
        return $this;
    }

    public function setPriority(TaskPriority $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function setStatus(TaskStatus $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDueDate(): DateTime
    {
        return $this->dueDate;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function getSetDate(): DateTime
    {
        return $this->setDate;
    }

    public function getPriority(): TaskPriority
    {
        return $this->priority;
    }

    public function getStatus(): TaskStatus
    {
        return $this->status;
    }

    public function getComputedStatus(): TaskStatus
    {
        return TaskStatus::fromBooleanAndDate($this->done, $this->dueDate);
    }

    public function markAsDone(): self
    {
        $this->done = true;
        $this->status = TaskStatus::COMPLETED;
        return $this;
    }

    public function markAsCancelled(): self
    {
        $this->status = TaskStatus::CANCELLED;
        return $this;
    }

    public function updateStatusBasedOnDueDate(): self
    {
        if (!$this->done && $this->isOverdue()) {
            $this->status = TaskStatus::OVERDUE;
        } elseif ($this->done) {
            $this->status = TaskStatus::COMPLETED;
        } else {
            $this->status = TaskStatus::PENDING;
        }
        return $this;
    }

    public function isOverdue(): bool
    {
        return $this->dueDate < new DateTime() && !$this->done;
    }

    public function getDaysUntilDue(): int
    {
        $now = new DateTime();
        $diff = $now->diff($this->dueDate);
        $days = $diff->days !== false ? $diff->days : 0;
        return $this->dueDate > $now ? $days : -$days;
    }

    public function getDaysSinceCreation(): int
    {
        $now = new DateTime();
        $diff = $this->setDate->diff($now)->days;
        return $diff !== false ? $diff : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->dueDate->format('Y-m-d H:i:s'),
            'done' => $this->done,
            'created_at' => $this->setDate->format('Y-m-d H:i:s'),
            'priority' => $this->priority->value,
            'priority_label' => $this->priority->getLabel(),
            'status' => $this->getStatus()->value,
            'status_label' => $this->getStatus()->getLabel()
        ];
    }
}
