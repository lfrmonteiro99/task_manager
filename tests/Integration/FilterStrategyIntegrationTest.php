<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Repositories\Filters\FilterChain;
use App\Repositories\Filters\FilterFactory;

/**
 * Integration tests for Filter Strategy patterns working with actual TaskRepository
 */
class FilterStrategyIntegrationTest extends TestCase
{
    private string $baseUrl;
    private string $authToken;
    private array $testTaskIds = [];

    protected function setUp(): void
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        $this->authToken = $this->getAuthToken();
        $this->createDiverseTestTasks();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTasks();
    }

    public function testFilterChainWithTextSearchIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?search=urgent&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $matchFound = stripos($task['title'], 'urgent') !== false || 
                         stripos($task['description'], 'urgent') !== false;
            $this->assertTrue($matchFound, 'Task should match text search for "urgent"');
        }
    }

    public function testFilterChainWithStatusFilterIntegration(): void
    {
        // First mark one task as completed
        $listResponse = $this->makeAuthenticatedRequest('GET', '/task/list?page=1&limit=1');
        if (!empty($listResponse['body']['data'])) {
            $taskId = $listResponse['body']['data'][0]['id'];
            $this->makeAuthenticatedRequest('PUT', "/task/{$taskId}/done");
        }

        // Test pending status filter
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?status=pending&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $this->assertFalse($task['done'], 'All tasks should be pending (not done)');
        }

        // Test completed status filter
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?status=completed&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $this->assertTrue($task['done'], 'All tasks should be completed (done)');
        }
    }

    public function testFilterChainWithPriorityFilterIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?priority=urgent&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        // Since our test tasks include urgent priority, we should get results
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            // Task should have priority information
            $this->assertArrayHasKey('priority', $task);
        }
    }

    public function testFilterChainWithDateRangeFilterIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', 
            '/task/list?due_date_from=2025-07-01&due_date_to=2025-12-31&page=1&limit=10');
        
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

    public function testFilterChainWithCombinedFiltersIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', 
            '/task/list?search=project&status=pending&priority=high&due_date_from=2025-07-01&sort_by=due_date&page=1&limit=5');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('pagination', $response['body']);
        
        // Verify all filters can work together
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            // Should match text search
            $matchFound = stripos($task['title'], 'project') !== false || 
                         stripos($task['description'], 'project') !== false;
            if (!empty($tasks)) {
                $this->assertTrue($matchFound, 'Task should match text search for "project"');
            }
            
            // Should be pending
            $this->assertFalse($task['done'], 'Task should be pending');
            
            // Should have due date after 2025-07-01
            $taskDueDate = new DateTime($task['due_date']);
            $fromDate = new DateTime('2025-07-01');
            $this->assertGreaterThanOrEqual(
                $fromDate->getTimestamp(),
                $taskDueDate->getTimestamp(),
                'Task due date should be after from date'
            );
        }
        
        // Verify pagination is working
        $pagination = $response['body']['pagination'];
        $this->assertEquals(1, $pagination['current_page']);
        $this->assertEquals(5, $pagination['per_page']);
    }

    public function testFilterChainWithSortingIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', 
            '/task/list?sort_by=due_date&sort_direction=asc&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
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

    public function testFilterChainWithUrgencyFilterIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?urgency=normal&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        // API should handle urgency filter without error
        $this->assertIsArray($response['body']['data']);
    }

    public function testFilterChainWithOverdueOnlyFilterIntegration(): void
    {
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?overdue_only=1&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('data', $response['body']);
        
        // API should handle overdue_only filter without error
        $this->assertIsArray($response['body']['data']);
    }

    public function testFilterChainWithDoneStatusFilterIntegration(): void
    {
        // Mark a task as done first
        $listResponse = $this->makeAuthenticatedRequest('GET', '/task/list?page=1&limit=1');
        if (!empty($listResponse['body']['data'])) {
            $taskId = $listResponse['body']['data'][0]['id'];
            $this->makeAuthenticatedRequest('PUT', "/task/{$taskId}/done");
        }

        // Test done=1 filter
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?done=1&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $this->assertTrue($task['done'], 'All tasks should be marked as done');
        }

        // Test done=0 filter
        $response = $this->makeAuthenticatedRequest('GET', '/task/list?done=0&page=1&limit=10');
        
        $this->assertEquals(200, $response['status']);
        $tasks = $response['body']['data'];
        foreach ($tasks as $task) {
            $this->assertFalse($task['done'], 'All tasks should not be marked as done');
        }
    }

    public function testFilterChainPerformanceWithManyFilters(): void
    {
        $start = microtime(true);
        
        $response = $this->makeAuthenticatedRequest('GET', 
            '/task/list?search=test&status=pending&priority=high&due_date_from=2025-01-01&due_date_to=2025-12-31&created_from=2024-01-01&urgency=normal&sort_by=due_date&sort_direction=asc&page=1&limit=10');
        
        $end = microtime(true);
        $responseTime = $end - $start;
        
        $this->assertEquals(200, $response['status']);
        $this->assertLessThan(2.0, $responseTime, 'Complex filter query should complete within 2 seconds');
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertArrayHasKey('pagination', $response['body']);
    }

    public function testFilterChainUserIsolationWithFilters(): void
    {
        // Create a second user to test isolation
        $user2Token = $this->getSecondUserToken();
        $this->createTasksForSecondUser($user2Token);

        // User 1 searches for their tasks
        $user1Response = $this->makeAuthenticatedRequest('GET', '/task/list?search=project&page=1&limit=10');
        
        // User 2 searches for their tasks  
        $user2Response = $this->makeAuthenticatedRequest('GET', '/task/list?search=test', [], [], $user2Token);

        $this->assertEquals(200, $user1Response['status']);
        $this->assertEquals(200, $user2Response['status']);

        // Verify users don't see each other's tasks even with same search terms
        $user1Tasks = $user1Response['body']['data'] ?? [];
        $user2Tasks = $user2Response['body']['data'] ?? [];

        // Extract task IDs
        $user1TaskIds = array_column($user1Tasks, 'id');
        $user2TaskIds = array_column($user2Tasks, 'id');

        // No overlap should exist
        $overlap = array_intersect($user1TaskIds, $user2TaskIds);
        $this->assertEmpty($overlap, 'Users should not see each other\'s tasks even with filters');
    }

    private function getAuthToken(): string
    {
        $userData = [
            'name' => 'Filter Integration Test User',
            'email' => 'filter_integration_' . uniqid() . '@example.com',
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

    private function getSecondUserToken(): string
    {
        $userData = [
            'name' => 'Second Filter Test User',
            'email' => 'filter_integration_2_' . uniqid() . '@example.com',
            'password' => 'testpass123'
        ];

        $response = $this->makeRequest('POST', '/auth/register', $userData);
        $loginResponse = $this->makeRequest('POST', '/auth/login', [
            'email' => $userData['email'],
            'password' => $userData['password']
        ]);

        return $loginResponse['body']['access_token'];
    }

    private function createDiverseTestTasks(): void
    {
        $tasks = [
            [
                'title' => 'Urgent Project Deadline',
                'description' => 'Complete the urgent project deliverables',
                'due_date' => '2025-08-15 14:00:00',
                'priority' => 'urgent'
            ],
            [
                'title' => 'High Priority Code Review',
                'description' => 'Review critical code changes for the project',
                'due_date' => '2025-09-28 17:00:00',
                'priority' => 'high'
            ],
            [
                'title' => 'Medium Priority Documentation',
                'description' => 'Update project documentation and guides',
                'due_date' => '2025-10-10 12:00:00',
                'priority' => 'medium'
            ],
            [
                'title' => 'Low Priority Database Cleanup',
                'description' => 'Clean up old database entries',
                'due_date' => '2025-11-15 09:00:00',
                'priority' => 'low'
            ],
            [
                'title' => 'Research Task for Future',
                'description' => 'Research new technologies for the project',
                'due_date' => '2026-01-30 16:00:00',
                'priority' => 'medium'
            ]
        ];

        foreach ($tasks as $taskData) {
            $response = $this->makeAuthenticatedRequest('POST', '/task/create', $taskData);
            if ($response['status'] === 200) {
                $this->testTaskIds[] = count($this->testTaskIds) + 1;
            }
        }
    }

    private function createTasksForSecondUser(string $token): void
    {
        $tasks = [
            [
                'title' => 'Second User Test Task',
                'description' => 'This is a test task for the second user',
                'due_date' => '2025-09-15 14:00:00'
            ]
        ];

        foreach ($tasks as $taskData) {
            $this->makeAuthenticatedRequest('POST', '/task/create', $taskData, [], $token);
        }
    }

    private function cleanupTestTasks(): void
    {
        // Tasks will be cleaned up when the test database is reset
        $this->testTaskIds = [];
    }

    private function makeAuthenticatedRequest(string $method, string $endpoint, array $data = [], array $extraHeaders = [], ?string $token = null): array
    {
        $headers = [
            'Authorization: Bearer ' . ($token ?: $this->authToken),
            'Content-Type: application/json'
        ];

        return $this->makeRequest($method, $endpoint, $data, array_merge($headers, $extraHeaders));
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