<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Bootstrap\Application;

$app = null;

try {
    // Bootstrap the application and handle the request
    $app = Application::getInstance();
    $app->bootstrap();
    $app->handleRequest();

} catch (Exception $e) {
    // Fallback error handling if bootstrap fails
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Application Error',
        'message' => 'The application failed to start properly.',
        'status_code' => 500
    ], JSON_PRETTY_PRINT);
} finally {
    // Ensure proper cleanup regardless of success or failure
    if ($app !== null) {
        $app->shutdown();
    }
}