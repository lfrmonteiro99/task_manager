<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PaginationIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $authToken;
    private array $testTaskIds = [];

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        // Register and login to get auth token
        $this->authToken = $this->getAuthToken();
        
        // Create test tasks for pagination testing
        $this->createTestTasks();
    }

    protected function tearDown(): void
    {
        // Clean up test tasks
        $this->cleanupTestTasks();
    }

    public function testPaginatedTaskListWithDefaults(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?page=1');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('pagination', $response['body']);
        
        $pagination = $response['body']['pagination'];
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(10, $pagination['per_page']);
        $this->assertGreaterThanOrEqual(15, $pagination['total_items']); // We created 15 test tasks
        $this->assertLessThanOrEqual(10, count($response['body']['data'])); // Max 10 per page
    }

    public function testPaginatedTaskListWithCustomLimit(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?page=1&limit=5');
        
        $this->assertEquals(200, $response['status']);
        $this->assertLessThanOrEqual(5, count($response['body']['data']));
        $this->assertEquals(5, $response['body']['pagination']['per_page']);
    }

    public function testPaginatedTaskListSecondPage(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?page=2&limit=5');
        
        $this->assertEquals(200, $response['status']);
        $pagination = $response['body']['pagination'];
        
        $this->assertEquals(2, $pagination['current_page']);
        $this->assertTrue($pagination['has_previous']);
        $this->assertEquals(1, $pagination['previous_page']);
        
        if ($pagination['total_items'] > 10) {
            $this->assertTrue($pagination['has_next']);
            $this->assertEquals(3, $pagination['next_page']);
        }
    }

    public function testBackwardCompatibilityWithoutPagination(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('tasks', $response['body']);
        $this->assertArrayNotHasKey('pagination', $response['body']);
    }

    public function testPaginationWithInvalidParameters(): void
    {
        // Test with invalid page number
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?page=0&limit=5');
        $this->assertEquals(200, $response['status']);
        $this->assertEquals(1, $response['body']['pagination']['current_page']); // Should default to 1

        // Test with invalid limit
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?page=1&limit=1000');
        $this->assertEquals(200, $response['status']);
        $this->assertEquals(10, $response['body']['pagination']['per_page']); // Should default to 10
    }

    private function getAuthToken(): string
    {
        // Register a test user
        $userData = [
            'name' => 'Pagination Test User',
            'email' => 'pagination_test_' . uniqid() . '@example.com',
            'password' => 'testpassword123'
        ];

        $this->makeRequest('POST', '/auth/register', $userData);

        // Login to get token
        $loginResponse = $this->makeRequest('POST', '/auth/login', [
            'email' => $userData['email'],
            'password' => $userData['password']
        ]);

        return $loginResponse['body']['access_token'];
    }

    private function createTestTasks(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $taskData = [
                'title' => "Pagination Test Task $i",
                'description' => "Description for test task $i",
                'due_date' => date('Y-m-d H:i:s', strtotime("+$i days")),
                'priority' => $i % 3 === 0 ? 'high' : ($i % 2 === 0 ? 'medium' : 'low')
            ];

            $response = $this->makeAuthenticatedRequest('POST', '/task/create', $taskData);
            if ($response['status'] === 201) {
                $this->testTaskIds[] = $i; // Store for cleanup
            }
        }
    }

    private function cleanupTestTasks(): void
    {
        // In a real scenario, you might want to delete the test tasks
        // For now, we'll just clear the array
        $this->testTaskIds = [];
    }

    private function makeAuthenticatedRequest(string $method, string $endpoint, array $data = []): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->authToken,
            'Content-Type: application/json'
        ];

        return $this->makeRequest($method, $endpoint, $data, $headers);
    }

    private function makeRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json'
            ], $headers),
            CURLOPT_POSTFIELDS => $method !== 'GET' ? json_encode($data) : null,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("Curl request failed: $error");
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . $response);
        }

        return [
            'status' => $status,
            'body' => $decodedResponse ?? []
        ];
    }
}