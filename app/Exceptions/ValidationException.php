<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\HttpStatusCode;
use Exception;

class ValidationException extends Exception
{
    /** @var array<string, string[]> */
    private array $errors;

    /**
     * @param array<string, string[]> $errors
     */
    public function __construct(
        array $errors,
        string $message = 'Validation failed',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->errors = $errors;
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getHttpStatusCode(): HttpStatusCode
    {
        return HttpStatusCode::UNPROCESSABLE_ENTITY;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'Validation Error',
            'message' => $this->getMessage(),
            'errors' => $this->errors,
            'status_code' => $this->getHttpStatusCode()->value
        ];
    }
}
