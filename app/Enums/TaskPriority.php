<?php

declare(strict_types=1);

namespace App\Enums;

enum TaskPriority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case URGENT = 'urgent';

    public function getLabel(): string
    {
        return match ($this) {
            self::LOW => 'Low Priority',
            self::MEDIUM => 'Medium Priority',
            self::HIGH => 'High Priority',
            self::URGENT => 'Urgent',
        };
    }

    public function getWeight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::URGENT => 4,
        };
    }

    public static function fromString(string $priority): self
    {
        return match (strtolower($priority)) {
            'low' => self::LOW,
            'medium' => self::MEDIUM,
            'high' => self::HIGH,
            'urgent' => self::URGENT,
            default => self::MEDIUM,
        };
    }
}
