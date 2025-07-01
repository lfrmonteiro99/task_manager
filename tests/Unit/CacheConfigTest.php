<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Cache\CacheConfig;

class CacheConfigTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        // Test default TTL values
        $this->assertEquals(3600, CacheConfig::SINGLE_TASK_TTL);
        $this->assertEquals(1800, CacheConfig::TASK_LIST_TTL);
        $this->assertEquals(900, CacheConfig::OVERDUE_TASKS_TTL);
        $this->assertEquals(1800, CacheConfig::STATISTICS_TTL);
        
        // Test connection settings
        $this->assertEquals(2.0, CacheConfig::REDIS_CONNECTION_TIMEOUT);
        $this->assertEquals(1.0, CacheConfig::REDIS_READ_TIMEOUT);
        $this->assertEquals(50, CacheConfig::REDIS_MAX_CONNECTIONS);
        
        // Test cache key prefixes
        $this->assertEquals('tm:', CacheConfig::KEY_PREFIX);
        $this->assertEquals('user:', CacheConfig::USER_KEY_PREFIX);
        $this->assertEquals('task:', CacheConfig::TASK_KEY_PREFIX);
    }
    
    public function testRedisConfiguration(): void
    {
        // Test Redis config generation
        $config = CacheConfig::getRedisConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('host', $config);
        $this->assertArrayHasKey('port', $config);
        $this->assertArrayHasKey('timeout', $config);
        $this->assertArrayHasKey('read_timeout', $config);
        $this->assertArrayHasKey('database', $config);
        
        // Check default values (should be 'redis' but may be overridden by environment)
        $this->assertContains($config['host'], ['redis', 'localhost']);
        $this->assertEquals(6379, $config['port']);
        $this->assertEquals(2.0, $config['timeout']);
        $this->assertEquals(1.0, $config['read_timeout']);
    }
    
    public function testUserCacheKeyGeneration(): void
    {
        $userId = 123;
        $type = 'tasks';
        
        $key = CacheConfig::getUserCacheKey($type, $userId);
        $expectedKey = 'tm:user:123:tasks';
        
        $this->assertEquals($expectedKey, $key);
        
        // Test with suffix
        $keyWithSuffix = CacheConfig::getUserCacheKey($type, $userId, 'all');
        $expectedKeyWithSuffix = 'tm:user:123:tasks:all';
        
        $this->assertEquals($expectedKeyWithSuffix, $keyWithSuffix);
    }
    
    public function testTaskCacheKeyGeneration(): void
    {
        $taskId = 456;
        
        $key = CacheConfig::getTaskCacheKey($taskId);
        $expectedKey = 'tm:task:456';
        
        $this->assertEquals($expectedKey, $key);
        
        // Test with suffix
        $keyWithSuffix = CacheConfig::getTaskCacheKey($taskId, 'details');
        $expectedKeyWithSuffix = 'tm:task:456:details';
        
        $this->assertEquals($expectedKeyWithSuffix, $keyWithSuffix);
    }
    
    public function testDynamicTTL(): void
    {
        // Test base TTL values
        $taskListTTL = CacheConfig::getDynamicTTL('task_list');
        $this->assertEquals(CacheConfig::TASK_LIST_TTL, $taskListTTL);
        
        $overdueTTL = CacheConfig::getDynamicTTL('overdue_tasks');
        $this->assertEquals(CacheConfig::OVERDUE_TASKS_TTL, $overdueTTL);
        
        $statisticsTTL = CacheConfig::getDynamicTTL('statistics');
        $this->assertEquals(CacheConfig::STATISTICS_TTL, $statisticsTTL);
        
        // Test reduced TTL for active users
        $activeUserTaskListTTL = CacheConfig::getDynamicTTL('task_list', true);
        $expectedReducedTTL = (int)(CacheConfig::TASK_LIST_TTL * 0.7);
        $this->assertEquals($expectedReducedTTL, $activeUserTaskListTTL);
        
        // Test unknown cache type defaults to task list TTL
        $unknownTTL = CacheConfig::getDynamicTTL('unknown_type');
        $this->assertEquals(CacheConfig::TASK_LIST_TTL, $unknownTTL);
    }
    
    public function testCacheWarmingConfiguration(): void
    {
        // Test cache warming is enabled by default
        $this->assertTrue(CacheConfig::CACHE_WARMING_ENABLED);
        
        // Test environment-based configuration
        // Note: This would require setting environment variables in real tests
        $shouldEnable = CacheConfig::shouldEnableCacheWarming();
        $this->assertIsBool($shouldEnable);
    }
    
    public function testCacheTags(): void
    {
        $userId = 123;
        $taskId = 456;
        
        // Test user-only tags
        $userTags = CacheConfig::getCacheTags($userId);
        $this->assertContains('user:123', $userTags);
        $this->assertCount(1, $userTags);
        
        // Test user and task tags
        $userTaskTags = CacheConfig::getCacheTags($userId, $taskId);
        $this->assertContains('user:123', $userTaskTags);
        $this->assertContains('task:456', $userTaskTags);
        $this->assertCount(2, $userTaskTags);
    }
    
    public function testMonitoringConfiguration(): void
    {
        $config = CacheConfig::getMonitoringConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('enable_metrics', $config);
        $this->assertArrayHasKey('metrics_interval', $config);
        $this->assertArrayHasKey('slow_query_threshold', $config);
        $this->assertArrayHasKey('memory_warning_threshold', $config);
        $this->assertArrayHasKey('log_cache_misses', $config);
        
        // Check default values
        $this->assertEquals(60, $config['metrics_interval']);
        $this->assertEquals(0.1, $config['slow_query_threshold']);
        $this->assertEquals(80, $config['memory_warning_threshold']);
    }
    
    public function testPerformanceConstants(): void
    {
        // Test performance optimization settings
        $this->assertTrue(CacheConfig::COMPRESSION_ENABLED);
        $this->assertEquals('json', CacheConfig::SERIALIZATION_FORMAT);
        $this->assertEquals(100, CacheConfig::BATCH_INVALIDATION_SIZE);
        
        // Test memory management
        $this->assertEquals(512, CacheConfig::MAX_MEMORY_USAGE_MB);
        $this->assertEquals('allkeys-lru', CacheConfig::EVICTION_POLICY);
        $this->assertEquals(80, CacheConfig::MEMORY_WARNING_THRESHOLD);
    }
    
    public function testEnvironmentVariableOverrides(): void
    {
        // Test that environment variables can override defaults
        // Note: In real tests, you'd use putenv() to set test values
        
        $config = CacheConfig::getRedisConfig();
        
        // These should pick up from environment or use defaults
        $this->assertIsString($config['host']);
        $this->assertIsInt($config['port']);
        $this->assertIsFloat($config['timeout']);
        
        // Test that nullable password works
        $this->assertTrue(is_null($config['password']) || is_string($config['password']));
    }
    
    public function testConfigurationValidation(): void
    {
        // Test that configuration values are within reasonable ranges
        $this->assertGreaterThan(0, CacheConfig::SINGLE_TASK_TTL);
        $this->assertGreaterThan(0, CacheConfig::TASK_LIST_TTL);
        $this->assertGreaterThan(0, CacheConfig::OVERDUE_TASKS_TTL);
        $this->assertGreaterThan(0, CacheConfig::STATISTICS_TTL);
        
        $this->assertGreaterThan(0, CacheConfig::REDIS_CONNECTION_TIMEOUT);
        $this->assertGreaterThan(0, CacheConfig::REDIS_READ_TIMEOUT);
        $this->assertGreaterThan(0, CacheConfig::REDIS_MAX_CONNECTIONS);
        
        $this->assertGreaterThan(0, CacheConfig::MAX_MEMORY_USAGE_MB);
        $this->assertGreaterThanOrEqual(0, CacheConfig::MEMORY_WARNING_THRESHOLD);
        $this->assertLessThanOrEqual(100, CacheConfig::MEMORY_WARNING_THRESHOLD);
    }
    
    public function testKeyNaming(): void
    {
        // Test that key naming follows expected patterns
        $userId = 123;
        $taskId = 456;
        
        $userKey = CacheConfig::getUserCacheKey('tasks', $userId);
        $this->assertStringStartsWith(CacheConfig::KEY_PREFIX, $userKey);
        $this->assertStringContainsString(CacheConfig::USER_KEY_PREFIX, $userKey);
        $this->assertStringContainsString((string)$userId, $userKey);
        
        $taskKey = CacheConfig::getTaskCacheKey($taskId);
        $this->assertStringStartsWith(CacheConfig::KEY_PREFIX, $taskKey);
        $this->assertStringContainsString(CacheConfig::TASK_KEY_PREFIX, $taskKey);
        $this->assertStringContainsString((string)$taskId, $taskKey);
    }
}