# API Reference

Complete API documentation for the Task Manager API.

## Base URL
```
http://localhost:8080
```

## Authentication

The API uses JWT (JSON Web Token) authentication with refresh tokens for secure, stateless authentication.

### Authentication Flow
1. Register or login to receive JWT tokens
2. Use access token in Authorization header
3. Refresh tokens when they expire

### Token Usage
```http
Authorization: Bearer your-jwt-access-token
```

### Token Configuration
- **Access Token**: 1 hour expiration (3600 seconds)
- **Refresh Token**: 7 days expiration
- **Algorithm**: HS256 with secure secret key
- **Cache**: 5-minute validation cache for performance

## Pagination

The API supports pagination for list endpoints to improve performance and reduce data transfer.

### Pagination Parameters
- `page`: Page number (default: 1)
- `limit`: Items per page (default: 20, max: 100)

### Pagination Response Format
```json
{
  "data": [...],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_items": 150,
    "total_pages": 8,
    "has_previous": false,
    "has_next": true,
    "previous_page": null,
    "next_page": 2
  }
}
```

## Rate Limiting

- **Default Limit**: 100 requests per hour per user
- **Implementation**: Redis-based per-user rate limiting
- **Headers Returned**:
  - `X-RateLimit-Limit`: Maximum requests allowed
  - `X-RateLimit-Remaining`: Requests remaining in current window
  - `X-RateLimit-Reset`: Unix timestamp when limit resets

## Response Format

All responses follow a consistent JSON format:

### Success Response
```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed successfully"
}
```

### Error Response
```json
{
  "error": "Error Type",
  "message": "Detailed error message",
  "status_code": 400
}
```

## Endpoints

### Authentication Endpoints

#### POST /auth/register
Register a new user account.

**Headers**: 
- `Content-Type: application/json`

**Request Body**:
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "securepass123"
}
```

**Validation Rules**:
- `name`: Required, string, 2-50 characters
- `email`: Required, valid email format, unique
- `password`: Required, minimum 8 characters, must contain letters and numbers

**Response (201 Created)**:
```json
{
  "success": true,
  "message": "User registered successfully",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2025-06-29 10:00:00"
  }
}
```

#### POST /auth/login
Authenticate user and receive JWT tokens.

**Headers**: 
- `Content-Type: application/json`

**Request Body**:
```json
{
  "email": "john@example.com",
  "password": "securepass123"
}
```

**Response (200 OK)**:
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ1c2VyX2lkIjoxLCJ0eXBlIjoicmVmcmVzaCIsImlhdCI6MTczNTI5OTg5MywiZXhwIjoxNzM1OTA0NjkzfQ...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

#### POST /auth/refresh
Refresh access token using refresh token.

**Headers**: 
- `Content-Type: application/json`

**Request Body**:
```json
{
  "refresh_token": "your-refresh-token"
}
```

**Response (200 OK)**:
```json
{
  "access_token": "new-access-token",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### GET /auth/profile
Get current user profile.

**Headers**: JWT authentication required

**Response (200 OK)**:
```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2025-06-26 10:00:00",
    "task_statistics": {
      "total_tasks": 25,
      "completed_tasks": 18,
      "pending_tasks": 7,
      "completion_rate": 72.0
    }
  }
}
```

#### POST /auth/logout
Invalidate current session and refresh token.

**Headers**: JWT authentication required

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Successfully logged out"
}
```

### Health Check

#### GET /health
Returns system health status and metrics.

**Headers**: Authentication required

**Response (200 OK)**:
```json
{
  "status": "healthy",
  "timestamp": "2025-06-29T00:00:00+00:00",
  "duration_ms": 5.23,
  "version": "1.0.0",
  "environment": "development",
  "checks": {
    "database": {
      "status": "healthy",
      "message": "Database is responsive",
      "details": {
        "task_count": 15,
        "connection_status": "connected",
        "response_time_ms": 2.1
      }
    },
    "redis": {
      "status": "healthy", 
      "message": "Redis is responsive",
      "details": {
        "memory_usage": "2.5MB",
        "connection_status": "connected",
        "response_time_ms": 0.8
      }
    },
    "cache": {
      "status": "healthy",
      "message": "Cache is operational",
      "details": {
        "hit_ratio": 85.2,
        "total_keys": 1247
      }
    }
  }
}
```

### Task Management

#### GET /task/list
Retrieve tasks with optional pagination, searching, and advanced filtering.

**Headers**: Authentication required

**Query Parameters**:

**Pagination**:
- `page`: Page number (optional, default: 1)
- `limit`: Items per page (optional, default: 20, max: 100)

**Search**:
- `search`: Search term to match against task title and description (optional)

**Status & Priority Filters**:
- `status`: Filter by status (optional: 'pending', 'completed')
- `priority`: Filter by priority (optional: 'low', 'medium', 'high', 'urgent')
- `done`: Filter by completion status (optional: '1' for completed, '0' for pending)

**Date Range Filters**:
- `due_date_from`: Filter tasks due from this date (optional, format: YYYY-MM-DD)
- `due_date_to`: Filter tasks due until this date (optional, format: YYYY-MM-DD)
- `created_from`: Filter tasks created from this date (optional, format: YYYY-MM-DD)
- `created_to`: Filter tasks created until this date (optional, format: YYYY-MM-DD)

**Urgency Filters**:
- `urgency`: Filter by urgency status (optional: 'overdue', 'due_soon', 'due_this_week', 'normal')
- `overdue_only`: Show only overdue tasks (optional: '1' to enable)

**Sorting**:
- `sort_by`: Sort field (optional: 'title', 'due_date', 'priority', 'status', 'created_at', 'urgency')
- `sort_direction`: Sort direction (optional: 'asc', 'desc', default: 'asc')

**Examples**:
```http
# Basic usage
GET /task/list                              # All tasks (first 20)
GET /task/list?page=2&limit=10              # Paginated

