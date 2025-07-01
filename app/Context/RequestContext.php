<?php

declare(strict_types=1);

namespace App\Context;

use App\Entities\User;

class RequestContext
{
    private static ?self $instance = null;
    private ?User $user = null;
    private ?string $requestId = null;
    private ?string $userAgent = null;
    private ?string $ipAddress = null;
    /** @var array<string, mixed> */
    private array $metadata = [];

    private function __construct()
    {
        $this->requestId = uniqid('req_', true);
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getUserId(): ?int
    {
        return $this->user?->getId();
    }

    public function getUserEmail(): ?string
    {
        return $this->user?->getEmail();
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function getRequestId(): string
    {
        return $this->requestId ?? '';
    }

    public function getUserAgent(): string
    {
        return $this->userAgent ?? '';
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress ?? '';
    }

    public function getHeader(string $headerName): ?string
    {
        // Convert header name to $_SERVER format
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return $_SERVER[$serverKey] ?? null;
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getAllMetadata(): array
    {
        return $this->metadata;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'user_id' => $this->getUserId(),
            'user_email' => $this->getUserEmail(),
            'user_agent' => $this->userAgent,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->metadata,
            'timestamp' => time()
        ];
    }
}
