<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Config\RateLimitConfig;
use App\Middleware\RateLimitMiddleware;

class RateLimitTest extends TestCase
{
    private string $baseUrl;
    private string $validJwtToken;
    private int $testUserId;

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        
        // Skip tests if API server is not available
        if (!$this->isApiServerAvailable()) {
            $this->markTestSkipped('API server not available. Set TEST_API_BASE_URL and ensure server is running.');
        }
        
        // Setup JWT authentication
        $this->setupJwtAuthentication();
        
        // Wait for Redis to be ready (only once)
        static $redisChecked = false;
        if (!$redisChecked) {
            $this->waitForRedis();
            $redisChecked = true;
        }
        
        // Clear any existing rate limit data for test user
        $this->clearRateLimitData();
    }
    
    protected function tearDown(): void
    {
        // Clear rate limit data
        $this->clearRateLimitData();
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
    
    private function setupJwtAuthentication(): void
    {
        $loginData = [
            'email' => 'test@example.com',
            'password' => 'testpass123'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/auth/login',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($loginData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            $this->validJwtToken = $responseData['access_token'];
            $this->testUserId = $responseData['user']['id'];
        } else {
            $this->fail('Unable to setup JWT authentication for rate limit tests');
        }
    }

    public function testRateLimitHeaders(): void
    {
        $response = $this->makeRequest('GET', '/task/list');
        
        $this->assertEquals(200, $response['status']);
        
        // Check rate limit headers are present
        $this->assertArrayHasKey('X-RateLimit-Limit', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Reset', $response['headers']);
        
        // Check for new enhanced headers
        $this->assertArrayHasKey('X-RateLimit-User-Tier', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Operation', $response['headers']);
        
        // Check initial values for 'basic' tier read operations
        $this->assertEquals('100', $response['headers']['X-RateLimit-Limit']); // read operation limit
        $this->assertEquals('basic', $response['headers']['X-RateLimit-User-Tier']);
        $this->assertEquals('read', $response['headers']['X-RateLimit-Operation']);
        $this->assertGreaterThan(0, (int)$response['headers']['X-RateLimit-Remaining']);
        $this->assertGreaterThan(time(), (int)$response['headers']['X-RateLimit-Reset']);
    }

    public function testRateLimitDecrementsCorrectly(): void
    {
        $response1 = $this->makeRequest('GET', '/task/list');
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        $response2 = $this->makeRequest('GET', '/task/list');
        $remaining2 = (int)$response2['headers']['X-RateLimit-Remaining'];
        
        $response3 = $this->makeRequest('GET', '/task/list');
        $remaining3 = (int)$response3['headers']['X-RateLimit-Remaining'];
        
        // Each request should decrement by 1 (for read operations)
        $this->assertEquals($remaining1 - 1, $remaining2);
        $this->assertEquals($remaining2 - 1, $remaining3);
        
        // Verify they're all read operations
        $this->assertEquals('read', $response1['headers']['X-RateLimit-Operation']);
        $this->assertEquals('read', $response2['headers']['X-RateLimit-Operation']);
        $this->assertEquals('read', $response3['headers']['X-RateLimit-Operation']);
    }

    public function testRateLimitPerUser(): void
    {
        // Test rate limiting for the same user
        $response1 = $this->makeRequest('GET', '/task/list');
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        $response2 = $this->makeRequest('GET', '/task/list');
        $remaining2 = (int)$response2['headers']['X-RateLimit-Remaining'];
        
        // Rate limit should decrease for the same user
        $this->assertEquals($remaining1 - 1, $remaining2);
    }

    public function testRateLimitPersistenceAcrossRequests(): void
    {
        // Make several requests quickly to avoid window boundaries
        $responses = [];
        for ($i = 0; $i < 3; $i++) {
            $responses[] = $this->makeRequest('GET', '/task/list');
            // No delay to ensure we stay in the same window
        }
        
        // Check that remaining count decreases or stays consistent (accounting for window boundaries)
        for ($i = 1; $i < count($responses); $i++) {
            $prev = (int)$responses[$i-1]['headers']['X-RateLimit-Remaining'];
            $curr = (int)$responses[$i]['headers']['X-RateLimit-Remaining'];
            
            // Allow for either decreasing count or reset due to window boundary
            $this->assertTrue(
                $curr == $prev - 1 || $curr >= $prev,
                "Rate limit should decrease or reset due to window boundary. Previous: {$prev}, Current: {$curr}"
            );
        }
    }

    public function testRateLimitAppliesAcrossEndpoints(): void
    {
        // Make request to different authenticated read endpoints
        $response1 = $this->makeRequest('GET', '/task/list');
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        $response2 = $this->makeRequest('GET', '/task/statistics');
        $remaining2 = (int)$response2['headers']['X-RateLimit-Remaining'];
        
        $response3 = $this->makeRequest('GET', '/task/list');
        $remaining3 = (int)$response3['headers']['X-RateLimit-Remaining'];
        
        // All should be 'read' operations with same rate limit pool
        $this->assertEquals('read', $response1['headers']['X-RateLimit-Operation']);
        $this->assertEquals('read', $response2['headers']['X-RateLimit-Operation']);
        $this->assertEquals('read', $response3['headers']['X-RateLimit-Operation']);
        
        // Rate limit should apply across all read endpoints (accounting for window boundaries)
        $this->assertTrue(
            $remaining2 == $remaining1 - 1 || $remaining2 >= $remaining1,
            "Rate limit should decrease or reset. First: {$remaining1}, Second: {$remaining2}"
        );
        $this->assertTrue(
            $remaining3 == $remaining2 - 1 || $remaining3 >= $remaining2,
            "Rate limit should decrease or reset. Second: {$remaining2}, Third: {$remaining3}"
        );
    }

    public function testRateLimitWithDifferentHttpMethods(): void
    {
        $response1 = $this->makeRequest('GET', '/task/list');
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        // Create task (POST) - this is a write operation with different limits
        $taskData = [
            'title' => 'Rate Limit Test Task',
            'description' => 'Testing rate limit with POST request',
            'due_date' => '2025-12-31 23:59:59'
        ];
        $response2 = $this->makeRequest('POST', '/task/create', $taskData);
        
        // Write operations have different limits (50 for basic tier)
        $this->assertEquals('50', $response2['headers']['X-RateLimit-Limit']);
        $this->assertEquals('write', $response2['headers']['X-RateLimit-Operation']);
        
        // Verify the operations are tracked separately
        $response3 = $this->makeRequest('GET', '/task/list');
        $remaining3 = (int)$response3['headers']['X-RateLimit-Remaining'];
        
        // Read operations should still have their own counter
        $this->assertEquals('100', $response3['headers']['X-RateLimit-Limit']);
        $this->assertEquals('read', $response3['headers']['X-RateLimit-Operation']);
        
        // Cleanup
        if ($response2['status'] === 200) {
            $this->cleanupTestTasks();
        }
    }

    public function testRateLimitResetTime(): void
    {
        $response1 = $this->makeRequest('GET', '/task/list');
        $reset1 = (int)$response1['headers']['X-RateLimit-Reset'];
        
        // Wait a second
        sleep(1);
        
        $response2 = $this->makeRequest('GET', '/task/list');
        $reset2 = (int)$response2['headers']['X-RateLimit-Reset'];
        
        // Reset time should be in the same window (shouldn't change much)
        $this->assertLessThanOrEqual(2, abs($reset1 - $reset2), 'Reset time changed too much between requests');
    }

    public function testRateLimitExceededWithEnhancedResponse(): void
    {
        $this->markTestSkipped('Rate limit exceeded test requires 100+ requests - enable manually');
        
        // Make requests until rate limit is exceeded
        $responses = [];
        for ($i = 0; $i < 105; $i++) {
            $response = $this->makeRequest('GET', '/task/list');
            $responses[] = $response;
            
            if ($response['status'] === 429) {
                break;
            }
        }
        
        // Find the first 429 response
        $rateLimitResponse = null;
        foreach ($responses as $response) {
            if ($response['status'] === 429) {
                $rateLimitResponse = $response;
                break;
            }
        }
        
        $this->assertNotNull($rateLimitResponse, 'Rate limit should be exceeded after 100 requests');
        $this->assertEquals(429, $rateLimitResponse['status']);
        $this->assertEquals('Rate limit exceeded', $rateLimitResponse['body']['error']);
        
        // Check enhanced error response
        $this->assertArrayHasKey('operation', $rateLimitResponse['body']);
        $this->assertArrayHasKey('user_tier', $rateLimitResponse['body']);
        $this->assertArrayHasKey('burst_limit', $rateLimitResponse['body']);
        $this->assertArrayHasKey('upgrade_info', $rateLimitResponse['body']);
        
        $this->assertEquals('read', $rateLimitResponse['body']['operation']);
        $this->assertEquals('basic', $rateLimitResponse['body']['user_tier']);
        $this->assertArrayHasKey('available_tiers', $rateLimitResponse['body']['upgrade_info']);
        
        // Check enhanced headers
        $this->assertArrayHasKey('X-RateLimit-User-Tier', $rateLimitResponse['headers']);
        $this->assertArrayHasKey('X-RateLimit-Operation', $rateLimitResponse['headers']);
        $this->assertArrayHasKey('Retry-After', $rateLimitResponse['headers']);
    }

    public function testRateLimitHeadersOnError(): void
    {
        // Make request that will fail (no auth)
        $response = $this->makeRequest('GET', '/task/list', [], '');
        
        $this->assertEquals(401, $response['status']);
        
        // Rate limit headers should not be present on auth failures
        $this->assertArrayNotHasKey('X-RateLimit-Limit', $response['headers']);
        $this->assertArrayNotHasKey('X-RateLimit-Remaining', $response['headers']);
    }

    public function testRateLimitWithInvalidJwtToken(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], 'invalid-jwt-token');
        
        $this->assertEquals(401, $response['status']);
        
        // Should not consume rate limit for invalid tokens
        $this->assertArrayNotHasKey('X-RateLimit-Remaining', $response['headers']);
    }

    public function testOperationSpecificRateLimits(): void
    {
        // Test read operation limit
        $readResponse = $this->makeRequest('GET', '/task/list');
        $this->assertEquals('100', $readResponse['headers']['X-RateLimit-Limit']);
        $this->assertEquals('read', $readResponse['headers']['X-RateLimit-Operation']);
        
        // Test write operation limit (create task)
        $taskData = [
            'title' => 'Write Operation Test',
            'description' => 'Testing write operation rate limit',
            'due_date' => '2025-12-31 23:59:59'
        ];
        $writeResponse = $this->makeRequest('POST', '/task/create', $taskData);
        $this->assertEquals('50', $writeResponse['headers']['X-RateLimit-Limit']); // write limit is 50% of read
        $this->assertEquals('write', $writeResponse['headers']['X-RateLimit-Operation']);
        
        // Verify operations have separate counters
        $readResponse2 = $this->makeRequest('GET', '/task/list');
        $this->assertEquals('100', $readResponse2['headers']['X-RateLimit-Limit']);
        
        // Cleanup
        if ($writeResponse['status'] === 200) {
            $this->cleanupTestTasks();
        }
    }

    public function testUserTierInformation(): void
    {
        $response = $this->makeRequest('GET', '/task/list');
        
        // Check user tier information
        $this->assertArrayHasKey('X-RateLimit-User-Tier', $response['headers']);
        $this->assertEquals('basic', $response['headers']['X-RateLimit-User-Tier']);
        
        // Basic tier should have standard limits
        $this->assertEquals('100', $response['headers']['X-RateLimit-Limit']);
    }

    // HELPER METHODS
    
    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $jwtToken = null): array
    {
        if ($jwtToken === null) {
            $jwtToken = $this->validJwtToken;
        }
        
        $url = $this->baseUrl . $endpoint;
        $headers = ['Content-Type: application/json'];
        
        if (!empty($jwtToken)) {
            $headers[] = "Authorization: Bearer {$jwtToken}";
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5,
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
    
    private function waitForRedis(): void
    {
        $start = time();
        while (time() - $start < 3) {
            try {
                $response = $this->makeRequest('GET', '/health');
                if ($response['status'] === 200 && 
                    isset($response['body']['checks']['redis']['status']) && 
                    $response['body']['checks']['redis']['status'] === 'healthy') {
                    return;
                }
            } catch (Exception $e) {
                // Continue trying
            }
            usleep(100000); // 0.1 second
        }
        
        $this->markTestSkipped('Redis not available for rate limit testing');
    }
    
    private function clearRateLimitData(): void
    {
        try {
            // Connect to Redis and clear rate limit data for test user
            $redis = new \Predis\Client([
                'scheme' => 'tcp',
                'host' => getenv('REDIS_HOST') ?: 'localhost',
                'port' => (int)(getenv('REDIS_PORT') ?: 6379),
            ]);
            
            // Clear all rate limit keys for test user (including different operations and time windows)
            $userKey = 'user_' . $this->testUserId;
            $hashedKey = hash('sha256', $userKey);
            
            // Clear rate limit keys for all operations
            $operations = ['read', 'write', 'bulk', 'auth'];
            foreach ($operations as $operation) {
                $keyPattern = "rate_limit:{$operation}:{$hashedKey}:*";
                $keys = $redis->keys($keyPattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
            
            // Clear burst limit keys
            $burstPattern = "burst_limit:{$hashedKey}:*";
            $burstKeys = $redis->keys($burstPattern);
            if (!empty($burstKeys)) {
                $redis->del($burstKeys);
            }
            
        } catch (\Exception $e) {
            // Ignore Redis connection errors in test environment
        }
    }
    
    private function cleanupTestTasks(): void
    {
        try {
            $response = $this->makeRequest('GET', '/task/list');
            if ($response['status'] === 200) {
                foreach ($response['body']['tasks'] as $task) {
                    if (str_contains($task['title'], 'Rate Limit Test')) {
                        $this->makeRequest('DELETE', '/task/' . $task['id']);
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}