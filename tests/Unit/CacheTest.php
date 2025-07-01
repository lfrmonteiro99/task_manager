<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Cache\NullCache;
use App\Cache\TaskCacheManager;
use App\Factories\TaskFactory;

class CacheTest extends TestCase
{
    private NullCache $cache;
    private TaskCacheManager $taskCacheManager;

    protected function setUp(): void
    {
        $this->cache = new NullCache();
        $this->taskCacheManager = new TaskCacheManager($this->cache);
    }

    public function testNullCacheImplementation(): void
    {
        // Test basic cache operations with null cache
        $this->assertNull($this->cache->get('test_key'));
        $this->assertTrue($this->cache->set('test_key', 'test_value'));
        $this->assertNull($this->cache->get('test_key')); // Null cache doesn't store
        $this->assertTrue($this->cache->delete('test_key'));
        $this->assertFalse($this->cache->exists('test_key'));
        $this->assertEquals(-2, $this->cache->ttl('test_key'));
        $this->assertTrue($this->cache->flush());
        $this->assertEquals(0, $this->cache->deletePattern('test*'));
    }

    public function testTaskCacheManagerWithNullCache(): void
    {
        $task = TaskFactory::create([
            'id' => 1,
            'title' => 'Test Task',
            'description' => 'Test task for cache testing',
            'due_date' => '2025-12-31 23:59:59'
        ]);

        // Test task caching (with null cache, should always return null)
        $this->assertNull($this->taskCacheManager->getTask(1));
        $this->assertTrue($this->taskCacheManager->setTask($task));
        $this->assertNull($this->taskCacheManager->getTask(1));

        // Test task list caching with user keys
        $tasks = [$task];
        $userKey = 'user_123';
        $this->assertNull($this->taskCacheManager->getAllTasks($userKey));
        $this->assertTrue($this->taskCacheManager->setAllTasks($tasks, $userKey));
        $this->assertNull($this->taskCacheManager->getAllTasks($userKey));

        // Test overdue tasks caching with user keys
        $this->assertNull($this->taskCacheManager->getOverdueTasks($userKey));
        $this->assertTrue($this->taskCacheManager->setOverdueTasks($tasks, $userKey));
        $this->assertNull($this->taskCacheManager->getOverdueTasks($userKey));

        // Test statistics caching with user keys
        $stats = ['total_tasks' => 1, 'completed_tasks' => 0];
        $this->assertNull($this->taskCacheManager->getStatistics($userKey));
        $this->assertTrue($this->taskCacheManager->setStatistics($stats, $userKey));
        $this->assertNull($this->taskCacheManager->getStatistics($userKey));
    }

    public function testCacheInvalidation(): void
    {
        $userId = 123;
        
        // Test invalidation methods (should work with null cache)
        $this->assertTrue($this->taskCacheManager->invalidateTask(1));
        $this->assertEquals(0, $this->taskCacheManager->invalidateAllTasks());
        
        // Test user-specific invalidation
        $this->assertTrue($this->taskCacheManager->invalidateUserListCaches($userId));
        $this->assertTrue($this->taskCacheManager->invalidateOnTaskCreate($userId));
        $this->assertTrue($this->taskCacheManager->invalidateOnTaskUpdate(1, $userId));
        $this->assertTrue($this->taskCacheManager->invalidateOnTaskDelete(1, $userId));
        $this->assertTrue($this->taskCacheManager->invalidateOnTaskStatusChange(1, $userId));
        
        // Test legacy methods for backward compatibility
        $this->assertTrue($this->taskCacheManager->invalidateListCaches());
    }

    public function testCacheAvailability(): void
    {
        // Null cache should always report as available
        $this->assertTrue($this->taskCacheManager->isAvailable());
    }

    public function testCacheInfo(): void
    {
        $info = $this->taskCacheManager->getCacheInfo();
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('available', $info);
        $this->assertArrayHasKey('keys', $info);
        $this->assertTrue($info['available']);
        
        $this->assertArrayHasKey('all_tasks', $info['keys']);
        $this->assertArrayHasKey('overdue_tasks', $info['keys']);
        $this->assertArrayHasKey('statistics', $info['keys']);
        
        foreach ($info['keys'] as $keyInfo) {
            $this->assertArrayHasKey('exists', $keyInfo);
            $this->assertArrayHasKey('ttl', $keyInfo);
            $this->assertFalse($keyInfo['exists']); // Null cache never has keys
            $this->assertEquals(-2, $keyInfo['ttl']); // Null cache TTL
        }
    }

