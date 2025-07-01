# Task Manager API

A production-ready, enterprise-grade RESTful API for task management built with PHP 8.2. Features advanced caching, comprehensive security, database optimizations, and real-time monitoring.

[![PHP](https://img.shields.io/badge/PHP-8.2-blue.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-blue.svg)](https://mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7-red.svg)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Compose-blue.svg)](https://docker.com)
[![Tests](https://img.shields.io/badge/Tests-140%20Passing-brightgreen.svg)](#testing)
[![Security](https://img.shields.io/badge/Security-Enterprise%20Grade-green.svg)](#security)

## Features

### **Core Functionality**
- **Complete Task Management** - Create, update, delete, and organize tasks
- **Multi-User Architecture** - Complete user isolation with JWT authentication
- **Priority System** - Low, medium, high, and urgent priority levels
- **Status Tracking** - Pending, completed, overdue, and cancelled states
- **Advanced Filtering** - Overdue tasks, priority filtering, date ranges
- **Comprehensive Statistics** - Completion rates, performance metrics, analytics

### **Enterprise Security**
- **JWT Authentication** - Secure token-based auth with refresh tokens
- **Multi-User Isolation** - Complete user data separation
- **Rate Limiting** - Redis-based per-user limits with burst allowance
- **Password Security** - Bcrypt hashing with automatic salting
- **XSS Protection** - Advanced input sanitization and validation
- **SQL Injection Prevention** - Parameterized queries with detection
- **Security Headers** - CORS, CSP, HSTS, and frame protection
- **Audit Logging** - Security event tracking with correlation IDs

### **Performance Optimizations**
- **Multi-Layer Caching** - Redis with intelligent cache invalidation
- **Database Views** - Optimized views for common query patterns
- **Compound Indexes** - 6 strategic indexes for optimal query performance
- **Connection Pooling** - Shared database and Redis connections
- **Query Optimization** - User-scoped queries preventing table scans

### **Monitoring & Observability**
- **Health Monitoring** - Database, Redis, memory, and disk monitoring
- **Structured Logging** - JSON logs with request correlation IDs
- **Performance Metrics** - Response times and resource usage tracking
- **Real-Time Alerts** - System health and performance alerting
- **Load Testing Tools** - Built-in performance validation scripts

### **Quality Assurance**
- **140 Comprehensive Tests** - Integration, security, unit, and BDD tests
- **PHPStan Level 8** - Maximum static analysis coverage
- **PSR-12 Compliance** - Enforced code style standards
- **Automated Testing** - Fast execution with Docker test environment

## Requirements

- **Docker & Docker Compose** (recommended)
- **PHP 8.2+** (if running locally)
- **MySQL 8.0+**
- **Redis 7+**
- **Composer**

## Quick Start

### **1. Clone and Setup**
```bash
git clone <repository-url>
cd task_manager
```

### **2. Start with Docker (Recommended)**
```bash
# Start all services
docker-compose up -d

# Verify services are healthy
docker-compose ps
```

### **3. Verify Installation**
```bash
# Run complete test suite
./run-tests.sh

# Quick API functionality test
./run-tests.sh --api-quick

# Performance validation
./run-tests.sh --performance
```

Your API is now running at **http://localhost:8080**

## Authentication & Usage

### **Authentication Flow**
The API uses JWT authentication with refresh tokens:

1. **Register** a new user account
2. **Login** to receive access and refresh tokens
3. **Use access token** for protected endpoints
4. **Refresh tokens** when they expire

### **Token Configuration**
- **Access Token**: 1 hour expiration
- **Refresh Token**: 7 days expiration
- **Algorithm**: HS256 with secure secret
- **Cache**: 5-minute validation cache for performance

### **Example Usage**

#### **1. User Registration**
```bash
curl -X POST http://localhost:8080/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "securepass123"
  }'
```

#### **2. User Login**
```bash
curl -X POST http://localhost:8080/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "securepass123"
  }'
```

**Response:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "eyJ1c2VyX2lkIjoxLCJ0eXBlIjoicmVmcmVzaCIsImlhdCI6MTczNTI5OTg5MywiZXhwIjoxNzM1OTA0NjkzfQ...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

#### **3. List Tasks (with Pagination)**
```bash
TOKEN="your-access-token-here"

# Get all tasks (original format)
curl -X GET http://localhost:8080/task/list \
  -H "Authorization: Bearer $TOKEN"

# Get paginated tasks (page 1, 10 items per page)
curl -X GET "http://localhost:8080/task/list?page=1&limit=10" \
  -H "Authorization: Bearer $TOKEN"

# Get page 2 with 5 items per page
curl -X GET "http://localhost:8080/task/list?page=2&limit=5" \
  -H "Authorization: Bearer $TOKEN"
```

**Paginated Response Format:**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Task 1",
      "description": "Task description",
      "due_date": "2025-12-31 23:59:59",
      "priority": "high",
      "status": "pending"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 10,
    "total_items": 25,
    "total_pages": 3,
    "has_previous": false,
    "has_next": true,
    "previous_page": null,
    "next_page": 2
  }
}
```

#### **4. Create a Task**
```bash
TOKEN="your-access-token-here"

curl -X POST http://localhost:8080/task/create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Complete API Documentation",
    "description": "Write comprehensive API docs with examples",
    "due_date": "2025-12-31 23:59:59",
    "priority": "high"
  }'
```

## API Endpoints

### **Authentication Endpoints**
| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| `POST` | `/auth/register` | Register new user | No |
| `POST` | `/auth/login` | Login user | No |
| `POST` | `/auth/refresh` | Refresh access token | No |
| `GET` | `/auth/profile` | Get user profile | Yes |
| `GET` | `/auth/debug` | Token debug info | Yes |

### **Task Management Endpoints**
| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| `GET` | `/task/list` | List user's tasks (supports pagination) | Yes |
| `POST` | `/task/create` | Create new task | Yes |
| `PUT` | `/task/update/{id}` | Update task | Yes |
| `POST` | `/task/done` | Mark task as completed | Yes |
| `GET` | `/task/overdue` | Get overdue tasks | Yes |
| `GET` | `/task/statistics` | Get task statistics | Yes |

### **System Endpoints**
| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| `GET` | `/health` | System health check | Yes |

## Development

### **Available Commands**
```bash
# Start services
docker-compose up -d

# Start with Xdebug (development)
docker-compose -f docker-compose.dev.yml up -d --build

# View logs
docker-compose logs -f app

# Run tests
./run-tests.sh

# Stop services
docker-compose down

# Rebuild containers
docker-compose build --no-cache
```

### **Debugging with Xdebug**
```bash
# Start environment (Xdebug installed but disabled by default)
docker-compose up -d --build

# Enable Xdebug for debugging session
docker-compose exec app bash -c 'export XDEBUG_MODE=debug,develop'

# Debug specific test
docker-compose exec app \
  php -dxdebug.mode=debug -dxdebug.start_with_request=yes vendor/bin/phpunit tests/TaskUnitTest.php
```

**IDE Setup:**
- **VS Code**: Install "PHP Debug" extension, configure port 9003
- **PhpStorm**: Configure Xdebug port 9003, set path mappings
- **Path Mapping**: Local project → `/var/www/html` (container)

See `docs/XDEBUG.md` for comprehensive debugging guide.

### **Log Monitoring**
```bash
# View application logs
tail -f logs/app.log | jq '.'

# View security events
tail -f logs/security.log | jq '.'

# View error logs
tail -f logs/error.log | jq '.'
```

## Testing & Benchmarking

The project includes comprehensive testing with **140 tests** and performance benchmarking across multiple categories:

### **Test Organization**

The tests are organized into logical directories for better maintainability:

```
tests/
├── Unit/                    # Fast, isolated unit tests (50+ tests)
│   ├── AppConfigTest.php        # Configuration management testing
│   ├── CacheConfigTest.php      # Cache configuration testing
│   ├── CacheTest.php            # Cache component testing
│   ├── DIContainerTest.php      # Dependency injection testing
│   ├── RequestContextTest.php   # Request context testing
│   ├── PaginationTest.php       # Pagination service testing
│   └── TaskUnitTest.php         # Task service unit testing
└── Integration/             # Full integration tests (90+ tests) 
    ├── ApiIntegrationTest.php       # Complete API endpoint testing
    ├── HealthCheckTest.php          # System health monitoring
    ├── PaginationIntegrationTest.php # Pagination API testing
    ├── PerformanceOptimizationTest.php # Performance validation
    ├── RateLimitTest.php            # Rate limiting functionality
    ├── SecurityTest.php             # Security features testing
    └── TaskIntegrationTest.php      # Task management integration
```

### **Test Categories by Type**
- **Unit Tests** - Fast, isolated component testing with mocks
- **Integration Tests** - API endpoints, authentication, database operations
- **Security Tests** - XSS protection, SQL injection prevention, input validation
- **Performance Tests** - Cache performance, database optimization validation
- **BDD Tests** - Behavior-driven testing with Behat (separate directory)

### **Running Tests**
```bash
# Complete test suite (all tests)
./run-tests.sh

# Test suite categories
./run-tests.sh --phpunit        # PHPUnit tests only
./run-tests.sh --behat          # BDD tests only
./run-tests.sh --performance    # Performance validation

# Quick API test
./run-tests.sh --api-quick

# Organized test suites
docker-compose exec app vendor/bin/phpunit --testsuite="Unit Tests"        # Fast unit tests only
docker-compose exec app vendor/bin/phpunit --testsuite="Integration Tests" # Full integration tests
docker-compose exec app vendor/bin/phpunit --testsuite="All Tests"         # All PHPUnit tests

# Specific test files
docker-compose exec app vendor/bin/phpunit tests/Integration/SecurityTest.php
docker-compose exec app vendor/bin/phpunit tests/Unit/PaginationTest.php --testdox
```

### **Performance Benchmarking with PHPBench**

```bash
# Run all benchmarks
docker-compose exec app composer bench

# Quick benchmark (fewer iterations)
docker-compose exec app composer bench-quick

# Thorough benchmark (more iterations)
docker-compose exec app composer bench-thorough

# Specific component benchmarks
docker-compose exec app composer bench-cache    # Cache performance
docker-compose exec app composer bench-db       # Database performance
```

**Benchmark Results Example:**
```
+------+---------------+---------------------+-----+------+------------+-----------+
| iter | benchmark     | subject             | set | revs | mem_peak   | time_avg  |
+------+---------------+---------------------+-----+------+------------+-----------+
| 0    | CacheBench    | benchCacheSet       |     | 1000 | 3,312,552b | 120.862μs |
| 1    | CacheBench    | benchCacheGet       |     | 2000 | 3,312,592b | 125.152μs |
| 2    | DatabaseBench | benchSimpleSelect   |     | 100  | 3,312,088b | 204.150μs |
| 3    | DatabaseBench | benchUserTasksView  |     | 50   | 3,312,088b | 303.560μs |
+------+---------------+---------------------+-----+------+------------+-----------+
```

**Available Benchmarks:**
- **Cache Operations** - Redis SET/GET/DELETE/EXISTS performance
- **Database Queries** - SELECT, view queries, connection pooling
- **Custom Benchmarks** - Add your own in `benchmarks/` directory

## Load Testing & Performance Monitoring

The project includes sophisticated Node.js-based load testing and performance monitoring tools located in the `scripts/` directory.

### **Load Testing Scripts**

#### **Quick Load Tests**
```bash
cd scripts

# Light load test (10 users, 15 seconds)
npm run load-test-light

# Standard load test (20 users, 30 seconds)  
npm run load-test

# Heavy load test (100 users, 2 minutes)
npm run load-test-heavy
```

#### **Custom Load Test Configuration**
```bash
# Environment variables for custom configuration
CONCURRENT_USERS=50 \
TEST_DURATION=60000 \
RAMP_UP_TIME=10000 \
API_BASE_URL=http://localhost:8080 \
npm run load-test
```

#### **Load Test Features**
- **Multi-User Simulation** - Creates test users with JWT authentication
- **Realistic Usage Patterns** - Task creation, listing, updates, and statistics
- **Performance Metrics** - Response times, throughput, error rates
- **Detailed Reporting** - Success rates, 95th percentile response times
- **Error Analysis** - Categorized error breakdown (auth, rate limit, server)

### **Performance Monitoring**

#### **Real-Time Monitoring**
```bash
cd scripts

# Standard monitoring (60-second intervals)
npm run monitor

# Development monitoring (30-second intervals)
npm run monitor-dev

# Production monitoring (strict thresholds)
npm run monitor-production
```

#### **Custom Monitoring Configuration**
```bash
# Environment variables for monitoring
MONITORING_INTERVAL=30000 \
RESPONSE_TIME_THRESHOLD=200 \
ERROR_RATE_THRESHOLD=5 \
CACHE_HIT_RATIO_THRESHOLD=80 \
npm run monitor
```

#### **Monitoring Features**
- **Health Check Monitoring** - Continuous API health validation
- **Performance Metrics** - Response times, error rates, cache performance
- **Alert Thresholds** - Configurable limits for response time and error rates
- **Real-Time Dashboards** - Live performance data and trend analysis
- **JWT Authentication** - Uses real authentication flow for accurate testing

### **Sample Load Test Output**
```
Starting Task Manager API Load Test
Configuration:
   - Concurrent Users: 10
   - Test Duration: 15s
   - Target API: http://localhost:8080

Created 10 test users

Load Test Results
==================
Performance Metrics:
   Total Requests: 95
   Successful Responses: 88
   Success Rate: 92.63%
   Requests/Second: 4.81
   Average Response Time: 16.92ms
   95th Percentile Response Time: 27.15ms

Performance Assessment:
   Response Time: Excellent (< 200ms)
   Success Rate: Good (> 90%)
   Throughput: Moderate (5 req/s)
```

### **Sample Monitoring Output**
```
Starting Task Manager API Performance Monitor
Monitoring interval: 30s

Performance Metrics (Last 5 minutes):
   Average Response Time: 23.4ms
   Error Rate: 0.5%
   Cache Hit Ratio: 94.2%
   Memory Usage: 45.2%

All systems healthy
```

## Performance & Architecture

### **Database Optimizations**
- **6 Compound Indexes** - Strategic indexing covering all query patterns
- **Database Views** - `user_active_tasks` and `user_task_statistics` for optimal performance
- **Triggers** - Automatic status management based on due dates
- **Connection Pooling** - Shared connections with health monitoring

### **Caching Strategy**
- **Redis Multi-Layer Cache** - Task data, user statistics, and query results
- **User-Scoped Caching** - Complete isolation between users
- **Intelligent Invalidation** - Selective cache updates on data changes
- **Performance Metrics** - Cache hit ratios and response time tracking

### **Security Architecture**
- **JWT Stateless Authentication** - No server-side session storage
- **Rate Limiting** - Per-user limits with Redis backend
- **Input Sanitization** - Multiple layers of validation and filtering
- **Data Isolation** - Complete user separation at database level

### **System Architecture**
```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Client Apps   │    │  Load Balancer  │    │   Application   │
│   (Web/Mobile)  │───▶│    (nginx)      │───▶│   Container     │
│                 │    │                 │    │   (PHP 8.2)     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                                                        │
                              ┌─────────────────────────┼─────────────────────────┐
                              ▼                         ▼                         ▼
                     ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
                     │    Database     │    │      Cache      │    │    Monitoring   │
                     │   (MySQL 8.0)   │    │    (Redis 7)    │    │   (Health API)  │
                     │                 │    │                 │    │                 │
                     └─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Security

### **Authentication & Authorization**
- **JWT Implementation** - Secure token-based authentication with configurable expiration
- **Dual Token System** - Access tokens with refresh token rotation
- **User Isolation** - Complete data separation with user-scoped queries
- **Password Security** - Bcrypt hashing with automatic salt generation

### **Input Protection**
- **XSS Prevention** - HTML entity encoding and content sanitization
- **SQL Injection Protection** - Parameterized queries with injection detection
- **Input Validation** - Comprehensive validation rules and length limits
- **Rate Limiting** - Configurable per-user request limits

### **Security Headers**
```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Content-Security-Policy: default-src 'self'
Strict-Transport-Security: max-age=31536000
```

## Monitoring

### **Health Checks**
The `/health` endpoint provides comprehensive monitoring:
- **Database Health** - Connection status and query performance
- **Redis Health** - Cache connectivity and memory usage
- **System Health** - Memory usage, disk space, load average
- **Environment Validation** - Configuration and dependency checks

### **Performance Metrics**
- **Response Time Tracking** - Request/response timing with percentiles
- **Memory Usage Monitoring** - PHP memory consumption and limits
- **Cache Performance** - Hit/miss ratios and invalidation rates
- **Database Performance** - Query execution times and connection pooling

### **Logging System**
- **Structured JSON Logs** - Consistent format with correlation IDs
- **Security Event Logging** - Authentication and authorization events
- **Error Tracking** - Exception handling with stack traces
- **Performance Logging** - Request timing and resource usage

## Configuration

### **Environment Variables**
| Variable | Default | Description |
|----------|---------|-------------|
| `JWT_SECRET` | - | Secret key for JWT token signing |
| `JWT_EXPIRATION` | `3600` | Access token expiration (seconds) |
| `DB_HOST` | `db` | Database hostname |
| `DB_NAME` | `task_manager` | Database name |
| `REDIS_HOST` | `redis` | Redis hostname |
| `REDIS_PORT` | `6379` | Redis port |
| `CACHE_TYPE` | `redis` | Cache implementation |
| `RATE_LIMIT_REQUESTS` | `100` | Rate limit max requests |
| `RATE_LIMIT_WINDOW` | `3600` | Rate limit window (seconds) |
| `LOG_LEVEL` | `info` | Logging level |

### **Database Schema**
The application uses an optimized MySQL schema with:
- **Users Table** - Authentication and profile data
- **Tasks Table** - Task data with user association
- **Performance Views** - Optimized views for common queries
- **Automatic Triggers** - Status management and data consistency

## Docker Services

### **Service Architecture**
- **app** - PHP 8.2 + Apache with Redis extension
- **db** - MySQL 8.0 with optimized configuration
- **db_test** - Separate MySQL instance for testing isolation
- **redis** - Redis 7 with persistence and memory optimization

### **Volume Management**
- **Application Logs** - `./logs:/tmp/task-manager-logs`
- **Database Persistence** - `mysql_data` named volume
- **Redis Persistence** - `redis_data` named volume
- **Test Database** - `mysql_test_data` for test isolation

## Production Deployment

### **Production Checklist**
- [ ] Configure secure JWT secret keys
- [ ] Set up proper rate limiting thresholds
- [ ] Enable log rotation and monitoring
- [ ] Configure HTTPS/TLS termination
- [ ] Set up reverse proxy (nginx/Apache)
- [ ] Configure monitoring and alerting
- [ ] Implement database backup strategy
- [ ] Review and validate security headers

### **Scaling Recommendations**
- **Horizontal Scaling** - Multiple app containers behind load balancer
- **Database Scaling** - Read replicas for query distribution
- **Cache Scaling** - Redis cluster for high availability
- **Monitoring** - Centralized logging and metrics collection

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run tests (`./run-tests.sh`)
4. Ensure PHPStan Level 8 compliance
5. Follow PSR-12 coding standards
6. Commit changes (`git commit -m 'Add amazing feature'`)
7. Push to branch (`git push origin feature/amazing-feature`)
8. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Additional Resources

- **API Documentation** - See `docs/API.md` for detailed endpoint documentation
- **Xdebug Debugging** - See `docs/XDEBUG.md` for comprehensive debugging guide
- **Load Testing Scripts** - See `scripts/` directory for Node.js load testing tools
- **Performance Monitoring** - Real-time monitoring scripts with configurable thresholds
- **Testing Guide** - Comprehensive testing instructions with 85 automated tests

---

**Enterprise-Ready Task Manager API - Built for Production Scale**

For support, documentation, or feature requests, please open an issue in the repository.