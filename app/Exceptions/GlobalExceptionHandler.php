<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\HttpStatusCode;
use App\Utils\Logger;
use App\Config\AppConfig;
use App\Context\RequestContext;
use Exception;
use InvalidArgumentException;
use PDOException;
use Throwable;

class GlobalExceptionHandler
{
    private bool $isProduction;
    private Logger $logger;
    private RequestContext $requestContext;

    public function __construct(
        ?AppConfig $config = null,
        ?RequestContext $requestContext = null,
        ?Logger $logger = null
    ) {
        $config = $config ?? AppConfig::getInstance();
        $this->isProduction = $config->isProduction();
        $this->logger = $logger ?? new Logger();
        $this->requestContext = $requestContext ?? RequestContext::getInstance();
    }

    public function handleException(Throwable $exception): void
    {
        // Log the exception with request context
        $contextData = $this->requestContext->toArray();
        $this->logger->error('Unhandled exception', [
            'exception' => get_class($exception),
            'message' => $this->sanitizeErrorMessage($exception->getMessage()),
            'file' => $this->sanitizeFilePath($exception->getFile()),
            'line' => $exception->getLine(),
            'trace' => $this->isProduction ? 'hidden' : $exception->getTraceAsString(),
            'context' => $contextData
        ]);

        // Determine appropriate response based on exception type
        $response = $this->createErrorResponse($exception);

        // Send response
        $this->sendJsonResponse($response['status'], $response['body']);
    }

    /**
     * @return array{status: HttpStatusCode, body: array<string, mixed>}
     */
    private function createErrorResponse(Throwable $exception): array
    {
        switch (true) {
            case $exception instanceof ValidationException:
                return [
                    'status' => $exception->getHttpStatusCode(),
                    'body' => $exception->toArray()
                ];

            case $exception instanceof SecurityException:
                return [
                    'status' => $exception->getHttpStatusCode(),
                    'body' => $exception->toArray()
                ];

            case $exception instanceof AuthenticationException:
                return [
                    'status' => $exception->getHttpStatusCode(),
                    'body' => $exception->toArray()
                ];

            case $exception instanceof InvalidArgumentException:
                return [
                    'status' => HttpStatusCode::BAD_REQUEST,
                    'body' => [
                        'error' => 'Validation Error',
                        'message' => $this->sanitizeErrorMessage($exception->getMessage()),
                        'error_code' => 'VALIDATION_ERROR',
                        'status_code' => HttpStatusCode::BAD_REQUEST->value
                    ]
                ];

            case $exception instanceof PDOException:
                $this->logger->critical('Database error', [
                    'error' => $exception->getMessage(),
                    'code' => $exception->getCode()
                ]);

                return [
                    'status' => HttpStatusCode::INTERNAL_SERVER_ERROR,
                    'body' => [
                        'error' => 'Database Error',
                        'message' => $this->isProduction
                            ? 'A database error occurred. Please try again later.'
                            : $exception->getMessage(),
                        'error_code' => 'DATABASE_ERROR',
                        'status_code' => HttpStatusCode::INTERNAL_SERVER_ERROR->value
                    ]
                ];

            case $exception instanceof TaskNotFoundException:
                return [
                    'status' => HttpStatusCode::NOT_FOUND,
                    'body' => [
                        'error' => 'Not Found',
                        'message' => 'The requested task was not found.',
                        'error_code' => 'TASK_NOT_FOUND',
                        'status_code' => HttpStatusCode::NOT_FOUND->value
                    ]
                ];

            case $exception instanceof RateLimitExceededException:
                return [
                    'status' => HttpStatusCode::TOO_MANY_REQUESTS,
                    'body' => [
                        'error' => 'Rate Limit Exceeded',
                        'message' => 'Too many requests. Please slow down.',
                        'error_code' => 'RATE_LIMIT_EXCEEDED',
                        'status_code' => HttpStatusCode::TOO_MANY_REQUESTS->value
                    ]
                ];

            default:
                return [
                    'status' => HttpStatusCode::INTERNAL_SERVER_ERROR,
                    'body' => [
                        'error' => 'Internal Server Error',
                        'message' => $this->isProduction
                            ? 'An unexpected error occurred. Please try again later.'
                            : $exception->getMessage(),
                        'error_code' => 'INTERNAL_ERROR',
                        'status_code' => HttpStatusCode::INTERNAL_SERVER_ERROR->value,
                        'debug' => $this->isProduction ? null : [
                            'exception' => get_class($exception),
                            'file' => $exception->getFile(),
                            'line' => $exception->getLine()
                        ]
                    ]
                ];
        }
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // Remove sensitive information from error messages
        $sanitized = preg_replace('/password|token|key|secret|credential/i', '[REDACTED]', $message);

        if ($sanitized === null) {
            $sanitized = $message;
        }

        // Limit message length
        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 197) . '...';
        }

        return $sanitized;
    }

    private function sanitizeFilePath(string $path): string
    {
        if ($this->isProduction) {
            // In production, only show the filename
            return basename($path);
        }

        // In development, show relative path from project root
        $projectRoot = dirname(__DIR__, 2);
        if (str_starts_with($path, $projectRoot)) {
            return str_replace($projectRoot, '', $path);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendJsonResponse(HttpStatusCode $status, array $body): void
    {
        // Ensure we haven't sent headers yet
        if (!headers_sent()) {
            http_response_code($status->value);
            header('Content-Type: application/json');

            // Add request ID for debugging
            $requestId = $this->requestContext->getRequestId();
            header('X-Request-ID: ' . $requestId);

            // Log the response
            $this->logger->info('Error response sent', [
                'status_code' => $status->value,
                'error_code' => $body['error_code'] ?? 'unknown',
                'request_id' => $requestId,
                'user_id' => $this->requestContext->getUserId()
            ]);
        }

        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function register(): void
    {
        // Set error handler
        set_error_handler([$this, 'handleError']);

        // Set exception handler
        set_exception_handler([$this, 'handleException']);

        // Set shutdown function for fatal errors
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Convert errors to exceptions
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logger->critical('Fatal error', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json');

                echo json_encode([
                    'error' => 'Fatal Error',
                    'message' => $this->isProduction
                        ? 'A critical error occurred. Please contact support.'
                        : $error['message'],
                    'error_code' => 'FATAL_ERROR',
                    'status_code' => 500
                ], JSON_PRETTY_PRINT);
            }
        }
    }
}
