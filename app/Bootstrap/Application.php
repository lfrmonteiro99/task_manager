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
        // Clean up resources
        RequestContext::reset();

        // Log application shutdown if needed
        if ($this->config->isDebug()) {
            error_log("Application shutdown at " . date('Y-m-d H:i:s'));
        }
    }
}
