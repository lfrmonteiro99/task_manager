<?php

declare(strict_types=1);

namespace App\Validation;

use App\Validation\Rules\RequiredRule;
use App\Validation\Rules\MaxLengthRule;
use App\Validation\Rules\EmailRule;
use App\Validation\Rules\DateTimeRule;
use App\Validation\Rules\SecurityRule;
use InvalidArgumentException;

class Validator
{
    /** @var array<string, mixed> */
    private array $data;
    /** @var array<string, array<string>> */
    private array $rules = [];
    /** @var array<string, string[]> */
    private array $errors = [];
    /** @var array<string, string> */
    private array $customMessages = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Add validation rules for a field
     */
    /**
     * @param array<string> $rules
     */
    public function rules(string $field, array $rules): self
    {
        $this->rules[$field] = $rules;
        return $this;
    }

    /**
     * Add custom error messages
     */
    /**
     * @param array<string, string> $messages
     */
    public function messages(array $messages): self
    {
        $this->customMessages = array_merge($this->customMessages, $messages);
        return $this;
    }

    /**
     * Validate all fields
     */
    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $this->validateField($field, $value, $fieldRules);
        }

        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    /**
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get validated data (only fields with rules)
     */
    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $validated = [];

        foreach ($this->rules as $field => $fieldRules) {
            if (isset($this->data[$field])) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    /**
     * Validate a single field
     */
    /**
     * @param array<string> $rules
     */
    private function validateField(string $field, mixed $value, array $rules): void
    {
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $rule = $this->parseRule($rule);
            }

            if (!($rule instanceof ValidationRule)) {
                throw new InvalidArgumentException("Invalid validation rule for field: {$field}");
            }

            $ruleInstance = new ($rule::class)($field, $value, $rule->getParameters());

            if (!$ruleInstance->passes()) {
                $message = $this->getCustomMessage($field, $rule::class) ?? $ruleInstance->message();
                $this->addError($field, $message);

                // Stop on first error for this field
                break;
            }
        }
    }

    /**
     * Parse string rule into ValidationRule instance
     */
    private function parseRule(string $rule): ValidationRule
    {
        if (strpos($rule, ':') !== false) {
            [$ruleName, $parameters] = explode(':', $rule, 2);
            $parameters = explode(',', $parameters);
        } else {
            $ruleName = $rule;
            $parameters = [];
        }

        return match ($ruleName) {
            'required' => new RequiredRule('', null, $parameters),
            'max' => new MaxLengthRule('', null, $parameters),
            'email' => new EmailRule('', null, $parameters),
            'datetime' => new DateTimeRule('', null, $parameters),
            'security' => new SecurityRule('', null, $parameters),
            default => throw new InvalidArgumentException("Unknown validation rule: {$ruleName}")
        };
    }

    /**
     * Get custom error message
     */
    private function getCustomMessage(string $field, string $ruleClass): ?string
    {
        $ruleKey = $this->getRuleKey($ruleClass);

        // Check for field-specific message
        $fieldKey = "{$field}.{$ruleKey}";
        if (isset($this->customMessages[$fieldKey])) {
            return $this->customMessages[$fieldKey];
        }

        // Check for general rule message
        if (isset($this->customMessages[$ruleKey])) {
            return str_replace(':field', $field, $this->customMessages[$ruleKey]);
        }

        return null;
    }

    /**
     * Get rule key from class name
     */
    private function getRuleKey(string $ruleClass): string
    {
        $parts = explode('\\', $ruleClass);
        $className = end($parts);

        return strtolower(str_replace('Rule', '', $className));
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Static factory method for quick validation
     */
    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string>> $rules
     * @param array<string, string> $messages
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        $validator = new self($data);

        foreach ($rules as $field => $fieldRules) {
            $validator->rules($field, $fieldRules);
        }

        if (!empty($messages)) {
            $validator->messages($messages);
        }

        return $validator;
    }
}
