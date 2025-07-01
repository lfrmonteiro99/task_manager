<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\JwtService;
use App\Context\RequestContext;
use App\Enums\HttpStatusCode;
use Exception;

class AuthController extends BaseController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JwtService $jwtService,
        private readonly RequestContext $requestContext
    ) {
    }

    public function register(): void
    {
        try {
            $input = $this->getJsonInput();

            if ($input === null || !$this->validateRegistrationInput($input)) {
                return;
            }

            if ($this->userRepository->emailExists($input['email'])) {
                $this->sendJsonResponse([
                    'error' => 'Registration failed',
                    'message' => 'Email already exists'
                ], HttpStatusCode::CONFLICT);
                return;
            }

            $user = $this->userRepository->create(
                $input['email'],
                $input['password'],
                $input['name']
            );

            $accessToken = $this->jwtService->generateToken($user);
            $refreshToken = $this->jwtService->generateRefreshToken($user);

            $this->sendJsonResponse([
                'message' => 'User registered successfully',
                'user' => $user->toSafeArray(),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer'
            ], HttpStatusCode::CREATED);
        } catch (Exception $e) {
            $this->sendJsonResponse([
                'error' => 'Registration failed',
                'message' => $e->getMessage()
            ], HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function login(): void
    {
        try {
            $input = $this->getJsonInput();

            if ($input === null || !$this->validateLoginInput($input)) {
                return;
            }

            $user = $this->userRepository->findByEmail($input['email']);

            if (!$user || !$user->verifyPassword($input['password'])) {
                $this->sendJsonResponse([
                    'error' => 'Authentication failed',
                    'message' => 'Invalid email or password'
                ], HttpStatusCode::UNAUTHORIZED);
                return;
            }

            $accessToken = $this->jwtService->generateToken($user);
            $refreshToken = $this->jwtService->generateRefreshToken($user);

            $this->sendJsonResponse([
                'message' => 'Login successful',
                'user' => $user->toSafeArray(),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer'
            ], HttpStatusCode::OK);
        } catch (Exception $e) {
            $this->sendJsonResponse([
                'error' => 'Login failed',
                'message' => $e->getMessage()
            ], HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function refresh(): void
    {
        try {
            $input = $this->getJsonInput();

            if (!isset($input['refresh_token']) || empty($input['refresh_token'])) {
                $this->sendJsonResponse([
                    'error' => 'Validation failed',
                    'message' => 'Refresh token is required'
                ], HttpStatusCode::BAD_REQUEST);
                return;
            }

            $payload = $this->jwtService->validateRefreshToken($input['refresh_token']);

            if (!$payload || !isset($payload['user_id'])) {
                $this->sendJsonResponse([
                    'error' => 'Token validation failed',
                    'message' => 'Invalid or expired refresh token'
                ], HttpStatusCode::UNAUTHORIZED);
                return;
            }

            $user = $this->userRepository->findById($payload['user_id']);

            if (!$user) {
                $this->sendJsonResponse([
                    'error' => 'User not found',
                    'message' => 'User associated with token not found'
                ], HttpStatusCode::UNAUTHORIZED);
                return;
            }

            $accessToken = $this->jwtService->generateToken($user);
            $refreshToken = $this->jwtService->generateRefreshToken($user);

            $this->sendJsonResponse([
                'message' => 'Token refreshed successfully',
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'Bearer'
            ], HttpStatusCode::OK);
        } catch (Exception $e) {
            $this->sendJsonResponse([
                'error' => 'Token refresh failed',
                'message' => $e->getMessage()
            ], HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function profile(): void
    {
        // This will be called after middleware authentication
        $userId = $this->requestContext->getUserId();

        if (!$userId) {
            $this->sendJsonResponse([
                'error' => 'Authentication required',
                'message' => 'User not authenticated'
            ], HttpStatusCode::UNAUTHORIZED);
            return;
        }

        try {
            $user = $this->userRepository->findById((int)$userId);

            if (!$user) {
                $this->sendJsonResponse([
                    'error' => 'User not found',
                    'message' => 'Authenticated user not found'
                ], HttpStatusCode::NOT_FOUND);
                return;
            }

            $this->sendJsonResponse([
                'user' => $user->toSafeArray()
            ], HttpStatusCode::OK);
        } catch (Exception $e) {
            $this->sendJsonResponse([
                'error' => 'Profile retrieval failed',
                'message' => $e->getMessage()
            ], HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }

    public function debug(): void
    {
        // This will be called after middleware authentication
        $userId = $this->requestContext->getUserId();

        if (!$userId) {
            $this->sendJsonResponse([
                'error' => 'Authentication required',
                'message' => 'User not authenticated'
            ], HttpStatusCode::UNAUTHORIZED);
            return;
        }

        try {
            // Extract token from Authorization header
            $authHeader = $this->requestContext->getHeader('Authorization') ?? '';
            if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                $token = $matches[1];
                $tokenInfo = $this->jwtService->getTokenInfo($token);

                $this->sendJsonResponse([
                    'user_id' => $userId,
                    'jwt_expiration_setting' => $this->jwtService->getExpirationTime(),
                    'token_info' => $tokenInfo,
                    'server_time' => time(),
                    'server_time_formatted' => date('Y-m-d H:i:s'),
                    'app_env' => getenv('APP_ENV') ?: 'not_set'
                ], HttpStatusCode::OK);
            } else {
                $this->sendJsonResponse([
                    'error' => 'No token found in Authorization header'
                ], HttpStatusCode::BAD_REQUEST);
            }
        } catch (Exception $e) {
            $this->sendJsonResponse([
                'error' => 'Debug failed',
                'message' => $e->getMessage()
            ], HttpStatusCode::INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * @param array<string, mixed> $input
     */
    private function validateRegistrationInput(array $input): bool
    {
        $errors = [];

        if (!isset($input['email']) || empty($input['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email format is invalid';
        }

        if (!isset($input['password']) || empty($input['password'])) {
            $errors[] = 'Password is required';
        } elseif (strlen($input['password']) < 6) {
            $errors[] = 'Password must be at least 6 characters long';
        }

        if (!isset($input['name']) || empty($input['name'])) {
            $errors[] = 'Name is required';
        } elseif (strlen($input['name']) < 2) {
            $errors[] = 'Name must be at least 2 characters long';
        }

        if (!empty($errors)) {
            $this->sendJsonResponse([
                'error' => 'Validation failed',
                'message' => 'Please fix the following errors',
                'errors' => $errors
            ], HttpStatusCode::BAD_REQUEST);
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function validateLoginInput(array $input): bool
    {
        $errors = [];

        if (!isset($input['email']) || empty($input['email'])) {
            $errors[] = 'Email is required';
        }

        if (!isset($input['password']) || empty($input['password'])) {
            $errors[] = 'Password is required';
        }

        if (!empty($errors)) {
            $this->sendJsonResponse([
                'error' => 'Validation failed',
                'message' => 'Please fix the following errors',
                'errors' => $errors
            ], HttpStatusCode::BAD_REQUEST);
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function sendJsonResponse(array $data, HttpStatusCode $statusCode = HttpStatusCode::OK): void
    {
        http_response_code($statusCode->value);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}
