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
        // Application is already bootstrapped in constructor
        // This method can be used for additional setup if needed
    }

    /**
     * Handle an HTTP request
     */
    /**
     * @param array<string, string> $headers
     */
    public function handleRequest(string $method, string $uri, array $headers = [], ?string $body = null): void
    {
        try {
            // Set request metadata
            $this->requestContext->setMetadata('method', $method);
            $this->requestContext->setMetadata('uri', $uri);
            $this->requestContext->setMetadata('headers', $headers);

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
