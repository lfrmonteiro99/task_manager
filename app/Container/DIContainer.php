<?php

declare(strict_types=1);

namespace App\Container;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use ReflectionNamedType;

class DIContainer
{
    private static ?self $instance = null;
    /** @var array<string, string|callable> */
    private array $bindings = [];
    /** @var array<string, object|null> */
    private array $instances = [];

    private function __construct()
    {
        $this->registerDefaultBindings();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Bind an interface to a concrete implementation
     */
    public function bind(string $abstract, string|callable $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
    }

    /**
     * Bind a singleton instance
     */
    public function singleton(string $abstract, string|callable $concrete): void
    {
        $this->bind($abstract, $concrete);
        $this->instances[$abstract] = null; // Mark as singleton
    }

    /**
     * Register an existing instance
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Resolve a service from the container
     */
    public function get(string $abstract): object
    {
        // Check if we have a singleton instance
        if (array_key_exists($abstract, $this->instances) && $this->instances[$abstract] !== null) {
            return $this->instances[$abstract];
        }

        // Check if we have a binding
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];

            if (is_callable($concrete)) {
                $instance = $concrete($this);
            } else {
                $instance = $this->resolve($concrete);
            }
        } else {
            // Try to auto-resolve the class
            $instance = $this->resolve($abstract);
        }

        // Store singleton instances
        if (array_key_exists($abstract, $this->instances)) {
            $this->instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Resolve a class and its dependencies
     */
    private function resolve(string $className): object
    {
        try {
            if (!class_exists($className)) {
                throw new InvalidArgumentException("Class {$className} does not exist");
            }
            $reflection = new ReflectionClass($className);

            if (!$reflection->isInstantiable()) {
                throw new InvalidArgumentException("Class {$className} is not instantiable");
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $className();
            }

            $parameters = $constructor->getParameters();
            $dependencies = $this->resolveDependencies($parameters);

            return $reflection->newInstanceArgs($dependencies);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException("Unable to resolve class {$className}: " . $e->getMessage());
        }
    }

    /**
     * Resolve constructor dependencies
     */
    /**
     * @param ReflectionParameter[] $parameters
     * @return array<mixed>
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $this->resolveDependency($parameter);
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Resolve a single dependency
     */
    private function resolveDependency(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            throw new InvalidArgumentException("Cannot resolve parameter {$parameter->getName()}");
        }

        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        // Handle primitive types
        if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            throw new InvalidArgumentException("Cannot resolve primitive type {$typeName}");
        }

        // Handle nullable types
        if ($parameter->allowsNull() && !isset($this->bindings[$typeName])) {
            return null;
        }

        return $this->get($typeName);
    }

    /**
     * Check if a service is bound
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Register default bindings
     */
    private function registerDefaultBindings(): void
    {
        // Config
        $this->singleton(\App\Config\AppConfig::class, function () {
            return \App\Config\AppConfig::getInstance();
        });

        // Context
        $this->singleton(\App\Context\RequestContext::class, function () {
            return \App\Context\RequestContext::getInstance();
        });

        // Database
        $this->singleton(\App\Models\Database::class, \App\Models\Database::class);

        // Cache
        $this->bind(\App\Cache\CacheInterface::class, \App\Cache\RedisCache::class);
        $this->singleton(\App\Cache\TokenValidationCache::class, \App\Cache\TokenValidationCache::class);
        $this->singleton(\App\Cache\UserDataCache::class, \App\Cache\UserDataCache::class);
        $this->singleton(\App\Cache\TaskCacheManager::class, \App\Cache\TaskCacheManager::class);

        // Services
        $this->singleton(\App\Services\JwtService::class, \App\Services\JwtService::class);
        $this->bind(\App\Services\JwtServiceInterface::class, \App\Services\JwtService::class);
        $this->bind(\App\Services\TaskServiceInterface::class, \App\Services\TaskService::class);
        $this->bind(\App\Services\TaskRetryServiceInterface::class, \App\Services\TaskRetryService::class);
        $this->bind(\App\Services\PaginationServiceInterface::class, \App\Services\PaginationService::class);

        // Repositories
        $this->bind(\App\Repositories\TaskRepositoryInterface::class, \App\Repositories\TaskRepository::class);
        $this->singleton(\App\Repositories\UserRepository::class, \App\Repositories\UserRepository::class);

        // Middleware
        $this->singleton(\App\Middleware\AuthMiddleware::class, \App\Middleware\AuthMiddleware::class);
        $this->singleton(\App\Middleware\RateLimitMiddleware::class, \App\Middleware\RateLimitMiddleware::class);
        $this->singleton(\App\Middleware\LoggingMiddleware::class, \App\Middleware\LoggingMiddleware::class);
        $this->singleton(
            \App\Middleware\SecurityHeadersMiddleware::class,
            \App\Middleware\SecurityHeadersMiddleware::class
        );

        // Utils
        $this->singleton(\App\Utils\Logger::class, \App\Utils\Logger::class);

        // Controllers
        $this->bind(\App\Controllers\TaskController::class, \App\Controllers\TaskController::class);
        $this->bind(\App\Controllers\AuthController::class, \App\Controllers\AuthController::class);
        $this->bind(\App\Controllers\HealthController::class, \App\Controllers\HealthController::class);

        // Router
        $this->singleton(\App\Router::class, \App\Router::class);

        // Exception Handler
        $this->singleton(\App\Exceptions\GlobalExceptionHandler::class, \App\Exceptions\GlobalExceptionHandler::class);
    }

    /**
     * Clear all bindings and instances (for testing)
     */
    public function flush(): void
    {
        $this->bindings = [];
        $this->instances = [];
        $this->registerDefaultBindings();
    }
}
