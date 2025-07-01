<?php

declare(strict_types=1);

namespace App\Services;

use App\Entities\User;
use App\Config\AppConfig;
use App\Cache\TokenValidationCache;
use DateTime;
use Exception;

class JwtService
{
    private string $secretKey;
    private string $algorithm;
    private int $expirationTime;
    private TokenValidationCache $tokenCache;
    private AppConfig $config;

    public function __construct(?AppConfig $config = null, ?TokenValidationCache $tokenCache = null)
    {
        $this->config = $config ?? AppConfig::getInstance();
        $jwtConfig = $this->config->getJwtConfig();

        $this->secretKey = $jwtConfig['secret'];
        $this->algorithm = $jwtConfig['algorithm'];
        $this->expirationTime = $jwtConfig['expiration'];
        $this->tokenCache = $tokenCache ?? new TokenValidationCache();
    }

    public function generateToken(User $user): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => $this->algorithm]);
        $now = time();
        $payload = json_encode([
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
            'name' => $user->getName(),
            'iat' => $now,
            'exp' => $now + $this->expirationTime,
            'jti' => uniqid('jwt_', true) // JWT ID for tracking
        ]);

        if ($header === false || $payload === false) {
            throw new Exception('Failed to encode JWT components');
        }

        $headerEncoded = $this->base64UrlEncode($header);
        $payloadEncoded = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secretKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        // Check Redis cache first
        $cachedPayload = $this->tokenCache->getValidatedToken($tokenHash);
        if ($cachedPayload !== null) {
            return $cachedPayload;
        }

        // Validate token if not cached
        $payload = $this->validateTokenInternal($token);

        if ($payload !== null) {
            // Cache the validation result in Redis
            $tokenExpiry = $payload['exp'] ?? (time() + 300);
            $this->tokenCache->storeValidatedToken($tokenHash, $payload, $tokenExpiry);
        }

        return $payload;
    }

    /**
     * Internal method to actually validate the token
     * @return array<string, mixed>|null
     */
    private function validateTokenInternal(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $this->secretKey, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        // Add clock skew tolerance (5 minutes)
        $clockSkewTolerance = 300;
        if (isset($payload['exp']) && $payload['exp'] < (time() - $clockSkewTolerance)) {
            return null; // Token expired (with tolerance)
        }

        return $payload;
    }

    /**
     * Invalidate a specific token
     */
    public function invalidateToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->tokenCache->invalidateToken($tokenHash);
    }

    /**
     * Invalidate all tokens for a user
     */
    public function invalidateUserTokens(int $userId): void
    {
        $this->tokenCache->invalidateUserTokens($userId);
    }

    public function getUserIdFromToken(string $token): ?int
    {
        $payload = $this->validateToken($token);
        return $payload['user_id'] ?? null;
    }

    public function isTokenExpired(string $token): bool
    {
        $payload = $this->validateToken($token);
        if (!$payload || !isset($payload['exp'])) {
            return true;
        }

        return $payload['exp'] < time();
    }

    public function generateRefreshToken(User $user): string
    {
        $payload = json_encode([
            'user_id' => $user->getId(),
            'type' => 'refresh',
            'iat' => time(),
            'exp' => time() + (86400 * 7) // 7 days
        ]);

        if ($payload === false) {
            throw new Exception('Failed to encode refresh token payload');
        }

        $payloadEncoded = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $payloadEncoded, $this->secretKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateRefreshToken(string $refreshToken): ?array
    {
        $parts = explode('.', $refreshToken);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;

        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $payloadEncoded, $this->secretKey, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null; // Token expired
        }

        if (!isset($payload['type']) || $payload['type'] !== 'refresh') {
            return null; // Not a refresh token
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Get token information for debugging
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!$payload) {
            return null;
        }

        $now = time();
        $timeToExpiry = isset($payload['exp']) ? $payload['exp'] - $now : null;

        return [
            'user_id' => $payload['user_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'issued_at' => $payload['iat'] ?? null,
            'expires_at' => $payload['exp'] ?? null,
            'current_time' => $now,
            'time_to_expiry' => $timeToExpiry,
            'is_expired' => $timeToExpiry !== null ? $timeToExpiry < 0 : null,
            'expires_in_minutes' => $timeToExpiry !== null ? round($timeToExpiry / 60, 1) : null
        ];
    }

    /**
     * Get the current expiration time setting
     */
    public function getExpirationTime(): int
    {
        return $this->expirationTime;
    }
}