# Search
GET /task/list?search=meeting               # Search for "meeting" in title/description
GET /task/list?search=project%20review      # Search for "project review"

# Basic filtering
GET /task/list?status=pending               # Filter by status
GET /task/list?priority=high                # Filter by priority
GET /task/list?done=0                       # Show only pending tasks

# Date range filtering
GET /task/list?due_date_from=2025-01-01     # Tasks due from Jan 1st
GET /task/list?due_date_from=2025-01-01&due_date_to=2025-12-31  # Tasks due this year
GET /task/list?created_from=2025-06-01      # Tasks created from June

# Urgency filtering
GET /task/list?urgency=overdue              # Only overdue tasks
GET /task/list?overdue_only=1               # Alternative overdue filter
GET /task/list?urgency=due_soon             # Tasks due soon

# Sorting
GET /task/list?sort_by=due_date&sort_direction=asc    # Sort by due date ascending
GET /task/list?sort_by=priority&sort_direction=desc   # Sort by priority descending

# Complex queries (combine multiple parameters)
GET /task/list?search=project&status=pending&priority=high&sort_by=due_date
GET /task/list?due_date_from=2025-01-01&urgency=overdue&sort_by=priority&sort_direction=desc
GET /task/list?search=meeting&created_from=2025-06-01&page=2&limit=5
```

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": 1,
      "title": "Complete project",
      "description": "Finish the task management API",
      "due_date": "2025-12-31 23:59:59",
      "priority": "high",
      "status": "pending",
      "done": false,
      "created_at": "2025-06-26 10:00:00",
      "updated_at": "2025-06-26 10:00:00",
      "days_until_due": 185
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_items": 150,
    "total_pages": 8,
    "has_previous": false,
    "has_next": true,
    "previous_page": null,
    "next_page": 2
  }
}
```

#### POST /task/create
Create a new task.

**Headers**: 
- Authentication required
- `Content-Type: application/json`

**Request Body**:
```json
{
  "title": "Task title",
  "description": "Optional task description",
  "due_date": "2025-12-31 23:59:59",
  "priority": "medium"
}
```

**Validation Rules**:
- `title`: Required, string, max 255 characters
- `description`: Optional, string, max 2000 characters  
- `due_date`: Required, valid datetime (YYYY-MM-DD HH:MM:SS), must be in future
- `priority`: Optional, enum ('low', 'medium', 'high', 'urgent'), default: 'medium'

