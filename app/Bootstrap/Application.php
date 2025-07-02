<?php

declare(strict_types=1);

namespace App\Bootstrap;

use App\Container\DIContainer;
use App\Config\AppConfig;
use App\Context\RequestContext;
use App\Exceptions\GlobalExceptionHandler;
use Exception;

class Application
{
    private static ?self $instance = null;
    private DIContainer $container;
    private AppConfig $config;
    private RequestContext $requestContext;
    private GlobalExceptionHandler $exceptionHandler;

    private function __construct()
    {
        $this->initializeConfiguration();
        $this->initializeContainer();
        $this->initializeContext();
        $this->initializeErrorHandling();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getContainer(): DIContainer
    {
        return $this->container;
    }

    public function getConfig(): AppConfig
    {
        return $this->config;
    }

    public function getRequestContext(): RequestContext
    {
        return $this->requestContext;
    }

    /**
     * Bootstrap the application
     */
    public function bootstrap(): void
    {
        // Register all application routes
        $this->registerRoutes();
    }

    /**
     * Handle an HTTP request
     */
    public function handleRequest(): void
    {
        try {
            // Record request start time for metrics
            $this->requestContext->setMetadata('start_time', microtime(true));

            // Set request metadata from $_SERVER
            $this->requestContext->setMetadata('method', $_SERVER['REQUEST_METHOD']);
            $this->requestContext->setMetadata('uri', $_SERVER['REQUEST_URI']);
            $this->requestContext->setMetadata('headers', $this->getAllHeaders());

            // Get router from container and handle request
            /** @var \App\Router $router */
            $router = $this->container->get(\App\Router::class);
            $router->handleRequest();
        } catch (Exception $e) {
            $this->exceptionHandler->handleException($e);
        }
    }

    /**
     * Get a service from the container
     */
    public function get(string $abstract): object
    {
        return $this->container->get($abstract);
    }

    /**
     * Check if a service exists in the container
     */
    public function has(string $abstract): bool
    {
        return $this->container->has($abstract);
    }

    private function initializeConfiguration(): void
    {
        try {
            $this->config = AppConfig::getInstance();
        } catch (Exception $e) {
            // If configuration fails, we can't continue
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Configuration Error',
                'message' => 'Application configuration failed: ' . $e->getMessage(),
                'status_code' => 500
            ]);
            exit;
        }
    }

    private function initializeContainer(): void
    {
        $this->container = DIContainer::getInstance();

        // Register the config instance
        $this->container->instance(AppConfig::class, $this->config);
    }

    private function initializeContext(): void
    {
        $this->requestContext = RequestContext::getInstance();

        // Register the context instance
        $this->container->instance(RequestContext::class, $this->requestContext);
    }

    private function initializeErrorHandling(): void
    {
        /** @var GlobalExceptionHandler $exceptionHandler */
        $exceptionHandler = $this->container->get(GlobalExceptionHandler::class);
        $this->exceptionHandler = $exceptionHandler;
        $this->exceptionHandler->register();
    }

    /**
     * Get all HTTP headers in a compatible way
     * @return array<string, string>
     */
    private function getAllHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        // Fallback for environments where getallheaders() is not available
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    /**
     * Register all application routes
     */
    private function registerRoutes(): void
    {
        /** @var \App\Router $router */
        $router = $this->container->get(\App\Router::class);
        
        // Get controllers from container
        $taskController = $this->container->get(\App\Controllers\TaskController::class);
        $authController = $this->container->get(\App\Controllers\AuthController::class);
        $healthController = $this->container->get(\App\Controllers\HealthController::class);

        // Authentication routes (no auth required)
        $router->addRoute('POST', '/auth/register', [$authController, 'register']);
        $router->addRoute('POST', '/auth/login', [$authController, 'login']);
        $router->addRoute('POST', '/auth/refresh', [$authController, 'refresh']);
        $router->addRoute('GET', '/auth/profile', [$authController, 'profile']);
        $router->addRoute('GET', '/auth/debug', [$authController, 'debug']);

        // Task routes (auth required)
        $router->addRoute('POST', '/task/create', [$taskController, 'create']);
        $router->addRoute('GET', '/task/list', [$taskController, 'list']);
        $router->addRoute('PUT', '/task/{id}', [$taskController, 'update']);
        $router->addRoute('POST', '/task/{id}/done', [$taskController, 'markdone']);
        $router->addRoute('DELETE', '/task/{id}', [$taskController, 'delete']);
        $router->addRoute('GET', '/task/overdue', [$taskController, 'overdue']);
        $router->addRoute('GET', '/task/statistics', [$taskController, 'statistics']);
        $router->addRoute('GET', '/task/{id}', [$taskController, 'show']);
        
        // Health check endpoint (no auth required)
        $router->addRoute('GET', '/health', [$healthController, 'health']);
        $router->addRoute('GET', '/debug/headers', [$healthController, 'debug']);
    }

    /**
     * Shutdown the application gracefully
     */
    public function shutdown(): void
    {
        try {
            // Record request completion time
            $startTime = $this->requestContext->getMetadata('start_time');
            if ($startTime) {
                $duration = (microtime(true) - $startTime) * 1000;
                $this->requestContext->setMetadata('total_duration_ms', round($duration, 2));
            }

            // Log request completion metrics
            if ($this->config->isDebug()) {
                $method = $this->requestContext->getMetadata('method') ?? 'UNKNOWN';
                $uri = $this->requestContext->getMetadata('uri') ?? 'UNKNOWN';
                $duration = $this->requestContext->getMetadata('total_duration_ms') ?? 0;
                
                error_log(sprintf(
                    "Request completed: %s %s (%.2fms) at %s",
                    $method,
                    $uri,
                    $duration,
                    date('Y-m-d H:i:s')
                ));
            }

            // Clean up database connections
            $this->cleanupDatabaseConnections();

            // Clean up cache connections
            $this->cleanupCacheConnections();

            // Clear request context
            RequestContext::reset();

            // Reset singleton instance for testing environments
            if ($this->config->get('app.env') === 'test') {
                self::$instance = null;
            }

        } catch (Exception $e) {
            // Don't let shutdown errors break the response
            if ($this->config->isDebug()) {
                error_log("Shutdown error: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up database connections
     */
    private function cleanupDatabaseConnections(): void
    {
        try {
            // Get database instance if it exists in container
            if ($this->container->has(\App\Models\Database::class)) {
                /** @var \App\Models\Database $database */
                $database = $this->container->get(\App\Models\Database::class);
                // Database connections will be closed by PDO destructor
                // No explicit close method needed
                
                if ($this->config->isDebug()) {
                    error_log("Database connection will be closed by destructor");
                }
            }
        } catch (Exception $e) {
            if ($this->config->isDebug()) {
                error_log("Database cleanup error: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up cache connections
     */
    private function cleanupCacheConnections(): void
    {
        try {
            // Clean up Redis connections if they exist
            if ($this->container->has(\App\Cache\RedisCache::class)) {
                /** @var \App\Cache\RedisCache $redisCache */
                $redisCache = $this->container->get(\App\Cache\RedisCache::class);
                // Redis client will be automatically closed by destructor
                // No explicit disconnect method available
            }

            // Record cache metrics before cleanup
            if ($this->container->has(\App\Cache\TaskCacheManager::class)) {
                /** @var \App\Cache\TaskCacheManager $cacheManager */
                $cacheManager = $this->container->get(\App\Cache\TaskCacheManager::class);
                
                if ($this->config->isDebug()) {
                    $metrics = $cacheManager->getCacheMetrics();
                    if (!empty($metrics)) {
                        error_log("Cache metrics: " . json_encode($metrics));
                    }
                }
            }
        } catch (Exception $e) {
            if ($this->config->isDebug()) {
                error_log("Cache cleanup error: " . $e->getMessage());
            }
        }
    }
}
