<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TaskSearchApiIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $authToken;
    private array $testTaskIds = [];

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        $this->authToken = $this->getAuthToken();
        $this->createTestTasks();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTasks();
    }

    public function testSearchByTextBasic(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?search=meeting&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('pagination', $response['body']);
        
        // Should have matching tasks
        $tasks = $response['body']['data'];
        if (!empty($tasks)) {
            foreach ($tasks as $task) {
                $this->assertTrue(
                    stripos($task['title'], 'meeting') !== false || 
                    stripos($task['description'], 'meeting') !== false,
                    'Task should match search term'
                );
            }
        }
    }

    public function testFilterByStatus(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?status=pending&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $this->assertFalse($task['done'], 'All tasks should be pending');
        }
    }

    public function testFilterByPriority(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?priority=high&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        // Test that the API accepts the priority parameter without error
        $this->assertIsArray($response['body']['data']);
    }

    public function testDateRangeFilter(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?due_date_from=2025-07-01&due_date_to=2025-12-31&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $taskDueDate = new DateTime($task['due_date']);
            $fromDate = new DateTime('2025-07-01');
            $toDate = new DateTime('2025-12-31 23:59:59');
            
            $this->assertGreaterThanOrEqual(
                $fromDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date should be after from date'
            );
            $this->assertLessThanOrEqual(
                $toDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date should be before to date'
            );
        }
    }

    public function testSortingByDueDate(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?sort_by=due_date&sort_direction=asc&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        $tasks = $response['body']['data'];
        if (count($tasks) > 1) {
            for ($i = 1; $i < count($tasks); $i++) {
                $prevDate = new DateTime($tasks[$i - 1]['due_date']);
                $currDate = new DateTime($tasks[$i]['due_date']);
                
                $this->assertLessThanOrEqual(
                    $currDate->getTimestamp(),
                    $prevDate->getTimestamp(),
                    'Tasks should be sorted by due date ascending'
                );
            }
        }
    }

    public function testCombinedFilters(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?search=task&status=pending&due_date_from=2025-07-01&sort_by=due_date&page=1&limit=5');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('pagination', $response['body']);
        
        // Verify pagination is working with search
        $pagination = $response['body']['pagination'];
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(5, $pagination['per_page']);
    }

    public function testOverdueFilter(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?overdue_only=1&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        // API should accept the parameter without error
        $this->assertIsArray($response['body']['data']);
    }

    private function getAuthToken(): string
    {
        $userData = [
            'name' => 'Search Test User',
            'email' => 'search_test_' . uniqid() . '@example.com',
            'password' => 'testpass123'
        ];

        $response = $this->makeRequest('POST', '/auth/register', $userData);
        if ($response['status'] !== 201) {
            throw new \Exception('Failed to register test user');
        }

        $loginResponse = $this->makeRequest('POST', '/auth/login', [
            'email' => $userData['email'],
            'password' => $userData['password']
        ]);

        if ($loginResponse['status'] !== 200) {
            throw new \Exception('Failed to login test user');
        }

        return $loginResponse['body']['access_token'];
    }

    private function createTestTasks(): void
    {
        $tasks = [
            [
                'title' => 'Important Meeting Preparation',
                'description' => 'Prepare slides for the quarterly business meeting',
                'due_date' => '2025-09-15 14:00:00'
            ],
            [
                'title' => 'Code Review Process',
                'description' => 'Review pull requests for the new feature implementation',
                'due_date' => '2025-08-28 17:00:00'
            ],
            [
                'title' => 'Database Optimization Task',
                'description' => 'Optimize database queries for better performance',
                'due_date' => '2025-10-10 12:00:00'
            ]
        ];

        foreach ($tasks as $taskData) {
            $response = $this->makeAuthenticatedRequest('POST', '/task/create', $taskData);
            if ($response['status'] === 201) {
                $this->testTaskIds[] = count($this->testTaskIds) + 1;
            }
        }
    }

    private function cleanupTestTasks(): void
    {
        // Tasks will be cleaned up when the test database is reset
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