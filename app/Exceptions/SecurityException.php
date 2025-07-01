<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\HttpStatusCode;
use Exception;

class SecurityException extends Exception
{
    private string $securityType;
    /** @var array<string, mixed> */
    private array $context;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $securityType,
        string $message = 'Security violation detected',
        array $context = [],
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->securityType = $securityType;
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getSecurityType(): string
    {
        return $this->securityType;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getHttpStatusCode(): HttpStatusCode
    {
        return HttpStatusCode::BAD_REQUEST;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'error' => 'Security Violation',
            'message' => $this->getMessage(),
            'security_type' => $this->securityType,
            'status_code' => $this->getHttpStatusCode()->value
        ];
    }
}
