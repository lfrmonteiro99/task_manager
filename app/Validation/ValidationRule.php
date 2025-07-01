<?php

declare(strict_types=1);

namespace App\Validation;

abstract class ValidationRule
{
    protected string $field;
    protected mixed $value;
    /** @var array<mixed> */
    protected array $parameters;

    /**
     * @param array<mixed> $parameters
     */
    public function __construct(string $field, mixed $value, array $parameters = [])
    {
        $this->field = $field;
        $this->value = $value;
        $this->parameters = $parameters;
    }

    /**
     * Check if the validation rule passes
     */
    abstract public function passes(): bool;

    /**
     * Get the validation error message
     */
    abstract public function message(): string;

    /**
     * Get the field name
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * Get the field value
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * Get rule parameters
     */
    /**
     * @return array<mixed>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