**Response (201 Created)**:
```json
{
  "success": true,
  "message": "Task created successfully",
  "task": {
    "id": 1,
    "title": "Task title",
    "description": "Optional task description", 
    "due_date": "2025-12-31 23:59:59",
    "priority": "medium",
    "status": "pending",
    "done": false,
    "created_at": "2025-06-29 10:00:00",
    "updated_at": "2025-06-29 10:00:00"
  }
}
```

#### GET /task/{id}
Retrieve a specific task by ID.

**Headers**: Authentication required

**Parameters**:
- `id` (path): Task ID (integer)

**Response (200 OK)**:
```json
{
  "task": {
    "id": 1,
    "title": "Task title",
    "description": "Task description",
    "due_date": "2025-12-31 23:59:59", 
    "priority": "medium",
    "status": "pending",
    "done": false,
    "created_at": "2025-06-26 10:00:00",
    "updated_at": "2025-06-26 10:00:00",
    "days_until_due": 185,
    "is_overdue": false
  }
}
```

#### PUT /task/{id}
Update an existing task.

**Headers**:
- Authentication required  
- `Content-Type: application/json`

**Parameters**:
- `id` (path): Task ID (integer)

**Request Body** (all fields optional):
```json
{
  "title": "Updated title",
  "description": "Updated description",
  "due_date": "2025-12-31 23:59:59",
  "priority": "high"
}
```

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Task updated successfully",
  "task": {
    "id": 1,
    "title": "Updated title",
    "description": "Updated description",
    "due_date": "2025-12-31 23:59:59",
    "priority": "high",
    "status": "pending",
    "done": false,
    "created_at": "2025-06-26 10:00:00",
    "updated_at": "2025-06-29 15:30:00"
  }
}
```

#### DELETE /task/{id}
Delete a task.

**Headers**: Authentication required

**Parameters**:
- `id` (path): Task ID (integer)

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Task deleted successfully"
}
```

#### POST /task/{id}/done
Mark a task as completed.

**Headers**: Authentication required

