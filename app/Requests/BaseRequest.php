<?php

declare(strict_types=1);

namespace App\Requests;

use DateTime;
use Exception;
use InvalidArgumentException;

abstract class BaseRequest
{
    /** @var array<string, mixed> */
    protected array $data;

    /** @var array<string, string> */
    protected array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        // Sanitize input data if method exists in child class
        if (method_exists($this, 'sanitizeInput')) {
            $this->data = $this->sanitizeInput($data);
        } else {
            $this->data = $data;
        }
    }

    /**
     * @return array<string, string>
     */
    abstract public function rules(): array;

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    public function validate(): bool
    {
        $this->errors = [];
        $rules = $this->rules();

        foreach ($rules as $field => $ruleString) {
            $this->validateField($field, $ruleString);
        }

        return empty($this->errors);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValidatedData(): array
    {
        if (!$this->validate()) {
            throw new InvalidArgumentException('Validation failed');
        }

        $validatedData = [];
        $rules = $this->rules();

        foreach (array_keys($rules) as $field) {
            if (isset($this->data[$field])) {
                $validatedData[$field] = $this->data[$field];
            }
        }

        return $validatedData;
    }

    protected function validateField(string $field, string $ruleString): void
    {
        $rules = explode('|', $ruleString);
        $value = $this->data[$field] ?? null;

        foreach ($rules as $rule) {
            $this->applyRule($field, $rule, $value);
        }
    }

    /**
     * @param mixed $value
     */
    protected function applyRule(string $field, string $rule, $value): void
    {
        $ruleParts = explode(':', $rule);
        $ruleName = $ruleParts[0];
        $ruleParam = $ruleParts[1] ?? null;

        switch ($ruleName) {
            case 'required':
                if (is_null($value) || (is_string($value) && trim($value) === '')) {
                    $this->addError($field, $ruleName, 'The ' . $field . ' field is required.');
                }
                break;

            case 'string':
                if (!is_null($value) && !is_string($value)) {
                    $this->addError($field, $ruleName, 'The ' . $field . ' must be a string.');
                }
                break;

            case 'min':
                if (!is_null($value) && is_string($value) && strlen(trim($value)) < (int)$ruleParam) {
                    $this->addError(
                        $field,
                        $ruleName,
                        'The ' . $field . ' must be at least ' . $ruleParam . ' characters.'
                    );
                }
                break;

            case 'max':
                if (!is_null($value) && is_string($value) && strlen(trim($value)) > (int)$ruleParam) {
                    $this->addError(
                        $field,
                        $ruleName,
                        'The ' . $field . ' may not be greater than ' . $ruleParam . ' characters.'
                    );
                }
                break;

            case 'date':
                if (!is_null($value)) {
                    try {
                        new DateTime($value);
                    } catch (Exception $e) {
                        $this->addError($field, $ruleName, 'The ' . $field . ' must be a valid date.');
                    }
                }
                break;

            case 'after':
                if (!is_null($value)) {
                    try {
                        $date = new DateTime($value);
                        $compareDate = $ruleParam === 'now' ? new DateTime() : new DateTime($ruleParam ?? 'now');
                        if ($date <= $compareDate) {
                            $this->addError(
                                $field,
                                $ruleName,
                                'The ' . $field . ' must be after ' . ($ruleParam === 'now' ? 'now' : $ruleParam) . '.'
                            );
                        }
                    } catch (Exception $e) {
                        // Date validation will catch invalid dates
                    }
                }
                break;

            case 'before':
                if (!is_null($value)) {
                    try {
                        $date = new DateTime($value);
                        $compareDate = new DateTime($ruleParam ?? 'now');
                        if ($date >= $compareDate) {
                            $this->addError($field, $ruleName, 'The ' . $field . ' must be before ' . $ruleParam . '.');
                        }
                    } catch (Exception $e) {
                        // Date validation will catch invalid dates
                    }
                }
                break;
        }
    }

    protected function addError(string $field, string $rule, string $defaultMessage): void
    {
        $customMessages = $this->messages();
        $messageKey = $field . '.' . $rule;

        $message = $customMessages[$messageKey] ?? $defaultMessage;

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = '';
        }

        $this->errors[$field] .= ($this->errors[$field] ? ', ' : '') . $message;
    }
}
