<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    private string $baseUrl;
    private string $validJwtToken;

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        $this->setupAuthentication();
    }

    protected function tearDown(): void
    {
        TestHelper::cleanup();
    }

    private function setupAuthentication(): void
    {
        $testEmail = 'middleware_test_' . uniqid() . '@example.com';
        $testPassword = 'test_password_123';
        
        $registerData = [
            'name' => 'Middleware Test User',
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
        } else {
            $this->fail('Unable to setup authentication for middleware test');
        }
    }

    public function testAuthMiddlewareBlocksUnauthenticatedRequests(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], '');
        
        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertEquals('Unauthorized', $response['body']['error']);
        $this->assertStringContainsString('Missing access token', $response['body']['message']);
    }

    public function testAuthMiddlewareAllowsValidTokens(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('tasks', $response['body']);
    }

    public function testAuthMiddlewareRejectsInvalidTokens(): void
    {
        $response = $this->makeRequest('GET', '/task/list', [], 'invalid.jwt.token');
        
        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('Invalid or expired access token', $response['body']['message']);
    }

    public function testSecurityHeadersMiddleware(): void
    {
        $response = $this->makeRequestWithHeaders('GET', '/task/list', [], $this->validJwtToken);
        
        $expectedHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block'
        ];
        
        foreach ($expectedHeaders as $header => $expectedValue) {
            $this->assertArrayHasKey($header, $response['headers'], "Missing security header: {$header}");
            $this->assertEquals($expectedValue, $response['headers'][$header], "Incorrect value for header: {$header}");
        }
        
        // Check that Content-Security-Policy header exists
        $this->assertArrayHasKey('Content-Security-Policy', $response['headers'], 'Missing Content-Security-Policy header');
    }

    public function testRateLimitHeaders(): void
    {
        $response = $this->makeRequestWithHeaders('GET', '/task/list', [], $this->validJwtToken);
        
        $rateLimitHeaders = [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset'
        ];
        
        foreach ($rateLimitHeaders as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "Missing rate limit header: {$header}");
            $this->assertIsNumeric($response['headers'][$header], "Rate limit header should be numeric: {$header}");
        }
    }

    public function testRequestContextPopulation(): void
    {
        // Make a request that should populate the request context
        $response = $this->makeRequest('GET', '/auth/profile', [], $this->validJwtToken);
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('user', $response['body']);
        
        // The user data should be properly set in the request context
        $user = $response['body']['user'];
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('name', $user);
        $this->assertIsInt($user['id']);
        $this->assertIsString($user['email']);
        $this->assertStringContainsString('middleware_test_', $user['email']);
    }

    public function testCorsHeaders(): void
    {
        $response = $this->makeRequestWithHeaders('OPTIONS', '/task/list', [], '');
        
        // CORS headers should be present for OPTIONS requests
        $corsHeaders = [
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers'
        ];
        
        foreach ($corsHeaders as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "Missing CORS header: {$header}");
        }
    }

    public function testPublicEndpointsBypassAuth(): void
    {
        $publicEndpoints = [
            '/health',
            '/auth/register',
            '/auth/login'
        ];
        
        foreach ($publicEndpoints as $endpoint) {
            $response = $this->makeRequest('GET', $endpoint, [], '');
            
            // Public endpoints should not return 401 Unauthorized
            $this->assertNotEquals(401, $response['status'], 
                "Public endpoint {$endpoint} should not require authentication");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], string $token = ''): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = ['Content-Type: application/json'];
        
        if (!empty($token)) {
            $headers[] = "Authorization: Bearer {$token}";
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 5,
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false) {
            $this->fail('cURL error occurred');
        }
        
        $decodedBody = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Invalid JSON response: ' . $response);
        }
        
        return [
            'status' => $httpCode,
            'body' => $decodedBody
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function makeRequestWithHeaders(string $method, string $endpoint, array $data = [], string $token = ''): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = ['Content-Type: application/json'];
        
        if (!empty($token)) {
            $headers[] = "Authorization: Bearer {$token}";
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
            $this->fail('cURL error occurred');
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
        
        // Handle empty responses (like OPTIONS requests)
        if (empty(trim($body))) {
            $decodedBody = [];
        } else {
            $decodedBody = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->fail('Invalid JSON response: ' . $body);
            }
        }
        
        return [
            'status' => $httpCode,
            'body' => $decodedBody,
            'headers' => $headers
        ];
    }
}