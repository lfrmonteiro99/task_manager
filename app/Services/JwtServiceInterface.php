<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\User;

interface JwtServiceInterface
{
    /**
     * Generate JWT token for user
     */
    public function generateToken(User $user): string;

    /**
     * Validate JWT token and return payload
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array;

    /**
     * Invalidate a specific token
     */
    public function invalidateToken(string $token): void;

    /**
     * Invalidate all tokens for a user
     */
    public function invalidateUserTokens(int $userId): void;

    /**
     * Get user ID from token
     */
    public function getUserIdFromToken(string $token): ?int;

    /**
     * Check if token is expired
     */
    public function isTokenExpired(string $token): bool;

    /**
     * Generate refresh token for user
     */
    public function generateRefreshToken(User $user): string;

    /**
     * Validate refresh token and return payload
     * @return array<string, mixed>|null
     */
    public function validateRefreshToken(string $refreshToken): ?array;

    /**
     * Get token information (payload + metadata)
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $token): ?array;

    /**
     * Get token expiration time in seconds
     */
    public function getExpirationTime(): int;
}
