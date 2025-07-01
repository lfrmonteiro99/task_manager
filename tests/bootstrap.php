<?php

declare(strict_types=1);

// Test bootstrap file for PHPUnit

// Set all environment variables BEFORE loading any classes
putenv('APP_ENV=testing');
putenv('DB_HOST=localhost');
putenv('DB_NAME=task_manager_test');
putenv('DB_USER=test_user');
putenv('DB_PASS=test_pass');
putenv('JWT_SECRET=test_jwt_secret_key_that_is_at_least_32_characters_long_for_testing_purposes');
putenv('REDIS_HOST=localhost');
putenv('REDIS_PORT=6379');
putenv('REDIS_DB=15');
putenv('TEST_API_BASE_URL=http://localhost:8080');

// Also set $_ENV for compatibility
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'task_manager_test';
$_ENV['DB_USER'] = 'test_user';
$_ENV['DB_PASS'] = 'test_pass';
$_ENV['JWT_SECRET'] = 'test_jwt_secret_key_that_is_at_least_32_characters_long_for_testing_purposes';
$_ENV['REDIS_HOST'] = 'localhost';
$_ENV['REDIS_PORT'] = '6379';
$_ENV['REDIS_DB'] = '15';
$_ENV['TEST_API_BASE_URL'] = 'http://localhost:8080';

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\AppConfig;
use App\Container\DIContainer;
use App\Context\RequestContext;

/**
 * Test helper class for setting up test environment
 */
class TestHelper
{
    private static ?DIContainer $container = null;
    
    public static function getContainer(): DIContainer
    {
        if (self::$container === null) {
            self::$container = DIContainer::getInstance();
        }
        return self::$container;
    }
    
    public static function resetContainer(): void
    {
        if (self::$container !== null) {
            self::$container->flush();
        }
        self::$container = null;
    }
    
    public static function resetRequestContext(): void
    {
        RequestContext::reset();
    }
    
    public static function createTestUser(): array
    {
        return [
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => password_hash('testpass123', PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s')
        ];
    }
    
    public static function createTestTask(int $userId = 1): array
    {
        return [
            'id' => 1,
            'user_id' => $userId,
            'title' => 'Test Task',
            'description' => 'This is a test task for unit testing purposes',
            'due_date' => '2025-12-31 23:59:59',
            'created_at' => date('Y-m-d H:i:s'),
            'done' => false,
            'priority' => 'medium',
            'status' => 'pending'
        ];
    }
    
    /**
     * Clean up test environment after each test
     */
    public static function cleanup(): void
    {
        self::resetContainer();
        self::resetRequestContext();
    }
}

// Global cleanup function for tests
register_shutdown_function(function() {
    TestHelper::cleanup();
});