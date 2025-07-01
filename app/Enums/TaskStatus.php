<?php

declare(strict_types=1);

namespace App\Enums;

use DateTime;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case OVERDUE = 'overdue';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
            self::OVERDUE => 'Overdue',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::PENDING, self::OVERDUE => true,
            self::COMPLETED, self::CANCELLED => false,
        };
    }

    public static function fromBooleanAndDate(bool $done, DateTime $dueDate): self
    {
        if ($done) {
            return self::COMPLETED;
        }

        $now = new DateTime();
        return $dueDate < $now ? self::OVERDUE : self::PENDING;
    }
}