**Parameters**:
- `id` (path): Task ID (integer)

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Task marked as done",
  "task": {
    "id": 1,
    "title": "Task title",
    "status": "completed",
    "done": true,
    "updated_at": "2025-06-29 15:45:00"
  }
}
```

#### POST /task/{id}/undone
Mark a completed task as not done.

**Headers**: Authentication required

**Parameters**:
- `id` (path): Task ID (integer)

**Response (200 OK)**:
```json
{
  "success": true,
  "message": "Task marked as not done",
  "task": {
    "id": 1,
    "title": "Task title",
    "status": "pending",
    "done": false,
    "updated_at": "2025-06-29 15:50:00"
  }
}
```

#### GET /task/overdue
Get all overdue tasks.

**Headers**: Authentication required

**Query Parameters**:
- `page`: Page number (optional)
- `limit`: Items per page (optional)

**Response (200 OK)**:
```json
{
  "data": [
    {
      "id": 1,
      "title": "Overdue task",
      "description": "This task is past due",
      "due_date": "2025-06-01 23:59:59",
      "priority": "high",
      "status": "overdue",
      "done": false,
      "days_overdue": 28,
      "created_at": "2025-05-15 10:00:00",
      "updated_at": "2025-05-15 10:00:00"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_items": 5,
    "total_pages": 1,
    "has_previous": false,
    "has_next": false
  }
}
```

#### GET /task/statistics
Get task statistics and metrics.

**Headers**: Authentication required

**Response (200 OK)**:
```json
{
  "statistics": {
    "total_tasks": 50,
    "completed_tasks": 35,
    "pending_tasks": 12,
    "overdue_tasks": 3,
    "cancelled_tasks": 0,
    "completion_rate": 70.0,
    "average_days_to_completion": 3.2,
    "tasks_by_priority": {
      "low": 10,
      "medium": 25,
      "high": 12,
      "urgent": 3
    },
    "tasks_by_status": {
      "pending": 12,
      "completed": 35,
      "overdue": 3,
      "cancelled": 0
    },
    "recent_activity": {
      "tasks_created_today": 2,
      "tasks_completed_today": 5,
      "tasks_due_this_week": 8
    }
  }
}
```

## Error Codes

| Code | Status | Description | Example |
|------|---------|-------------|---------|
| 200 | OK | Request successful | Task retrieved successfully |
| 201 | Created | Resource created | Task created successfully |
| 400 | Bad Request | Invalid request data | Invalid JSON format |
| 401 | Unauthorized | Missing or invalid authentication | Invalid JWT token |
| 403 | Forbidden | Access denied | Cannot access another user's task |
| 404 | Not Found | Resource not found | Task with ID 123 not found |
| 422 | Unprocessable Entity | Validation failed | Title is required |
| 429 | Too Many Requests | Rate limit exceeded | Rate limit of 100 requests/hour exceeded |
| 500 | Internal Server Error | Server error | Database connection failed |

### Error Response Examples

**Validation Error (422)**:
```json
{
  "error": "ValidationError",
  "message": "Validation failed",
  "status_code": 422,
  "details": {
    "title": ["Title is required"],
    "due_date": ["Due date must be in the future"]
  }
}
```

**Authentication Error (401)**:
```json
{
  "error": "AuthenticationError",
  "message": "Invalid or expired token",
  "status_code": 401
}
```

**Not Found Error (404)**:
```json
{
  "error": "NotFoundError",
  "message": "Task with ID 123 not found",
  "status_code": 404
}
```

## Security

### Input Validation
- XSS protection with HTML entity encoding
- SQL injection prevention with parameterized queries
- Input length limits enforced
- Malicious pattern detection
- CSRF protection for state-changing operations

### Rate Limiting
- Per-user tracking with JWT user identification
- Configurable limits and windows
- Headers provided for client awareness
- Automatic reset timers
- Graduated responses (warnings before blocking)

### Security Headers
```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

### Password Security
- Minimum 8 characters with complexity requirements
- Bcrypt hashing with cost factor 12
- Password reset tokens expire in 1 hour
- Account lockout after 5 failed attempts

## Performance

### Caching
- **JWT Validation**: 5-minute cache for valid tokens
- **Task Lists**: 10-minute cache with smart invalidation
- **User Statistics**: 1-hour cache with background refresh
- **Database Views**: Optimized views for common queries

### Database Optimizations
- Composite indexes on frequently queried columns
- Database triggers for automatic status management
- Optimized views for task listings
- Connection pooling and query optimization

### Response Times (Average)
- Authentication: <50ms
- Task operations: <100ms
- List operations: <150ms
- Statistics: <200ms

## Examples

### cURL Examples

**Complete Authentication Flow**:
```bash
# Register a new user
curl -X POST http://localhost:8080/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "securepass123"
  }'

# Login to get JWT tokens
LOGIN_RESPONSE=$(curl -s -X POST http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "securepass123"
  }')

# Extract token
TOKEN=$(echo $LOGIN_RESPONSE | jq -r '.access_token')
```

**Task Operations**:
```bash
# Create a task
curl -X POST http://localhost:8080/task/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Write documentation",
    "description": "Complete API documentation",
    "due_date": "2025-12-31 23:59:59",
    "priority": "high"
  }'

# List tasks with pagination
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/task/list?page=1&limit=10"

# Filter tasks by status
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8080/task/list?status=pending&priority=high"

# Get specific task
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/task/1

# Mark task as done
curl -X POST -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/task/1/done

# Get task statistics
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/task/statistics

# Check system health
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/health
```

### JavaScript/Fetch Examples

```javascript
class TaskManagerAPI {
  constructor(baseUrl = 'http://localhost:8080') {
    this.baseUrl = baseUrl;
    this.token = localStorage.getItem('access_token');
  }

  async login(email, password) {
    const response = await fetch(`${this.baseUrl}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password })
    });
    
    const data = await response.json();
    if (data.access_token) {
      this.token = data.access_token;
      localStorage.setItem('access_token', this.token);
    }
    return data;
  }

  async getTasks(page = 1, limit = 20, filters = {}) {
    const params = new URLSearchParams({
      page: page.toString(),
      limit: limit.toString(),
      ...filters
    });

    const response = await fetch(`${this.baseUrl}/task/list?${params}`, {
      headers: { 'Authorization': `Bearer ${this.token}` }
    });
    
    return response.json();
  }

  async createTask(taskData) {
    const response = await fetch(`${this.baseUrl}/task/create`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(taskData)
    });
    
    return response.json();
  }

  async markTaskDone(taskId) {
    const response = await fetch(`${this.baseUrl}/task/${taskId}/done`, {
      method: 'POST',
      headers: { 'Authorization': `Bearer ${this.token}` }
    });
    
    return response.json();
  }

  async getStatistics() {
    const response = await fetch(`${this.baseUrl}/task/statistics`, {
      headers: { 'Authorization': `Bearer ${this.token}` }
    });
    
    return response.json();
  }
}

