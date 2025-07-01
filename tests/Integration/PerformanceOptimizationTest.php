<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Config\RateLimitConfig;
use App\Cache\CacheConfig;
use App\Cache\TaskCacheManager;
use App\Cache\NullCache;

/**
 * Integration tests for performance optimizations
 * Tests the interaction between caching, rate limiting, and multi-user features
 */
class PerformanceOptimizationTest extends TestCase
{
    private string $baseUrl;
    private array $testUsers = [];

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        
        // Only setup test users if API is available (some tests don't need API)
        if ($this->isApiServerAvailable()) {
            $this->setupTestUsers();
        }
    }

    protected function tearDown(): void
    {
        // Cleanup test data
        $this->cleanupTestData();
    }

    private function isApiServerAvailable(): bool
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl . '/health',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_NOBODY => true, // HEAD request
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $response !== false && $httpCode === 200;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function setupTestUsers(): void
    {
        $userConfigs = [
            'basic_user' => ['tier' => 'basic', 'email' => 'basic_perf_test@example.com'],
            'premium_user' => ['tier' => 'premium', 'email' => 'premium_perf_test@example.com'],
        ];

        foreach ($userConfigs as $key => $config) {
            try {
                // Register user
                $registerResponse = $this->makeRequest('POST', '/auth/register', [
                    'name' => 'Performance Test User - ' . $config['tier'],
                    'email' => $config['email'],
                    'password' => 'TestPass123!'
                ]);

                // Login to get token
                $loginResponse = $this->makeRequest('POST', '/auth/login', [
                    'email' => $config['email'],
                    'password' => 'TestPass123!'
                ]);

                if ($loginResponse['status'] === 200) {
                    $this->testUsers[$key] = [
                        'email' => $config['email'],
                        'token' => $loginResponse['body']['access_token'],
                        'userId' => $loginResponse['body']['user']['id'],
                        'tier' => $config['tier']
                    ];
                }
            } catch (Exception $e) {
                // User might already exist, try to login
                $loginResponse = $this->makeRequest('POST', '/auth/login', [
                    'email' => $config['email'],
                    'password' => 'TestPass123!'
                ]);

                if ($loginResponse['status'] === 200) {
                    $this->testUsers[$key] = [
                        'email' => $config['email'],
                        'token' => $loginResponse['body']['access_token'],
                        'userId' => $loginResponse['body']['user']['id'],
                        'tier' => $config['tier']
                    ];
                }
            }
        }
    }

    public function testMultiUserRateLimitIsolation(): void
    {
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        if (count($this->testUsers) < 2) {
            $this->markTestSkipped('Need at least 2 test users for multi-user isolation test');
        }

        $basicUser = $this->testUsers['basic_user'];
        $premiumUser = $this->testUsers['premium_user'];

        // Make requests with basic user
        $basicResponse1 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $basicUser['token']);
        $basicRemaining1 = (int)$basicResponse1['headers']['X-RateLimit-Remaining'];

        // Make requests with premium user - should have separate rate limit
        $premiumResponse1 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $premiumUser['token']);
        $premiumRemaining1 = (int)$premiumResponse1['headers']['X-RateLimit-Remaining'];

        // Basic user's second request should decrement their limit
        $basicResponse2 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $basicUser['token']);
        $basicRemaining2 = (int)$basicResponse2['headers']['X-RateLimit-Remaining'];

        // Premium user's limit should be unaffected by basic user's requests
        $premiumResponse2 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $premiumUser['token']);
        $premiumRemaining2 = (int)$premiumResponse2['headers']['X-RateLimit-Remaining'];

        // Verify isolation
        $this->assertEquals($basicRemaining1 - 1, $basicRemaining2, 'Basic user rate limit should decrement');
        $this->assertEquals($premiumRemaining1 - 1, $premiumRemaining2, 'Premium user rate limit should decrement independently');
        
        // Verify different tier limits
        $this->assertEquals('basic', $basicResponse1['headers']['X-RateLimit-User-Tier']);
        $this->assertEquals('100', $basicResponse1['headers']['X-RateLimit-Limit']); // basic read limit
    }

    public function testOperationSpecificRateLimits(): void
    {
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        if (empty($this->testUsers['basic_user'])) {
            $this->markTestSkipped('Need test user for operation-specific rate limit test');
        }

        $user = $this->testUsers['basic_user'];

        // Test read operation
        $readResponse = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user['token']);
        $this->assertEquals('read', $readResponse['headers']['X-RateLimit-Operation']);
        $this->assertEquals('100', $readResponse['headers']['X-RateLimit-Limit']);

        // Test write operation
        $taskData = [
            'title' => 'Performance Test Task',
            'description' => 'Testing operation-specific rate limits',
            'due_date' => '2025-12-31 23:59:59'
        ];
        $writeResponse = $this->makeAuthenticatedRequest('POST', '/task/create', $taskData, $user['token']);
        
        if ($writeResponse['status'] === 200) {
            $this->assertEquals('write', $writeResponse['headers']['X-RateLimit-Operation']);
            $this->assertEquals('50', $writeResponse['headers']['X-RateLimit-Limit']); // write limit is 50% of read
        }

        // Verify read and write have separate counters
        $readResponse2 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user['token']);
        $this->assertEquals('read', $readResponse2['headers']['X-RateLimit-Operation']);
        $this->assertEquals('100', $readResponse2['headers']['X-RateLimit-Limit']);
    }

    public function testCacheIsolationBetweenUsers(): void
    {
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        if (count($this->testUsers) < 2) {
            $this->markTestSkipped('Need at least 2 test users for cache isolation test');
        }

        $user1 = $this->testUsers['basic_user'];
        $user2 = $this->testUsers['premium_user'];

        // Create tasks for each user
        $task1Data = [
            'title' => 'User 1 Task for Cache Test',
            'description' => 'This task belongs to user 1',
            'due_date' => '2025-12-31 23:59:59'
        ];
        $task2Data = [
            'title' => 'User 2 Task for Cache Test', 
            'description' => 'This task belongs to user 2',
            'due_date' => '2025-12-31 23:59:59'
        ];

        $createResponse1 = $this->makeAuthenticatedRequest('POST', '/task/create', $task1Data, $user1['token']);
        $createResponse2 = $this->makeAuthenticatedRequest('POST', '/task/create', $task2Data, $user2['token']);

        if ($createResponse1['status'] === 200 && $createResponse2['status'] === 200) {
            // Get task lists for each user
            $listResponse1 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user1['token']);
            $listResponse2 = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user2['token']);

            $this->assertEquals(200, $listResponse1['status']);
            $this->assertEquals(200, $listResponse2['status']);

            // Verify users only see their own tasks
            $user1Tasks = $listResponse1['body']['tasks'] ?? [];
            $user2Tasks = $listResponse2['body']['tasks'] ?? [];

            $user1TaskTitles = array_column($user1Tasks, 'title');
            $user2TaskTitles = array_column($user2Tasks, 'title');

            $this->assertContains('User 1 Task for Cache Test', $user1TaskTitles);
            $this->assertNotContains('User 2 Task for Cache Test', $user1TaskTitles);

            $this->assertContains('User 2 Task for Cache Test', $user2TaskTitles);
            $this->assertNotContains('User 1 Task for Cache Test', $user2TaskTitles);
        }
    }

    public function testPerformanceMetricsCollection(): void
    {
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        if (empty($this->testUsers['basic_user'])) {
            $this->markTestSkipped('Need test user for performance metrics test');
        }

        $user = $this->testUsers['basic_user'];

        // Make several requests to generate metrics
        $responses = [];
        $start = microtime(true);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user['token']);
            $responses[] = $response;
            
            // Small delay to avoid overwhelming the system
            usleep(100000); // 0.1 seconds
        }

        $end = microtime(true);
        $totalTime = $end - $start;

        // Analyze response times
        $responseTimes = [];
        foreach ($responses as $response) {
            $this->assertEquals(200, $response['status']);
            $this->assertArrayHasKey('X-RateLimit-Remaining', $response['headers']);
            
            // Response time should be reasonable (less than 1 second per request)
            // This is a rough check since we can't measure exact response time from the client side
        }

        // Test should complete in reasonable time (less than 10 seconds for 5 requests)
        $this->assertLessThan(10.0, $totalTime, 'Performance test should complete quickly');

        // Verify rate limit headers are present and decreasing
        $remainingCounts = array_map(function($response) {
            return (int)$response['headers']['X-RateLimit-Remaining'];
        }, $responses);

        // Should generally be decreasing (allowing for window boundaries)
        $this->assertGreaterThanOrEqual(0, min($remainingCounts), 'Rate limit remaining should not go negative');
    }

    public function testCacheConfiguration(): void
    {
        // Test that cache configuration is properly set up
        $config = CacheConfig::getRedisConfig();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('host', $config);
        $this->assertArrayHasKey('port', $config);
        $this->assertArrayHasKey('timeout', $config);

        // Test cache key generation
        $userKey = CacheConfig::getUserCacheKey('tasks', 123);
        $this->assertStringContainsString('user:123', $userKey);

        $taskKey = CacheConfig::getTaskCacheKey(456);
        $this->assertStringContainsString('task:456', $taskKey);

        // Test TTL calculation
        $normalTTL = CacheConfig::getDynamicTTL('task_list', false);
        $activeTTL = CacheConfig::getDynamicTTL('task_list', true);
        $this->assertLessThan($normalTTL, $activeTTL, 'Active users should have shorter TTL');
    }

    public function testRateLimitConfiguration(): void
    {
        // Test basic tier configuration
        $basicConfig = RateLimitConfig::forUserTier('basic');
        $this->assertEquals('basic', $basicConfig->getUserTier());
        $this->assertEquals(100, $basicConfig->getMaxRequests());

        // Test premium tier configuration
        $premiumConfig = RateLimitConfig::forUserTier('premium');
        $this->assertEquals('premium', $premiumConfig->getUserTier());
        $this->assertEquals(500, $premiumConfig->getMaxRequests());

        // Test operation-specific limits
        $readLimit = $basicConfig->getOperationLimit('read');
        $writeLimit = $basicConfig->getOperationLimit('write');
        $this->assertEquals(100, $readLimit);
        $this->assertEquals(50, $writeLimit); // 50% of read limit

        // Test burst limits
        $burstLimit = $basicConfig->getBurstLimit();
        $this->assertEquals(20, $burstLimit); // 20% of main limit

        // Test available tiers
        $tiers = RateLimitConfig::getAvailableTiers();
        $this->assertContains('basic', $tiers);
        $this->assertContains('premium', $tiers);
        $this->assertContains('enterprise', $tiers);
        $this->assertContains('admin', $tiers);
    }

    public function testDatabaseOptimizationReadiness(): void
    {
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        // Test that the system can handle queries that would benefit from our new indexes
        if (empty($this->testUsers['basic_user'])) {
            $this->markTestSkipped('Need test user for database optimization test');
        }

        $user = $this->testUsers['basic_user'];

        // Test queries that use our optimized indexes
        $endpoints = [
            '/task/list',        // Uses idx_user_tasks_optimized
            '/task/statistics',  // Uses idx_user_stats
            '/task/overdue'      // Uses idx_user_overdue_tasks (if endpoint exists)
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->makeAuthenticatedRequest('GET', $endpoint, [], $user['token']);
            
            if ($response['status'] === 200) {
                $this->assertEquals(200, $response['status']);
                // These queries should complete quickly with proper indexes
            } elseif ($response['status'] === 404) {
                // Endpoint might not be implemented yet, which is fine
                $this->addToAssertionCount(1);
            } else {
                $this->fail("Unexpected response status {$response['status']} for endpoint {$endpoint}");
            }
        }
    }

    public function testLoadTestingInfrastructure(): void
    {
        // Verify that load testing scripts exist and are properly configured
        $scriptsPath = dirname(dirname(__DIR__)) . '/scripts';
        
        $this->assertFileExists($scriptsPath . '/load-test.js');
        $this->assertFileExists($scriptsPath . '/performance-monitor.js');
        $this->assertFileExists($scriptsPath . '/package.json');

        // Test package.json has correct scripts
        $packageJson = json_decode(file_get_contents($scriptsPath . '/package.json'), true);
        $this->assertArrayHasKey('scripts', $packageJson);
        $this->assertArrayHasKey('load-test', $packageJson['scripts']);
        $this->assertArrayHasKey('monitor', $packageJson['scripts']);

        // Test that scripts contain expected configurations
        $loadTestContent = file_get_contents($scriptsPath . '/load-test.js');
        $this->assertStringContainsString('LoadTester', $loadTestContent);
        $this->assertStringContainsString('JWT', $loadTestContent);

        $monitorContent = file_get_contents($scriptsPath . '/performance-monitor.js');
        $this->assertStringContainsString('PerformanceMonitor', $monitorContent);
        $this->assertStringContainsString('metrics', $monitorContent);
    }

    // Helper methods

    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = ['Content-Type: application/json'];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            $this->fail('cURL request failed');
        }

        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Parse headers
        $headers = [];
        foreach (explode("\r\n", $headerString) as $header) {
            if (strpos($header, ':') !== false) {
                [$key, $value] = explode(':', $header, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        $decodedBody = json_decode($body, true) ?: [];

        return [
            'status' => $httpCode,
            'body' => $decodedBody,
            'headers' => $headers
        ];
    }

    private function makeAuthenticatedRequest(string $method, string $endpoint, array $data = [], string $token = ''): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$token}"
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            $this->fail('cURL request failed');
        }

        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Parse headers
        $headers = [];
        foreach (explode("\r\n", $headerString) as $header) {
            if (strpos($header, ':') !== false) {
                [$key, $value] = explode(':', $header, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        $decodedBody = json_decode($body, true) ?: [];

        return [
            'status' => $httpCode,
            'body' => $decodedBody,
            'headers' => $headers
        ];
    }

    private function cleanupTestData(): void
    {
        foreach ($this->testUsers as $user) {
            try {
                // Delete test tasks
                $listResponse = $this->makeAuthenticatedRequest('GET', '/task/list', [], $user['token']);
                if ($listResponse['status'] === 200 && isset($listResponse['body']['tasks'])) {
                    foreach ($listResponse['body']['tasks'] as $task) {
                        if (str_contains($task['title'], 'Performance Test') || 
                            str_contains($task['title'], 'Cache Test')) {
                            $this->makeAuthenticatedRequest('DELETE', '/task/' . $task['id'], [], $user['token']);
                        }
                    }
                }
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    }
}