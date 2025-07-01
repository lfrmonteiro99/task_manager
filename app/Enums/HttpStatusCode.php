<?php

declare(strict_types=1);

namespace App\Enums;

enum HttpStatusCode: int
{
    case OK = 200;
    case CREATED = 201;
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case CONFLICT = 409;
    case UNPROCESSABLE_ENTITY = 422;
    case TOO_MANY_REQUESTS = 429;
    case INTERNAL_SERVER_ERROR = 500;

    public function getMessage(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::CREATED => 'Created',
            self::BAD_REQUEST => 'Bad Request',
            self::UNAUTHORIZED => 'Unauthorized',
            self::FORBIDDEN => 'Forbidden',
            self::NOT_FOUND => 'Not Found',
            self::CONFLICT => 'Conflict',
            self::UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
            self::TOO_MANY_REQUESTS => 'Too Many Requests',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
        };
    }

    public function isSuccess(): bool
    {
        return $this->value >= 200 && $this->value < 300;
    }

    public function isClientError(): bool
    {
        return $this->value >= 400 && $this->value < 500;
    }

    public function isServerError(): bool
    {
        return $this->value >= 500;
    }
}
