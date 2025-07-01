<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Enums\HttpStatusCode;
use App\Services\JwtService;
use App\Repositories\UserRepository;
use App\Context\RequestContext;
use App\Cache\UserDataCache;
use App\Entities\User;

class AuthMiddleware
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly UserRepository $userRepository,
        private readonly RateLimitMiddleware $rateLimitMiddleware,
        private readonly LoggingMiddleware $loggingMiddleware,
        private readonly RequestContext $requestContext,
        private readonly UserDataCache $userCache
    ) {
    }

    public function authenticate(): bool
    {
        $token = $this->extractTokenFromRequest();

        if ($token === null) {
            $this->loggingMiddleware->logAuthFailure('missing_token');
            $this->sendUnauthorizedResponse(
                'Missing access token. Please provide Authorization header with Bearer token.'
            );
            return false;
        }

        $payload = $this->jwtService->validateToken($token);
        if (!$payload || !isset($payload['user_id'])) {
            $this->loggingMiddleware->logAuthFailure('invalid_token', substr($token, 0, 20) . '...');
            $this->sendUnauthorizedResponse('Invalid or expired access token.');
            return false;
        }

        // Try to get user from cache first to avoid database call
        $user = $this->getUserFromCacheOrDatabase($payload['user_id']);
        if (!$user) {
            $this->loggingMiddleware->logAuthFailure('user_not_found', (string)$payload['user_id']);
            $this->sendUnauthorizedResponse('User not found.');
            return false;
        }

        // Set authenticated user in request context (not global variables)
        $this->requestContext->setUser($user);

        // Use user ID for rate limiting (more specific than token)
        $rateLimitKey = 'user_' . $user->getId();
        if (!$this->rateLimitMiddleware->checkRateLimit($rateLimitKey)) {
            return false; // RateLimitMiddleware handles the response
        }

        // Add rate limit headers to successful requests
        $this->addRateLimitHeaders($rateLimitKey);

        return true;
    }

    public function authenticateOptional(): ?int
    {
        $token = $this->extractTokenFromRequest();

        if ($token === null) {
            return null;
        }

        $payload = $this->jwtService->validateToken($token);
        if (!$payload || !isset($payload['user_id'])) {
            return null;
        }

        $user = $this->getUserFromCacheOrDatabase($payload['user_id']);
        if (!$user) {
            return null;
        }

        $this->requestContext->setUser($user);

        return $user->getId();
    }

    /**
     * Get user from cache first, fall back to database
     */
    private function getUserFromCacheOrDatabase(int $userId): ?User
    {
        // Try cache first
        $cachedUserData = $this->userCache->getUser($userId);
        if ($cachedUserData !== null) {
            // Reconstruct User entity from cached data
            $user = new User();
            $user->setId($cachedUserData['id']);
            $user->setEmail($cachedUserData['email']);
            $user->setName($cachedUserData['name']);
            if ($cachedUserData['created_at']) {
                $user->setCreatedAt(new \DateTime($cachedUserData['created_at']));
            }
            return $user;
        }

        // Fall back to database
        $user = $this->userRepository->findById($userId);
        if ($user) {
            // Cache the user data for next time
            $this->userCache->storeUser($user);
        }

        return $user;
    }

    private function extractTokenFromRequest(): ?string
    {
        // Primary: Check Authorization header (Bearer token)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Also try alternative header names that some servers use
        if (empty($authHeader)) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        // Extract token from Authorization header
        if (!empty($authHeader) && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Fallback for development: X-Auth-Token header (only when Authorization header fails)
        $customToken = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
        if (!empty($customToken)) {
            return $customToken;
        }

        return null;
    }

    private function sendUnauthorizedResponse(string $message): void
    {
        http_response_code(HttpStatusCode::UNAUTHORIZED->value);
        header('Content-Type: application/json');
        header('WWW-Authenticate: Bearer realm="API"');

        echo json_encode([
            'error' => 'Unauthorized',
            'message' => $message,
            'status_code' => HttpStatusCode::UNAUTHORIZED->value
        ], JSON_PRETTY_PRINT);

        exit;
    }

    private function addRateLimitHeaders(string $rateLimitKey): void
    {
        $remaining = $this->rateLimitMiddleware->getRemainingRequests($rateLimitKey);
        $resetTime = $this->rateLimitMiddleware->getResetTime($rateLimitKey);
        $limit = $this->rateLimitMiddleware->getMaxRequests();

        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $resetTime);
    }
}
