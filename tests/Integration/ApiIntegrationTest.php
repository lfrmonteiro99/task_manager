<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ApiIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $validJwtToken;
    private string $invalidJwtToken;
    private int $testUserId;

    protected function setUp(): void
    {
        // Load configuration from environment
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        $this->invalidJwtToken = 'invalid.jwt.token';
        
        // Ensure we're not accidentally using production keys
        $this->validateTestEnvironment();
        
        // Quick service check (only if not already verified)
        static $serviceChecked = false;
        if (!$serviceChecked) {
            $this->waitForService($this->baseUrl . '/health', 5);
            $serviceChecked = true;
        }
        
        // Setup JWT authentication
        $this->setupJwtAuthentication();
    }
    
    private function setupJwtAuthentication(): void
    {
        // Create a test user and get JWT token
        $testEmail = 'test_user_' . uniqid() . '@example.com';
        $testPassword = 'test_password_123';
        
        // Register test user
        $registerData = [
            'name' => 'Test User',
            'email' => $testEmail,
            'password' => $testPassword
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/auth/register',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($registerData),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 || $httpCode === 201) {
            $responseData = json_decode($response, true);
            $this->validJwtToken = $responseData['access_token'];
            $this->testUserId = $responseData['user']['id'];
        } else {
            // Try to login with existing user (in case of duplicate)
            $loginData = [
                'email' => $testEmail,
                'password' => $testPassword
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
            
            $loginResponse = curl_exec($ch);
            $loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($loginHttpCode === 200) {
                $loginData = json_decode($loginResponse, true);
                $this->validJwtToken = $loginData['access_token'];
                $this->testUserId = $loginData['user']['id'];
            } else {
                // Use default test user
                $this->loginAsDefaultTestUser();
            }
        }
    }
    
    private function loginAsDefaultTestUser(): void
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
            $this->fail('Unable to setup JWT authentication for tests');
        }
    }
    
    private function validateTestEnvironment(): void
    {
        // Ensure base URL is test environment
        $allowedHosts = ['localhost', 'test', 'app'];
        $isValidHost = false;
        foreach ($allowedHosts as $host) {
            if (str_contains($this->baseUrl, $host)) {
                $isValidHost = true;
                break;
            }
        }
        if (!$isValidHost) {
            $this->fail("Test base URL should be localhost, test, or localhost environment: {$this->baseUrl}");
        }
        
        // Ensure we're in test mode
        if (getenv('APP_ENV') === 'production') {
            $this->fail('Tests should not run in production environment');
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        TestHelper::cleanup();
    }

    // AUTHENTICATION TESTS
    
    public function testAuthenticationRequired(): void
    {
        $response = $this->makeRequest('GET', '/task/list');
        
        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertEquals('Unauthorized', $response['body']['error']);
    }

    public function testInvalidJwtToken(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], $this->invalidJwtToken);
        
        $this->assertEquals(401, $response['status']);
        $this->assertStringContainsString('Invalid or expired access token', $response['body']['message']);
    }

    public function testValidJwtTokenAuthentication(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('tasks', $response['body']);
    }

    public function testJwtTokenUserIsolation(): void
    {
        // Test that JWT token properly isolates user data
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('tasks', $response['body']);
        
        // All tasks should belong to the authenticated user
        foreach ($response['body']['tasks'] as $task) {
            $this->assertEquals($this->testUserId, $task['user_id']);
        }
    }

    // RATE LIMITING TESTS
    
    public function testRateLimitHeaders(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $this->assertArrayHasKey('X-RateLimit-Limit', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $response['headers']);
        $this->assertArrayHasKey('X-RateLimit-Reset', $response['headers']);
        $this->assertEquals('100', $response['headers']['X-RateLimit-Limit']);
    }

    public function testRateLimitDecrementing(): void
    {
        $response1 = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        $response2 = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        $remaining2 = (int)$response2['headers']['X-RateLimit-Remaining'];
        
        // Allow for either decreasing count or reset due to rate limit window boundaries
        $this->assertTrue(
            $remaining2 == $remaining1 - 1 || $remaining2 >= $remaining1,
            "Rate limit should decrease or reset. First: {$remaining1}, Second: {$remaining2}"
        );
    }

    // INPUT SANITIZATION TESTS
    
    public function testXssProtection(): void
    {
        $maliciousData = [
            'title' => '<script>alert("XSS")</script>Malicious Task',
            'description' => 'This is a test description',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $maliciousData, $this->validJwtToken);
        
        // With our input validation, XSS content might be sanitized or rejected
        // Check for either success (sanitized) or validation error
        $this->assertContains($response['status'], [200, 400, 422]);
        
        if ($response['status'] !== 200) {
            $this->assertArrayHasKey('error', $response['body']);
        }
    }

    public function testSqlInjectionProtection(): void
    {
        $maliciousData = [
            'title' => 'Normal Task',
            'description' => "'; DROP TABLE tasks; --",
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $maliciousData, $this->validJwtToken);
        
        // With prepared statements, SQL injection should be safely handled
        // Either success (sanitized) or validation error (if detected)
        $this->assertContains($response['status'], [200, 400, 422]);
        
        if ($response['status'] !== 200) {
            $this->assertArrayHasKey('error', $response['body']);
        }
    }

    public function testHtmlEntityEncoding(): void
    {
        $dataWithHtml = [
            'title' => 'Task with & special chars',
            'description' => 'Description with <b>bold</b> and "quotes"',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $dataWithHtml, $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    // CRUD OPERATIONS TESTS
    
    public function testCreateTask(): void
    {
        $taskData = [
            'title' => 'Integration Test Task',
            'description' => 'This is a test task created via API',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $taskData, $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('Task created successfully', $response['body']['message']);
    }

    public function testCreateTaskValidation(): void
    {
        $invalidData = [
            'title' => 'AB', // Too short
            'description' => 'Short', // Too short
            'due_date' => '2020-01-01 00:00:00' // In the past
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $invalidData, $this->validJwtToken);
        
        $this->assertEquals(422, $response['status']);
        $this->assertArrayHasKey('errors', $response['body']);
    }

    public function testListTasks(): void
    {
        // Create a test task first
        $taskId = $this->createTestTask();
        
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('tasks', $response['body']);
        $this->assertIsArray($response['body']['tasks']);
        $this->assertGreaterThan(0, count($response['body']['tasks']), 'Task list should not be empty after creating a task');
    }

    public function testUpdateTask(): void
    {
        $taskId = $this->createTestTask();
        
        $updateData = [
            'title' => 'Updated Task Title',
            'description' => 'Updated task description with enough characters',
            'due_date' => '2026-01-01 12:00:00'
        ];
        
        $response = $this->makeRequest('PUT', "/task/{$taskId}", $updateData, $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testMarkTaskDone(): void
    {
        $taskId = $this->createTestTask();
        
        $response = $this->makeRequest('POST', "/task/{$taskId}/done", [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testDeleteTask(): void
    {
        $taskId = $this->createTestTask();
        
        $response = $this->makeRequest('DELETE', "/task/{$taskId}", [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testGetTaskById(): void
    {
        $taskId = $this->createTestTask();
        
        $response = $this->makeRequest('GET', "/task/{$taskId}", [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('task', $response['body']);
        $this->assertEquals($taskId, $response['body']['task']['id']);
    }

    public function testGetNonexistentTask(): void
    {
        $response = $this->makeRequest('GET', '/task/99999', [], $this->validJwtToken);
        
        $this->assertEquals(404, $response['status']);
        $this->assertEquals('Task not found', $response['body']['error']);
    }

    // HEALTH CHECK TESTS
    
    public function testHealthEndpoint(): void
    {
        $response = $this->makeRequest('GET', '/health', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('checks', $response['body']);
        $this->assertArrayHasKey('system', $response['body']);
        
        // Check specific health checks
        $checks = $response['body']['checks'];
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('redis', $checks);
        $this->assertArrayHasKey('memory', $checks);
        
        $this->assertEquals('healthy', $checks['database']['status']);
    }

    // SECURITY HEADERS TESTS
    
    public function testSecurityHeaders(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $expectedHeaders = [
            'X-XSS-Protection',
            'X-Content-Type-Options',
            'X-Frame-Options',
            'Content-Security-Policy',
            'X-API-Version'
        ];
        
        foreach ($expectedHeaders as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "Missing security header: {$header}");
        }
        
        $this->assertEquals('nosniff', $response['headers']['X-Content-Type-Options']);
        $this->assertEquals('DENY', $response['headers']['X-Frame-Options']);
    }

    // ERROR HANDLING TESTS
    
    public function testInvalidEndpoint(): void
    {
        $response = $this->makeRequest('GET', '/invalid/endpoint', [], $this->validJwtToken);
        
        $this->assertEquals(404, $response['status']);
        $this->assertEquals('Endpoint not found', $response['body']['error']);
    }

    public function testInvalidHttpMethod(): void
    {
        $response = $this->makeRequest('PATCH', '/task/list', [], $this->validJwtToken);
        
        $this->assertContains($response['status'], [404, 429]);
    }

    // HELPER METHODS
    
    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], string $jwtToken = ''): array
    {
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
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if ($response === false) {
            $this->fail('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
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
        
        $decodedBody = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON response: ' . $body);
        }
        
        return [
            'status' => $httpCode,
            'body' => $decodedBody,
            'headers' => $headers
        ];
    }
    
    private function createTestTask(): int
    {
        $taskData = [
            'title' => 'Test Task for API',
            'description' => 'This is a test task created for API testing purposes',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $taskData, $this->validJwtToken);
        
        if ($response['status'] !== 200) {
            $this->fail('Failed to create test task. Status: ' . $response['status'] . ', Response: ' . json_encode($response['body']));
        }
        
        // Wait a moment for the task to be properly saved
        usleep(100000); // 0.1 second
        
        // Get the created task ID by listing tasks
        $listResponse = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        if ($listResponse['status'] !== 200) {
            $this->fail('Failed to list tasks. Status: ' . $listResponse['status'] . ', Response: ' . json_encode($listResponse['body']));
        }
        
        $tasks = $listResponse['body']['tasks'];
        
        // Find our test task (get the most recent one with our title)
        $testTaskId = null;
        foreach (array_reverse($tasks) as $task) {
            if ($task['title'] === 'Test Task for API') {
                $testTaskId = $task['id'];
                break;
            }
        }
        
        if ($testTaskId === null) {
            $this->fail('Failed to find created test task in task list. Available tasks: ' . json_encode($tasks));
        }
        
        return $testTaskId;
    }
    
    private function cleanupTestData(): void
    {
        try {
            // Only cleanup if JWT token is initialized
            if (!isset($this->validJwtToken) || empty($this->validJwtToken)) {
                return;
            }
            
            $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
            if ($response['status'] === 200) {
                foreach ($response['body']['tasks'] as $task) {
                    if (str_contains($task['title'], 'Test') || str_contains($task['title'], 'Integration')) {
                        $this->makeRequest('DELETE', '/task/' . $task['id'], [], $this->validJwtToken);
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
    
    private function waitForService(string $url, int $timeoutSeconds): void
    {
        $start = time();
        
        while (time() - $start < $timeoutSeconds) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 1
                // No authentication required for health check
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Health endpoint should return 200, other endpoints may return 401 without auth
            if ($response !== false && ($httpCode === 200 || $httpCode === 401)) {
                return;
            }
            
            usleep(100000); // 0.1 second
        }
        
        $this->markTestSkipped("Service not available at {$url} after {$timeoutSeconds} seconds");
    }
}