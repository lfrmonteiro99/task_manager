<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use App\Validation\ValidationRule;
use DateTime;

class DateTimeRule extends ValidationRule
{
    public function passes(): bool
    {
        if ($this->value === null || $this->value === '') {
            return true; // Allow empty values, use Required rule to enforce non-empty
        }

        $format = $this->parameters[0] ?? 'Y-m-d H:i:s';
        $date = DateTime::createFromFormat($format, $this->value);

        return $date !== false && $date->format($format) === $this->value;
    }

    public function message(): string
    {
        $format = $this->parameters[0] ?? 'Y-m-d H:i:s';
        return "The {$this->field} field must be a valid date in format: {$format}.";
    }
}
