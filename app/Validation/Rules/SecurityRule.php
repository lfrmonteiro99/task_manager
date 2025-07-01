<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use App\Validation\ValidationRule;
use App\Utils\InputSanitizer;

class SecurityRule extends ValidationRule
{
    public function passes(): bool
    {
        if ($this->value === null || $this->value === '') {
            return true; // Allow empty values
        }

        if (!is_string($this->value)) {
            return true; // Only validate strings
        }

        // Check for SQL injection patterns
        if (InputSanitizer::detectSqlInjection($this->value)) {
            return false;
        }

        // Check for XSS patterns
        if (InputSanitizer::detectXss($this->value)) {
            return false;
        }

        return true;
    }

    public function message(): string
    {
        return "The {$this->field} field contains potentially malicious content.";
    }
}
