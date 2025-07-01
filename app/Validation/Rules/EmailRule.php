<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use App\Validation\ValidationRule;

class EmailRule extends ValidationRule
{
    public function passes(): bool
    {
        if ($this->value === null || $this->value === '') {
            return true; // Allow empty values, use Required rule to enforce non-empty
        }

        return filter_var($this->value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function message(): string
    {
        return "The {$this->field} field must be a valid email address.";
    }
}
