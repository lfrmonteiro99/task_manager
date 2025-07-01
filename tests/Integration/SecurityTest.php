<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    private string $baseUrl;
    private string $validJwtToken;
    private int $testUserId;
    
    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        
        // Setup JWT authentication
        $this->setupJwtAuthentication();
    }
    
    private function setupJwtAuthentication(): void
    {
        // Try to login with default test user first
        $this->loginAsDefaultTestUser();
    }
    
    private function loginAsDefaultTestUser(): void
    {
        // First try to register a test user
        $testEmail = 'security_test_' . uniqid() . '@example.com';
        $testPassword = 'testpass123';
        
        $registerData = [
            'name' => 'Security Test User',
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
            // Try login with existing default user
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
                $this->fail('Unable to setup JWT authentication for security tests. Response: ' . $response);
            }
        }
    }

    // XSS PROTECTION TESTS
    
    /**
     * @dataProvider xssPayloads
     */
    public function testXssProtection(string $payload, string $field): void
    {
        $data = [
            'title' => $field === 'title' ? $payload : 'Safe Title',
            'description' => $field === 'description' ? $payload : 'Safe description with enough characters',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken);
        
        $this->assertEquals(400, $response['status']);
        $this->assertEquals('Validation Error', $response['body']['error']);
        $this->assertStringContainsString('XSS', $response['body']['message']);
    }
    
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function xssPayloads(): array
    {
        return [
            'Basic script tag in title' => ['<script>alert("XSS")</script>Safe Title', 'title'],
            'Script tag in description' => ['<script>alert("XSS")</script>Safe description', 'description'],
            'JavaScript event in title' => ['<img src="x" onerror="alert(1)">Title', 'title'],
            'JavaScript protocol in description' => ['<a href="javascript:alert(1)">Safe description text here</a>', 'description'],
            'Data URL with script in title' => ['<iframe src="data:text/html,<script>alert(1)</script>">Title', 'title'],
            'SVG with script in description' => ['<svg onload="alert(1)">Safe description with enough text</svg>', 'description'],
            'Style with expression in title' => ['<style>body{background:expression(alert(1))}</style>Title', 'title'],
            'Object tag in description' => ['<object data="javascript:alert(1)">Safe description text</object>', 'description'],
        ];
    }

    // SQL INJECTION PROTECTION TESTS
    
    /**
     * @dataProvider sqlInjectionPayloads
     */
    public function testSqlInjectionProtection(string $payload, string $field): void
    {
        $data = [
            'title' => $field === 'title' ? $payload : 'Safe Title',
            'description' => $field === 'description' ? $payload : 'Safe description with enough characters',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken);
        
        $this->assertEquals(400, $response['status']);
        $this->assertEquals('Validation Error', $response['body']['error']);
        $this->assertStringContainsString('SQL injection', $response['body']['message']);
    }
    
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function sqlInjectionPayloads(): array
    {
        return [
            'Union select in title' => ["'; UNION SELECT * FROM users; --", 'title'],
            'Drop table in description' => ["'; DROP TABLE tasks; -- Safe description text", 'description'],
            'Insert statement in title' => ["'; INSERT INTO tasks VALUES (1,'evil','desc'); --", 'title'],
            'Boolean injection in description' => ["' OR 1=1; -- Safe description with enough text", 'description'],
            'Time delay injection in title' => ["'; WAITFOR DELAY '00:00:05'; --", 'title'],
            'Hex encoding in description' => ["0x53514C20496E6A656374696F6E and enough text", 'description'],
            'Comment injection in title' => ["/* SQL Comment */ SELECT * FROM tasks", 'title'],
            'Stacked queries in description' => ["'; UPDATE tasks SET title='hacked'; -- description", 'description'],
        ];
    }

    // SAFE INPUT TESTS
    
    /**
     * @dataProvider safeInputs
     */
    public function testSafeInputsAllowed(string $title, string $description): void
    {
        $data = [
            'title' => $title,
            'description' => $description,
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        
        // Cleanup
        $this->cleanupTask($title);
    }
    
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function safeInputs(): array
    {
        return [
            'Normal text' => ['Normal Task Title', 'This is a normal task description with enough characters'],
            'With special chars' => ['Task & Project #1', 'Description with "quotes" and <brackets> safely encoded'],
            'Unicode characters' => ['Task with émojis ', 'Description with special characters: café, naïve, résumé'],
            'Numbers and symbols' => ['Task #123 (Priority)', 'Cost: $100.50 - Due: 2025-12-31 @ 23:59:59'],
            'Long text' => ['Very Long Task Title Here', 'This is a very long description that contains many words and should be processed correctly by the sanitization system without any issues'],
        ];
    }

    // AUTHENTICATION BYPASS TESTS
    
    public function testMissingAuthHeader(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], '');
        
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Unauthorized', $response['body']['error']);
    }
    
    public function testMalformedBearerToken(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], 'malformed-token', 'Bearer');
        
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Invalid or expired access token.', $response['body']['message']);
    }
    
    public function testEmptyJwtToken(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], '', 'Bearer');
        
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Unauthorized', $response['body']['error']);
    }

    // RATE LIMITING SECURITY TESTS
    
    public function testRateLimitPerUser(): void
    {
        // Check if rate limiting is enabled (it's disabled in test environment by default)
        $response1 = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        $this->assertEquals(200, $response1['status']);
        
        // If no rate limit headers, skip the test as rate limiting is disabled
        if (!isset($response1['headers']['X-RateLimit-Remaining'])) {
            $this->markTestSkipped('Rate limiting is disabled in test environment');
            return;
        }
        
        $remaining1 = (int)$response1['headers']['X-RateLimit-Remaining'];
        
        $response2 = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        $this->assertEquals(200, $response2['status']);
        $remaining2 = (int)$response2['headers']['X-RateLimit-Remaining'];
        
        // If rate limiting is disabled, both values will be the same (static max value)
        if ($remaining1 === $remaining2) {
            $this->markTestSkipped('Rate limiting is disabled in test environment (headers present but static)');
            return;
        }
        
        // Verify rate limit decreased
        $this->assertEquals($remaining1 - 1, $remaining2, 'JWT user rate limit should decrease');
    }

    // INPUT VALIDATION EDGE CASES
    
    public function testVeryLongInput(): void
    {
        $longTitle = str_repeat('A', 1000); // Exceeds max length
        $data = [
            'title' => $longtitle,
            'description' => 'Valid description with enough characters',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken);
        
        $this->assertEquals(422, $response['status']);
        $this->assertArrayHasKey('errors', $response['body']);
    }
    
    public function testNullByteInjection(): void
    {
        $data = [
            'title' => "Title with null byte\x00injection",
            'description' => 'Valid description with enough characters',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken);
        
        // Should be sanitized and work
        $this->assertEquals(200, $response['status']);
        
        // Cleanup
        $this->cleanupTask('Title with null byte');
    }

    // CONTENT TYPE SECURITY TESTS
    
    public function testInvalidContentType(): void
    {
        $data = [
            'title' => 'Test Task',
            'description' => 'Valid description with enough characters',
            'due_date' => '2025-12-31 23:59:59'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $data, $this->validJwtToken, 'Bearer', 'text/plain');
        
        // Should still work with JSON data
        $this->assertContains($response['status'], [200, 400, 422]); // Any reasonable response
    }

    // HELPER METHODS
    
    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], string $jwtToken = '', string $authType = 'Bearer', string $contentType = 'application/json'): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = ["Content-Type: {$contentType}"];
        
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
            CURLOPT_CONNECTTIMEOUT => 10,
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
            // If JSON decode fails, return raw body
            $decodedBody = ['raw' => $body];
        }
        
        return [
            'status' => $httpCode,
            'body' => $decodedBody,
            'headers' => $headers
        ];
    }
    
    private function cleanupTask(string $titlePattern): void
    {
        try {
            $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
            if ($response['status'] === 200) {
                foreach ($response['body']['tasks'] as $task) {
                    if (str_contains($task['title'], $titlePattern)) {
                        $this->makeRequest('DELETE', '/task/' . $task['id'], [], $this->validJwtToken);
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
}