// Usage example
const api = new TaskManagerAPI();

// Login and use API
await api.login('john@example.com', 'securepass123');

// Get first page of pending high-priority tasks
const tasks = await api.getTasks(1, 10, { 
  status: 'pending', 
  priority: 'high' 
});

// Create a new task
const newTask = await api.createTask({
  title: 'New Task',
  description: 'Task description',
  due_date: '2025-12-31 23:59:59',
  priority: 'medium'
});

// Get user statistics
const stats = await api.getStatistics();
console.log(`Completion rate: ${stats.statistics.completion_rate}%`);
```

### PHP Examples

```php
class TaskManagerClient 
{
    private string $baseUrl;
    private ?string $token = null;

    public function __construct(string $baseUrl = 'http://localhost:8080')
    {
        $this->baseUrl = $baseUrl;
    }

    public function login(string $email, string $password): array
    {
        $response = $this->makeRequest('/auth/login', 'POST', [
            'email' => $email,
            'password' => $password
        ]);

        if (isset($response['access_token'])) {
            $this->token = $response['access_token'];
        }

        return $response;
    }

    public function getTasks(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $params = array_merge(['page' => $page, 'limit' => $limit], $filters);
        $queryString = http_build_query($params);
        
        return $this->makeRequest("/task/list?{$queryString}", 'GET');
    }

    public function createTask(array $taskData): array
    {
        return $this->makeRequest('/task/create', 'POST', $taskData);
    }

    public function markTaskDone(int $taskId): array
    {
        return $this->makeRequest("/task/{$taskId}/done", 'POST');
    }

    public function getStatistics(): array
    {
        return $this->makeRequest('/task/statistics', 'GET');
    }

    private function makeRequest(string $endpoint, string $method, array $data = []): array
    {
        $ch = curl_init();
        
        $headers = ['Content-Type: application/json'];
        if ($this->token) {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method
        ]);

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            throw new Exception("API Error ({$httpCode}): " . ($decodedResponse['message'] ?? 'Unknown error'));
        }

        return $decodedResponse;
    }
}

// Usage example
$client = new TaskManagerClient();

try {
    // Login
    $loginResult = $client->login('john@example.com', 'securepass123');
    echo "Logged in successfully\n";

    // Get pending tasks
    $tasks = $client->getTasks(1, 10, ['status' => 'pending']);
    echo "Found {$tasks['pagination']['total_items']} pending tasks\n";

    // Create a new task
    $newTask = $client->createTask([
        'title' => 'API Integration Task',
        'description' => 'Test API integration with PHP',
        'due_date' => '2025-12-31 23:59:59',
        'priority' => 'medium'
    ]);
    echo "Created task with ID: {$newTask['task']['id']}\n";

    // Get statistics
    $stats = $client->getStatistics();
    echo "Completion rate: {$stats['statistics']['completion_rate']}%\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## Changelog

### Version 1.0.0 (Current)
- Initial API release
- JWT authentication with refresh tokens
- CRUD operations for tasks
- Pagination support
- Task filtering and statistics
- Comprehensive error handling
- Rate limiting implementation

### Planned Features (v1.1.0)
- Task categories and tags
- File attachments for tasks
- Task comments and collaboration
- Advanced search and filtering
- Bulk operations
- Webhook notifications
- API versioning support