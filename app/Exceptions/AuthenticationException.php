<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\HttpStatusCode;
use Exception;

class AuthenticationException extends Exception
{
    private string $authenticationType;

    public function __construct(
        string $authenticationType = 'general',
        string $message = 'Authentication failed',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->authenticationType = $authenticationType;
        parent::__construct($message, $code, $previous);
    }

    public function getAuthenticationType(): string
    {
        return $this->authenticationType;
    }

    public function getHttpStatusCode(): HttpStatusCode
    {
        return HttpStatusCode::UNAUTHORIZED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'Authentication Error',
            'message' => $this->getMessage(),
            'authentication_type' => $this->authenticationType,
            'status_code' => $this->getHttpStatusCode()->value
        ];
    }
}
