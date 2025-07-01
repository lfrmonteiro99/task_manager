<?php

declare(strict_types=1);

namespace App\Validation\Rules;

use App\Validation\ValidationRule;

class RequiredRule extends ValidationRule
{
    public function passes(): bool
    {
        if ($this->value === null || $this->value === '') {
            return false;
        }

        if (is_array($this->value) && empty($this->value)) {
            return false;
        }

        return true;
    }

    public function message(): string
    {
        return "The {$this->field} field is required.";
    }
}
