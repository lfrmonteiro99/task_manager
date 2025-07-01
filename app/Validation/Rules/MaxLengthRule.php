<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use App\Validation\ValidationRule;

class MaxLengthRule extends ValidationRule
{
    public function passes(): bool
    {
        if ($this->value === null) {
            return true; // Allow null values, use Required rule to enforce non-null
        }

        $maxLength = $this->parameters[0] ?? 255;

        if (is_string($this->value)) {
            return mb_strlen($this->value, 'UTF-8') <= $maxLength;
        }

        if (is_array($this->value)) {
            return count($this->value) <= $maxLength;
        }

        return true;
    }

    public function message(): string
    {
        $maxLength = $this->parameters[0] ?? 255;
        return "The {$this->field} field must not exceed {$maxLength} characters.";
    }
}
