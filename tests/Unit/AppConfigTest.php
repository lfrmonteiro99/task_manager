<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Config\AppConfig;

class AppConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure we have all required environment variables for testing
        putenv('JWT_SECRET=test_jwt_secret_key_that_is_at_least_32_characters_long_for_testing_purposes');
        putenv('DB_HOST=localhost');
        putenv('DB_NAME=task_manager_test');
        putenv('DB_USER=test_user');
        putenv('DB_PASS=test_pass');
        putenv('REDIS_HOST=localhost');
        putenv('REDIS_PORT=6379');
        putenv('REDIS_DB=15');
    }

    protected function tearDown(): void
    {
        TestHelper::cleanup();
    }

    public function testAppConfigIsSingleton(): void
    {
        $config1 = AppConfig::getInstance();
        $config2 = AppConfig::getInstance();
        
        $this->assertSame($config1, $config2);
    }

    public function testGetDatabaseConfig(): void
    {
        $config = AppConfig::getInstance();
        $dbConfig = $config->getDatabaseConfig();
        
        $this->assertIsArray($dbConfig);
        $this->assertArrayHasKey('host', $dbConfig);
        $this->assertArrayHasKey('name', $dbConfig);
        $this->assertArrayHasKey('user', $dbConfig);
        $this->assertArrayHasKey('pass', $dbConfig);
        
        $this->assertEquals('localhost', $dbConfig['host']);
        $this->assertEquals('task_manager_test', $dbConfig['name']);
        $this->assertEquals('test_user', $dbConfig['user']);
        $this->assertEquals('test_pass', $dbConfig['pass']);
    }

    public function testGetJwtConfig(): void
    {
        $config = AppConfig::getInstance();
        $jwtConfig = $config->getJwtConfig();
        
        $this->assertIsArray($jwtConfig);
        $this->assertArrayHasKey('secret', $jwtConfig);
        $this->assertArrayHasKey('expiration', $jwtConfig);
        $this->assertArrayHasKey('algorithm', $jwtConfig);
        
        $this->assertGreaterThanOrEqual(32, strlen($jwtConfig['secret']));
        $this->assertIsInt($jwtConfig['expiration']);
        $this->assertEquals('HS256', $jwtConfig['algorithm']);
    }

    public function testGetRedisConfig(): void
    {
        $config = AppConfig::getInstance();
        $redisConfig = $config->getRedisConfig();
        
        $this->assertIsArray($redisConfig);
        $this->assertArrayHasKey('host', $redisConfig);
        $this->assertArrayHasKey('port', $redisConfig);
        $this->assertArrayHasKey('database', $redisConfig);
        
        $this->assertEquals('localhost', $redisConfig['host']);
        $this->assertEquals(6379, $redisConfig['port']);
        $this->assertEquals(15, $redisConfig['database']);
    }

    public function testInvalidJwtSecretThrowsException(): void
    {
        // Reset singleton to test validation
        $reflection = new ReflectionClass(AppConfig::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        // Set invalid JWT secret
        putenv('JWT_SECRET=short');
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JWT_SECRET environment variable must be set and at least 32 characters long');
        
        AppConfig::getInstance();
    }

    public function testMissingRequiredDatabaseConfigThrowsException(): void
    {
        // Reset singleton to test validation
        $reflection = new ReflectionClass(AppConfig::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        // Remove required environment variable
        putenv('DB_HOST');
        
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Required environment variable DB_HOST is not set');
        
        AppConfig::getInstance();
    }
}