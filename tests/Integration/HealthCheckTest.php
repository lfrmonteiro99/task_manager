<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HealthCheckTest extends TestCase
{
    private string $baseUrl;
    private string $validApiKey;

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        $this->validApiKey = getenv('TEST_API_KEY') ?: 'dev-key-123';
    }

    public function testHealthEndpointStructure(): void
    {
        $response = $this->makeRequest('GET', '/health');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('status', $response['body']);
        $this->assertArrayHasKey('timestamp', $response['body']);
        $this->assertArrayHasKey('duration_ms', $response['body']);
        $this->assertArrayHasKey('version', $response['body']);
        $this->assertArrayHasKey('environment', $response['body']);
        $this->assertArrayHasKey('checks', $response['body']);
        $this->assertArrayHasKey('system', $response['body']);
    }

    public function testHealthChecksSubsystems(): void
    {
        $response = $this->makeRequest('GET', '/health');
        $checks = $response['body']['checks'];
        
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('redis', $checks);
        $this->assertArrayHasKey('disk_space', $checks);
        $this->assertArrayHasKey('memory', $checks);
        $this->assertArrayHasKey('environment', $checks);
        
        // Verify each check has required fields
        foreach ($checks as $checkName => $check) {
            $this->assertArrayHasKey('status', $check, "Check {$checkName} missing status");
            $this->assertArrayHasKey('message', $check, "Check {$checkName} missing message");
            $this->assertContains($check['status'], ['healthy', 'degraded', 'critical'], 
                "Check {$checkName} has invalid status: {$check['status']}");
        }
    }

    public function testSystemInfoPresent(): void
    {
        $response = $this->makeRequest('GET', '/health');
        $system = $response['body']['system'];
        
        $this->assertArrayHasKey('uptime', $system);
        $this->assertArrayHasKey('load_average', $system);
        $this->assertArrayHasKey('php_version', $system);
        $this->assertArrayHasKey('memory_usage', $system);
        $this->assertArrayHasKey('memory_peak', $system);
    }

    public function testHealthEndpointPerformance(): void
    {
        $start = microtime(true);
        $response = $this->makeRequest('GET', '/health');
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertEquals(200, $response['status']);
        $this->assertLessThan(1000, $duration, 'Health check should complete within 1 second');
        $this->assertIsFloat($response['body']['duration_ms']);
        $this->assertGreaterThan(0, $response['body']['duration_ms']);
    }

    private function makeRequest(string $method, string $endpoint): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Content-Type: application/json',
            "Authorization: Bearer {$this->validApiKey}"
        ];
        
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
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if ($response === false) {
            $this->fail('cURL error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        $body = substr($response, $headerSize);
        $decodedBody = json_decode($body, true);
        
        return [
            'status' => $httpCode,
            'body' => $decodedBody ?: []
        ];
    }
}