    public function testUserSpecificCacheInfo(): void
    {
        $userId = 123;
        $info = $this->taskCacheManager->getUserCacheInfo($userId);
        
        $this->assertIsArray($info);
        $this->assertArrayHasKey('available', $info);
        $this->assertArrayHasKey('user_id', $info);
        $this->assertArrayHasKey('keys', $info);
        $this->assertTrue($info['available']);
        $this->assertEquals($userId, $info['user_id']);
        
        // Check user-specific cache keys
        $this->assertArrayHasKey('all_tasks', $info['keys']);
        $this->assertArrayHasKey('overdue_tasks', $info['keys']);
        $this->assertArrayHasKey('statistics', $info['keys']);
    }

    public function testCacheMetrics(): void
    {
        $metrics = $this->taskCacheManager->getCacheMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('hit_ratio', $metrics);
        $this->assertArrayHasKey('memory_usage', $metrics);
        $this->assertArrayHasKey('key_count', $metrics);
        $this->assertArrayHasKey('connection_status', $metrics);
        
        // For null cache, these should be default values
        $this->assertEquals(0, $metrics['hit_ratio']);
        $this->assertEquals(0, $metrics['memory_usage']);
        $this->assertEquals(0, $metrics['key_count']);
        $this->assertTrue($metrics['connection_status']);
    }

    public function testCacheWarmup(): void
    {
        $userId = 123;
        $result = $this->taskCacheManager->warmUpUserCache($userId);
        
        // With null cache, warmup should still work but return false (no actual warming needed)
        $this->assertIsBool($result);
    }

    public function testTaskFactoryIntegrationWithCache(): void
    {
        // Test that tasks created by factory work with cache
        $taskData = [
            'id' => 42,
            'title' => 'Cache Integration Test',
            'description' => 'Testing task factory integration with cache system',
            'due_date' => '2025-12-31 23:59:59',
            'done' => false
        ];

        $task = TaskFactory::create($taskData);
        
        // Test caching the factory-created task
        $this->assertTrue($this->taskCacheManager->setTask($task));
        $this->assertNull($this->taskCacheManager->getTask(42)); // Null cache returns null
        
        // Verify task properties are preserved
        $this->assertEquals(42, $task->getId());
        $this->assertEquals('Cache Integration Test', $task->getTitle());
        $this->assertEquals('Testing task factory integration with cache system', $task->getDescription());
        $this->assertFalse($task->isDone());
    }

    public function testMultiUserCacheIsolation(): void
    {
        $task1 = TaskFactory::create([
            'id' => 1,
            'title' => 'User 1 Task',
            'description' => 'Task for user 1',
            'due_date' => '2025-12-31 23:59:59'
        ]);
        
        $task2 = TaskFactory::create([
            'id' => 2,
            'title' => 'User 2 Task',
            'description' => 'Task for user 2',
            'due_date' => '2025-12-31 23:59:59'
        ]);
        
        $user1Key = 'user_100';
        $user2Key = 'user_200';
        
        // Cache tasks for different users
        $this->assertTrue($this->taskCacheManager->setAllTasks([$task1], $user1Key));
        $this->assertTrue($this->taskCacheManager->setAllTasks([$task2], $user2Key));
        
        // Verify isolation (with null cache, both return null but the structure works)
        $this->assertNull($this->taskCacheManager->getAllTasks($user1Key));
        $this->assertNull($this->taskCacheManager->getAllTasks($user2Key));
        
        // Test user-specific invalidation
        $this->assertTrue($this->taskCacheManager->invalidateUserListCaches(100));
        $this->assertTrue($this->taskCacheManager->invalidateUserListCaches(200));
    }

    public function testCacheConfigIntegration(): void
    {
        // Test that cache manager works with different configurations
        // This would be more meaningful with a real cache implementation
        
        $this->assertTrue($this->taskCacheManager->isAvailable());
        
        // Test cache info structure
        $info = $this->taskCacheManager->getCacheInfo();
        $this->assertIsArray($info);
        
        $userInfo = $this->taskCacheManager->getUserCacheInfo(123);
        $this->assertIsArray($userInfo);
        
        $metrics = $this->taskCacheManager->getCacheMetrics();
        $this->assertIsArray($metrics);
    }
}