<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Bootstrap\Application;
use App\Router;

try {
    // Bootstrap the application
    $app = Application::getInstance();
    $app->bootstrap();
    
    // Get router from container
    $router = $app->get(Router::class);
    
    // Get controllers from container
    $taskController = $app->get(\App\Controllers\TaskController::class);
    $authController = $app->get(\App\Controllers\AuthController::class);
    $healthController = $app->get(\App\Controllers\HealthController::class);

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

    $router->handleRequest();

} catch (Exception $e) {
    // Fallback error handling if bootstrap fails
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Application Error',
        'message' => 'The application failed to start properly.',
        'status_code' => 500
    ], JSON_PRETTY_PRINT);
}