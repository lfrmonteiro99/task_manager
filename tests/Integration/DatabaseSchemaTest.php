<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class DatabaseSchemaTest extends TestCase
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
        $testEmail = 'schema_test_' . uniqid() . '@example.com';
        $testPassword = 'test_password_123';
        
        
        // Register test user
        $registerData = [
            'name' => 'Schema Test User',
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
            $this->fail('Unable to setup authentication for database schema test');
        }
    }

    public function testTaskCreationWithCorrectSchema(): void
    {
        $taskData = [
            'title' => 'Schema Test Task',
            'description' => 'Testing that task creation works with updated schema',
            'due_date' => '2025-12-31 23:59:59',
            'priority' => 'high'
        ];
        
        $response = $this->makeRequest('POST', '/task/create', $taskData);
        
        $this->assertEquals(200, $response['status'], 'Task creation should succeed with correct schema');
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('Task created successfully', $response['body']['message']);
    }

    public function testTaskListingWithCorrectSchema(): void
    {
        // First create a task
        $taskData = [
            'title' => 'Schema List Test Task',
            'description' => 'Testing task listing with updated schema',
            'due_date' => '2025-12-31 23:59:59',
            'priority' => 'medium'
        ];
        
        $createResponse = $this->makeRequest('POST', '/task/create', $taskData);
        $this->assertEquals(200, $createResponse['status']);
        
        // Then list tasks
        $listResponse = $this->makeRequest('GET', '/task/list');
        
        $this->assertEquals(200, $listResponse['status'], 'Task listing should work with correct schema');
        $this->assertArrayHasKey('tasks', $listResponse['body']);
        $this->assertIsArray($listResponse['body']['tasks']);
        
        // Check that tasks have expected fields
        if (!empty($listResponse['body']['tasks'])) {
            $task = $listResponse['body']['tasks'][0];
            $this->assertArrayHasKey('id', $task);
            $this->assertArrayHasKey('title', $task);
            $this->assertArrayHasKey('description', $task);
            $this->assertArrayHasKey('due_date', $task);
            $this->assertArrayHasKey('created_at', $task); // Should use created_at from database
            $this->assertArrayHasKey('priority', $task);
            $this->assertArrayHasKey('status', $task);
            $this->assertArrayHasKey('done', $task);
        }
    }

    public function testTaskStatisticsWithCorrectSchema(): void
    {
        $response = $this->makeRequest('GET', '/task/statistics');
        
        $this->assertEquals(200, $response['status'], 'Task statistics should work with correct schema');
        $this->assertArrayHasKey('statistics', $response['body']);
        
        $stats = $response['body']['statistics'];
        $this->assertArrayHasKey('total_tasks', $stats);
        $this->assertArrayHasKey('completed_tasks', $stats);
        $this->assertArrayHasKey('pending_tasks', $stats);
        $this->assertArrayHasKey('overdue_tasks', $stats);
        $this->assertArrayHasKey('completion_rate', $stats);
        $this->assertArrayHasKey('last_activity', $stats);
    }

    public function testOverdueTasksWithCorrectSchema(): void
    {
        $response = $this->makeRequest('GET', '/task/overdue');
        
        $this->assertEquals(200, $response['status'], 'Overdue tasks should work with correct schema');
        $this->assertArrayHasKey('overdue_tasks', $response['body']);
        $this->assertIsArray($response['body']['overdue_tasks']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->validJwtToken
        ];
        
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
}