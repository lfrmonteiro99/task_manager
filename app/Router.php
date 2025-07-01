<?php

declare(strict_types=1);

namespace App;

use App\Middleware\AuthMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\LoggingMiddleware;
use App\Exceptions\GlobalExceptionHandler;

class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable}> */
    private array $routes = [];

    public function __construct(
        private readonly AuthMiddleware $authMiddleware,
        private readonly SecurityHeadersMiddleware $securityMiddleware,
        private readonly LoggingMiddleware $loggingMiddleware,
        private readonly GlobalExceptionHandler $exceptionHandler
    ) {
        // Exception handler registration is now handled by Application bootstrap
    }

    public function addRoute(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    public function handleRequest(): void
    {
        try {
            // Start request logging
            $this->loggingMiddleware->logRequest();

            // Add security headers first
            $this->securityMiddleware->addSecurityHeaders();
            $this->securityMiddleware->handleCors();

            $method = $_SERVER['REQUEST_METHOD'];
            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if ($uri === null || $uri === false) {
                $uri = '/';
            }

            // Check if this is an auth endpoint (no authentication required)
            if ($this->isAuthEndpoint($uri)) {
                foreach ($this->routes as $route) {
                    if ($this->matchRoute($route, $method, $uri)) {
                        $params = $this->extractParams($route['pattern'], $uri);
                        call_user_func_array($route['handler'], $params);
                        $this->loggingMiddleware->logResponse(200);
                        return;
                    }
                }
            } else {
                // Authenticate request before processing protected endpoints
                if (!$this->authMiddleware->authenticate()) {
                    $this->loggingMiddleware->logResponse(401);
                    return; // AuthMiddleware handles the response
                }

                foreach ($this->routes as $route) {
                    if ($this->matchRoute($route, $method, $uri)) {
                        $params = $this->extractParams($route['pattern'], $uri);
                        call_user_func_array($route['handler'], $params);
                        $this->loggingMiddleware->logResponse(200);
                        return;
                    }
                }
            }

            $this->sendNotFoundResponse();
            $this->loggingMiddleware->logResponse(404);
        } catch (\Throwable $e) {
            // Exception handler will take care of logging and response
            $this->exceptionHandler->handleException($e);
        }
    }

    /**
     * @param array{method: string, pattern: string, handler: callable} $route
     */
    private function matchRoute(array $route, string $method, string $uri): bool
    {
        if ($route['method'] !== $method) {
            return false;
        }

        // Convert route pattern to regex
        $pattern = preg_replace('/\{(\w+)\}/', '(\d+)', $route['pattern']);
        $pattern = '#^' . $pattern . '$#';

        return (bool) preg_match($pattern, $uri);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractParams(string $pattern, string $uri): array
    {
        if (strpos($pattern, '{') === false) {
            return [];
        }

        $patternRegex = preg_replace('/\{(\w+)\}/', '(\d+)', $pattern);
        $patternRegex = '#^' . $patternRegex . '$#';

        preg_match($patternRegex, $uri, $matches);
        array_shift($matches); // Remove full match

        return array_map('intval', $matches);
    }

    private function isAuthEndpoint(string $uri): bool
    {
        $authEndpoints = [
            '/auth/register',
            '/auth/login',
            '/auth/refresh',
            '/health',
            '/debug/headers'
        ];

        return in_array($uri, $authEndpoints, true);
    }

    private function sendNotFoundResponse(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Endpoint not found'], JSON_PRETTY_PRINT);
    }
}
