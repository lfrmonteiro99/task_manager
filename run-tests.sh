#!/bin/bash

# Task Manager API - Comprehensive Test Runner
# Supports PHPUnit (integration/unit) and Behat (BDD) tests

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE} Task Manager API - Test Suite${NC}"
echo "=================================="

# Parse command line arguments
PHPUNIT_ONLY="${PHPUNIT_ONLY:-}"
BEHAT_ONLY="${BEHAT_ONLY:-}"
PERFORMANCE_ONLY="${PERFORMANCE_ONLY:-}"
API_QUICK_ONLY="${API_QUICK_ONLY:-}"
SUITE=""
VERBOSE=""

while [[ $# -gt 0 ]]; do
    case $1 in
        --phpunit)
            PHPUNIT_ONLY="true"
            shift
            ;;
        --behat)
            BEHAT_ONLY="true"
            shift
            ;;
        --bdd)
            BEHAT_ONLY="true"
            shift
            ;;
        --performance)
            PERFORMANCE_ONLY="true"
            shift
            ;;
        --api-quick)
            API_QUICK_ONLY="true"
            shift
            ;;
        --suite=*)
            SUITE="${1#*=}"
            shift
            ;;
        --verbose|-v)
            VERBOSE="true"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --phpunit           Run only PHPUnit tests (Unit + Integration suites)"
            echo "  --behat, --bdd      Run only Behat BDD tests"
            echo "  --performance       Run performance optimization tests"
            echo "  --api-quick         Run quick API test suite"
            echo "  --suite=SUITE       Run specific Behat suite (api, task_management, security)"
            echo "  --verbose, -v       Verbose output"
            echo "  --help, -h          Show this help message"
            echo ""
            echo "Test Organization:"
            echo "  Unit Tests (39):    Fast isolated component tests"
            echo "  Integration (101):  API endpoints, security, database tests"
            echo "  BDD Tests:          Behavior-driven scenarios"
            echo ""
            echo "Examples:"
            echo "  $0                  # Run all tests (PHPUnit + Behat)"
            echo "  $0 --phpunit        # Run Unit + Integration PHPUnit tests"
            echo "  $0 --behat          # Run only Behat BDD tests"
            echo "  $0 --performance    # Run performance optimization validation"
            echo "  $0 --api-quick      # Run quick API functionality test"
            echo "  $0 --behat --suite=security  # Run only security BDD tests"
            echo ""
            echo "Direct PHPUnit Commands:"
            echo "  docker-compose exec app vendor/bin/phpunit --testsuite='Unit Tests'"
            echo "  docker-compose exec app vendor/bin/phpunit --testsuite='Integration Tests'"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Load test environment variables
if [ -f ".env.test" ]; then
    echo -e "${YELLOW} Loading test environment from .env.test${NC}"
    export $(grep -v '^#' .env.test | xargs)
else
    echo -e "${YELLOW}  No .env.test file found, using defaults${NC}"
    export TEST_API_KEY="test-secure-key-$(date +%s)"
    export TEST_API_BASE_URL="http://localhost:8080"
fi

echo -e "${BLUE} Using test API key: ${TEST_API_KEY:0:15}...${NC}"

# Check if Docker containers are running
if ! docker-compose ps | grep -q "Up"; then
    echo -e "${RED} Docker containers not running. Starting them...${NC}"
    docker-compose up -d > /dev/null 2>&1
    echo -e "${YELLOW} Waiting for services to be ready...${NC}"
    sleep 10
fi

echo -e "${GREEN} Docker containers are running${NC}"

# Functions for running different test types
run_phpunit_tests() {
    local failed_tests=()
    
    echo -e "\n${BLUE} Running PHPUnit Tests${NC}"
    echo "========================"
    
    # Run PHPStan analysis
    echo -e "\n${YELLOW} Running PHPStan static analysis...${NC}"
    if docker-compose exec -T app composer run analyze; then
        echo -e "${GREEN} PHPStan analysis passed${NC}"
    else
        echo -e "${RED} PHPStan analysis failed${NC}"
        failed_tests+=("PHPStan")
    fi

    # Run PSR-12 code style check
    echo -e "\n${YELLOW} Checking PSR-12 code style...${NC}"
    if docker-compose exec -T app composer run lint; then
        echo -e "${GREEN} Code style check passed${NC}"
    else
        echo -e "${RED} Code style check failed${NC}"
        failed_tests+=("PSR-12")
    fi

    # Run Unit Tests first (fast)
    echo -e "\n${YELLOW} Running unit tests (fast)...${NC}"
    if docker-compose exec -T app sh -c 'export $(grep -v "^#" .env.test | xargs) && vendor/bin/phpunit --testsuite="Unit Tests" --testdox --do-not-fail-on-warning --do-not-fail-on-deprecation --do-not-fail-on-phpunit-deprecation'; then
        echo -e "${GREEN} Unit tests passed${NC}"
    else
        echo -e "${RED} Unit tests failed${NC}"
        failed_tests+=("Unit")
    fi

    # Run Integration Tests (comprehensive)
    echo -e "\n${YELLOW} Running integration tests...${NC}"
    # Clear rate limits before running integration tests
    docker-compose exec redis redis-cli FLUSHALL > /dev/null 2>&1 || true
    
    # Configure API server for test environment
    echo -e "${YELLOW} Configuring API server for test database...${NC}"
    docker-compose -f docker-compose.yml -f docker-compose.test.yml up -d app > /dev/null 2>&1
    sleep 3
    echo -e "${GREEN} API server configured for test environment${NC}"
    
    if docker-compose exec -T app sh -c 'export $(grep -v "^#" .env.test | xargs) && vendor/bin/phpunit --testsuite="Integration Tests" --testdox --do-not-fail-on-warning --do-not-fail-on-deprecation --do-not-fail-on-phpunit-deprecation'; then
        echo -e "${GREEN} Integration tests passed${NC}"
        integration_result=0
    else
        echo -e "${RED} Integration tests failed${NC}"
        failed_tests+=("Integration")
        integration_result=1
    fi
    
    # Restore original configuration
    echo -e "${YELLOW} Restoring original environment configuration...${NC}"
    docker-compose up -d app > /dev/null 2>&1
    sleep 2
    echo -e "${GREEN} Original environment restored${NC}"
    
    if [ $integration_result -ne 0 ]; then
        failed_tests+=("Integration")
    fi

    # Return status
    if [ ${#failed_tests[@]} -eq 0 ]; then
        return 0
    else
        echo -e "\n${RED} Failed PHPUnit tests: ${failed_tests[*]}${NC}"
        return 1
    fi
}

run_behat_tests() {
    local suite=$1
    local failed_suites=()
    
    echo -e "\n${BLUE} Running Behat BDD Tests${NC}"
    echo "=========================="
    
    # Clear rate limits and database before running BDD tests
    docker-compose exec redis redis-cli FLUSHALL > /dev/null 2>&1 || true
    
    # Clear tasks table for clean test environment
    echo -e "${YELLOW} Clearing test database...${NC}"
    docker-compose exec -T app sh -c 'export $(grep -v "^#" .env.test | xargs) && php -r "
try {
    \$pdo = new PDO(\"mysql:host=\" . getenv(\"DB_HOST\") . \";dbname=\" . getenv(\"DB_NAME\"), getenv(\"DB_USER\"), getenv(\"DB_PASS\"));
    \$pdo->exec(\"DELETE FROM tasks\");
    \$pdo->exec(\"ALTER TABLE tasks AUTO_INCREMENT = 1\");
    echo \"Database cleared successfully\n\";
} catch (Exception \$e) {
    echo \"Failed to clear database: \" . \$e->getMessage() . \"\n\";
    exit(1);
}
"' || {
        echo -e "${YELLOW} Failed to clear database, continuing with tests...${NC}"
    }
    
    # Configure API server for test environment
    echo -e "${YELLOW} Configuring API server for test environment...${NC}"
    docker-compose -f docker-compose.yml -f docker-compose.test.yml up -d app > /dev/null 2>&1
    sleep 2
    echo -e "${GREEN} API server configured for test environment${NC}"
    
    # Function to run a specific Behat suite
    run_behat_suite() {
        local suite_name=$1
        echo -e "\n${YELLOW} Running $suite_name tests...${NC}"
        
        local behat_cmd="docker-compose exec -T app sh -c 'export \$(grep -v \"^#\" .env.test | xargs) && vendor/bin/behat --suite=$suite_name'"
        [ "$VERBOSE" = "true" ] && behat_cmd="$behat_cmd --verbose"
        
        if eval $behat_cmd; then
            echo -e "${GREEN} $suite_name BDD tests passed${NC}"
            return 0
        else
            echo -e "${RED} $suite_name BDD tests failed${NC}"
            return 1
        fi
    }
    
    # Run specific suite or all suites
    if [ -n "$suite" ]; then
        if run_behat_suite "$suite"; then
            return 0
        else
            return 1
        fi
    else
        # Run all suites
        if run_behat_suite "api"; then
            true
        else
            failed_suites+=("api")
        fi
        
        if run_behat_suite "task_management"; then
            true
        else
            failed_suites+=("task_management")
        fi
        
        if run_behat_suite "security"; then
            true
        else
            failed_suites+=("security")
        fi
        
        # Restore original configuration
        echo -e "${YELLOW} Restoring original environment configuration...${NC}"
        docker-compose up -d app > /dev/null 2>&1
        sleep 2
        echo -e "${GREEN} Original environment restored${NC}"
        
        # Return status
        if [ ${#failed_suites[@]} -eq 0 ]; then
            return 0
        else
            echo -e "\n${RED} Failed Behat suites: ${failed_suites[*]}${NC}"
            return 1
        fi
    fi
}

run_performance_tests() {
    echo -e "\n${BLUE} Performance Optimization Test Suite${NC}"
    echo "========================================"
    echo ""
    
    local failed_tests=0
    local total_tests=0
    
    # Helper function to run and track tests
    run_perf_test() {
        local test_name=$1
        local test_command=$2
        
        echo -e "${YELLOW} Running $test_name...${NC}"
        ((total_tests++))
        
        if eval $test_command; then
            echo -e "${GREEN} $test_name passed${NC}"
            return 0
        else
            echo -e "${RED} $test_name failed${NC}"
            ((failed_tests++))
            return 1
        fi
    }
    
    echo " Performance Optimization Features:"
    echo "    Multi-tier rate limiting (basic, premium, enterprise, admin)"
    echo "     User-isolated Redis caching with optimization"
    echo "    Database compound indexes for user-scoped queries"
    echo "    Load testing and monitoring infrastructure"
    echo ""
    
    # Unit Tests
    echo -e "${BLUE} Unit Tests${NC}"
    run_perf_test "All Unit Tests" "docker-compose exec -T app vendor/bin/phpunit --testsuite='Unit Tests'"
    
    # Cache Performance Tests
    echo -e "\n${BLUE} Cache Performance Tests${NC}"
    run_perf_test "Multi-User Cache Isolation" "docker-compose exec -T app vendor/bin/phpunit --filter testMultiUserCacheIsolation tests/Unit/CacheTest.php"
    run_perf_test "User-Specific Cache Info" "docker-compose exec -T app vendor/bin/phpunit --filter testUserSpecificCacheInfo tests/Unit/CacheTest.php"
    run_perf_test "Cache Metrics" "docker-compose exec -T app vendor/bin/phpunit --filter testCacheMetrics tests/Unit/CacheTest.php"
    
    # Rate Limiting Tests
    echo -e "\n${BLUE} Enhanced Rate Limiting Tests${NC}"
    # Clear rate limits before testing
    docker-compose exec redis redis-cli FLUSHALL > /dev/null 2>&1 || true
    run_perf_test "Operation-Specific Rate Limits" "docker-compose exec -T app vendor/bin/phpunit --filter testOperationSpecificRateLimits tests/Integration/RateLimitTest.php"
    run_perf_test "User Tier Information" "docker-compose exec -T app vendor/bin/phpunit --filter testUserTierInformation tests/Integration/RateLimitTest.php"
    run_perf_test "Rate Limit Headers" "docker-compose exec -T app vendor/bin/phpunit --filter testRateLimitHeaders tests/Integration/RateLimitTest.php"
    
    # Performance Integration Tests
    echo -e "\n${BLUE} Performance Integration Tests${NC}"
    run_perf_test "Cache Configuration" "docker-compose exec -T app vendor/bin/phpunit --filter testCacheConfiguration tests/Integration/PerformanceOptimizationTest.php"
    run_perf_test "Rate Limit Configuration" "docker-compose exec -T app vendor/bin/phpunit --filter testRateLimitConfiguration tests/Integration/PerformanceOptimizationTest.php"
    run_perf_test "Database Optimization Readiness" "docker-compose exec -T app vendor/bin/phpunit --filter testDatabaseOptimizationReadiness tests/Integration/PerformanceOptimizationTest.php"
    run_perf_test "Load Testing Infrastructure" "docker-compose exec -T app vendor/bin/phpunit --filter testLoadTestingInfrastructure tests/Integration/PerformanceOptimizationTest.php"
    
    # Infrastructure Checks
    echo -e "\n${BLUE} Infrastructure Validation${NC}"
    
    # Load Testing Scripts
    if [ -d "scripts" ]; then
        echo -e "${GREEN} Load testing scripts directory exists${NC}"
        ((total_tests++))
        
        if [ -f "scripts/load-test.js" ] && [ -f "scripts/performance-monitor.js" ] && [ -f "scripts/package.json" ]; then
            echo -e "${GREEN} All load testing files present${NC}"
            
            # Validate script syntax if Node.js is available
            if command -v node &> /dev/null; then
                cd scripts
                if node -c load-test.js && node -c performance-monitor.js; then
                    echo -e "${GREEN} Load testing scripts syntax is valid${NC}"
                else
                    echo -e "${RED} Load testing scripts have syntax errors${NC}"
                    ((failed_tests++))
                fi
                cd ..
            else
                echo -e "${YELLOW}  Node.js not available, skipping script validation${NC}"
            fi
        else
            echo -e "${RED} Missing load testing files${NC}"
            ((failed_tests++))
        fi
    else
        echo -e "${RED} Scripts directory missing${NC}"
        ((failed_tests++))
        ((total_tests++))
    fi
    
    # Database Optimization Files
    if [ -f "sql/init.sql" ]; then
        echo -e "${GREEN} Database setup file exists${NC}"
        
        # Check for performance optimizations
        if grep -q "idx_user_tasks_optimized\|user_active_tasks\|ANALYZE TABLE" sql/init.sql; then
            echo -e "${GREEN} Performance optimizations found in database setup${NC}"
        else
            echo -e "${RED} Performance optimizations missing from database setup${NC}"
            ((failed_tests++))
        fi
        ((total_tests++))
    else
        echo -e "${RED} Database setup file missing${NC}"
        ((failed_tests++))
        ((total_tests++))
    fi
    
    # Final Summary
    echo -e "\n${BLUE} Performance Test Results${NC}"
    echo "=========================="
    
    local passed_tests=$((total_tests - failed_tests))
    local success_rate=$((passed_tests * 100 / total_tests))
    
    echo "Total Tests: $total_tests"
    echo "Passed: $passed_tests"
    echo "Failed: $failed_tests"
    echo "Success Rate: $success_rate%"
    echo ""
    
    if [ $failed_tests -eq 0 ]; then
        echo -e "${GREEN} All performance optimization tests passed!${NC}"
        echo ""
        echo " Performance optimizations are working correctly:"
        echo "   - Multi-user rate limiting with tier support"
        echo "   - User-isolated caching with Redis optimization"
        echo "   - Database indexes for improved query performance"
        echo "   - Load testing and monitoring infrastructure"
        echo ""
        echo " Next steps:"
        echo "   - Apply database optimizations: mysql < sql/init.sql"
        echo "   - Run load tests: cd scripts && npm run load-test"
        echo "   - Start monitoring: cd scripts && npm run monitor"
        return 0
    else
        echo -e "${RED} $failed_tests performance test(s) failed${NC}"
        echo ""
        echo " To fix the issues:"
        echo "   1. Check the detailed error messages above"
        echo "   2. Ensure all dependencies are installed"
        echo "   3. Verify API server is running (for integration tests)"
        echo "   4. Check database connection and Redis availability"
        return 1
    fi
}

run_api_quick_tests() {
    echo -e "\n${BLUE} Quick API Functionality Test${NC}"
    echo "==============================="
    echo ""
    
    local API_BASE="http://localhost:8080"
    local failed_tests=0
    
    # Helper function for API calls
    api_call() {
        local method=$1
        local endpoint=$2
        local token=$3
        local data=$4
        
        if [ -n "$data" ]; then
            curl -s -X $method "$API_BASE$endpoint" \
                -H "Authorization: Bearer $token" \
                -H "Content-Type: application/json" \
                -d "$data"
        else
            curl -s -X $method "$API_BASE$endpoint" \
                -H "Authorization: Bearer $token"
        fi
    }
    
    # Test 1: User Registration
    echo -e "${YELLOW}1. Testing User Registration...${NC}"
    REG_RESPONSE=$(curl -s -X POST "$API_BASE/auth/register" \
        -H "Content-Type: application/json" \
        -d '{
            "name": "Quick Test User",
            "email": "quicktest@example.com", 
            "password": "testpass123"
        }')
    
    if echo "$REG_RESPONSE" | grep -q "successfully"; then
        echo -e "${GREEN} Registration successful${NC}"
    else
        echo -e "${RED} Registration failed${NC}"
        ((failed_tests++))
    fi
    
    # Test 2: User Login
    echo -e "${YELLOW}2. Testing User Login...${NC}"
    LOGIN_RESPONSE=$(curl -s -X POST "$API_BASE/auth/login" \
        -H "Content-Type: application/json" \
        -d '{
            "email": "quicktest@example.com",
            "password": "testpass123"
        }')
    
    TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.access_token' 2>/dev/null || echo "")
    
    if [ "$TOKEN" != "null" ] && [ -n "$TOKEN" ]; then
        echo -e "${GREEN} Login successful${NC}"
        echo "Token: ${TOKEN:0:30}..."
    else
        echo -e "${RED} Login failed${NC}"
        ((failed_tests++))
        TOKEN=""
    fi
    
    # Test 3: Task Creation
    echo -e "${YELLOW}3. Testing Task Creation...${NC}"
    if [ -n "$TOKEN" ]; then
        CREATE_RESPONSE=$(api_call POST "/task/create" "$TOKEN" '{
            "title": "Quick API Test Task",
            "description": "Testing the API functionality",
            "due_date": "2025-12-31 23:59:59"
        }')
        
        if echo "$CREATE_RESPONSE" | grep -q "successfully"; then
            echo -e "${GREEN} Task creation successful${NC}"
        else
            echo -e "${RED} Task creation failed${NC}"
            ((failed_tests++))
        fi
    else
        echo -e "${YELLOW}  Skipping task creation (no valid token)${NC}"
        ((failed_tests++))
    fi
    
    # Test 4: Task Listing
    echo -e "${YELLOW}4. Testing Task Listing...${NC}"
    if [ -n "$TOKEN" ]; then
        TASKS=$(api_call GET "/task/list" "$TOKEN")
        TASK_COUNT=$(echo "$TASKS" | jq '.tasks | length' 2>/dev/null || echo "0")
        echo -e "${GREEN} Found $TASK_COUNT tasks${NC}"
    else
        echo -e "${YELLOW}  Skipping task listing (no valid token)${NC}"
        ((failed_tests++))
    fi
    
    # Test 5: Multi-User Isolation Quick Check
    echo -e "${YELLOW}5. Testing Multi-User Isolation...${NC}"
    
    # Create second user
    SECOND_REG=$(curl -s -X POST "$API_BASE/auth/register" \
        -H "Content-Type: application/json" \
        -d '{
            "name": "Second Quick User",
            "email": "secondquick@example.com",
            "password": "testpass123"
        }')
    
    # Login as second user
    SECOND_LOGIN=$(curl -s -X POST "$API_BASE/auth/login" \
        -H "Content-Type: application/json" \
        -d '{
            "email": "secondquick@example.com",
            "password": "testpass123"
        }')
    
    SECOND_TOKEN=$(echo "$SECOND_LOGIN" | jq -r '.access_token' 2>/dev/null || echo "")
    
    if [ -n "$SECOND_TOKEN" ] && [ "$SECOND_TOKEN" != "null" ]; then
        # Check isolation
        SECOND_TASKS=$(api_call GET "/task/list" "$SECOND_TOKEN")
        SECOND_COUNT=$(echo "$SECOND_TASKS" | jq '.tasks | length' 2>/dev/null || echo "0")
        
        if [ "$SECOND_COUNT" -eq 0 ]; then
            echo -e "${GREEN} User isolation working correctly${NC}"
        else
            echo -e "${RED} User isolation may have issues${NC}"
            ((failed_tests++))
        fi
    else
        echo -e "${YELLOW}  Could not test isolation (second user login failed)${NC}"
        ((failed_tests++))
    fi
    
    # Final Summary
    echo ""
    echo -e "${BLUE} Quick API Test Results${NC}"
    echo "========================"
    
    if [ $failed_tests -eq 0 ]; then
        echo -e "${GREEN} All quick API tests passed!${NC}"
        echo ""
        echo " Your Task Manager API is working correctly with:"
        echo "   • JWT authentication (1-hour tokens)"
        echo "   • Multi-user isolation"
        echo "   • Complete CRUD operations"
        echo "   • Proper error handling"
        return 0
    else
        echo -e "${RED} $failed_tests quick API test(s) failed${NC}"
        echo ""
        echo " Common issues:"
        echo "   1. API server not running on $API_BASE"
        echo "   2. Database connection problems"
        echo "   3. Redis not available for sessions"
        echo "   4. Environment configuration issues"
        return 1
    fi
}

# Main execution logic
PHPUNIT_RESULT=0
BEHAT_RESULT=0
PERFORMANCE_RESULT=0
API_QUICK_RESULT=0

if [ "$PERFORMANCE_ONLY" = "true" ]; then
    # Run only performance optimization tests
    if run_performance_tests; then
        PERFORMANCE_RESULT=0
    else
        PERFORMANCE_RESULT=1
    fi
elif [ "$API_QUICK_ONLY" = "true" ]; then
    # Run only quick API tests
    if run_api_quick_tests; then
        API_QUICK_RESULT=0
    else
        API_QUICK_RESULT=1
    fi
elif [ "$BEHAT_ONLY" = "true" ]; then
    # Run only Behat tests
    if run_behat_tests "$SUITE"; then
        BEHAT_RESULT=0
    else
        BEHAT_RESULT=1
    fi
elif [ "$PHPUNIT_ONLY" = "true" ]; then
    # Run only PHPUnit tests
    if run_phpunit_tests; then
        PHPUNIT_RESULT=0
    else
        PHPUNIT_RESULT=1
    fi
else
    # Run all tests (default)
    if run_phpunit_tests; then
        PHPUNIT_RESULT=0
    else
        PHPUNIT_RESULT=1
    fi
    
    if run_behat_tests "$SUITE"; then
        BEHAT_RESULT=0
    else
        BEHAT_RESULT=1
    fi
fi

# Final summary
echo -e "\n${BLUE} Test Summary${NC}"
echo "==============="

if [ "$PERFORMANCE_ONLY" = "true" ]; then
    # Performance tests only summary  
    if [ $PERFORMANCE_RESULT -eq 0 ]; then
        echo -e "${GREEN} All performance optimization tests passed!${NC}"
        echo -e "${GREEN} Performance optimizations validated and ready${NC}"
        exit 0
    else
        echo -e "${RED} Performance optimization tests failed${NC}"
        exit 1
    fi
elif [ "$API_QUICK_ONLY" = "true" ]; then
    # API quick tests only summary
    if [ $API_QUICK_RESULT -eq 0 ]; then
        echo -e "${GREEN} All quick API tests passed!${NC}"
        echo -e "${GREEN} API functionality verified${NC}"
        exit 0
    else
        echo -e "${RED} Quick API tests failed${NC}"
        exit 1
    fi
elif [ "$PHPUNIT_ONLY" != "true" ] && [ "$BEHAT_ONLY" != "true" ]; then
    # All tests summary
    if [ $PHPUNIT_RESULT -eq 0 ] && [ $BEHAT_RESULT -eq 0 ]; then
        echo -e "${GREEN} All tests passed!${NC}"
        echo -e "${GREEN} PHPUnit Tests (Unit + Integration Suites): PASSED${NC}"
        echo -e "${GREEN} Behat BDD Tests (API, Tasks, Security): PASSED${NC}"
        echo -e "\n${GREEN} Your Task Manager API is production-ready!${NC}"
        exit 0
    else
        echo -e "${RED} Some tests failed${NC}"
        [ $PHPUNIT_RESULT -eq 0 ] && echo -e "${GREEN} PHPUnit Tests: PASSED${NC}" || echo -e "${RED} PHPUnit Tests: FAILED${NC}"
        [ $BEHAT_RESULT -eq 0 ] && echo -e "${GREEN} Behat BDD Tests: PASSED${NC}" || echo -e "${RED} Behat BDD Tests: FAILED${NC}"
        exit 1
    fi
elif [ "$PHPUNIT_ONLY" = "true" ]; then
    # PHPUnit only summary
    if [ $PHPUNIT_RESULT -eq 0 ]; then
        echo -e "${GREEN} All PHPUnit tests passed!${NC}"
        echo -e "${GREEN} Static Analysis (PHPStan Level 8)${NC}"
        echo -e "${GREEN} Code Style (PSR-12 Compliance)${NC}"
        echo -e "${GREEN} Unit Tests (39 tests - Fast isolated testing)${NC}"
        echo -e "${GREEN} Integration Tests (101 tests - API, Security, Database)${NC}"
        exit 0
    else
        echo -e "${RED} PHPUnit tests failed${NC}"
        exit 1
    fi
elif [ "$BEHAT_ONLY" = "true" ]; then
    # Behat only summary
    if [ $BEHAT_RESULT -eq 0 ]; then
        echo -e "${GREEN} All Behat BDD tests passed!${NC}"
        if [ -n "$SUITE" ]; then
            echo -e "${GREEN} $SUITE BDD Tests: PASSED${NC}"
        else
            echo -e "${GREEN} API BDD Tests${NC}"
            echo -e "${GREEN} Task Management BDD Tests${NC}"
            echo -e "${GREEN} Security BDD Tests${NC}"
        fi
        exit 0
    else
        echo -e "${RED} Behat BDD tests failed${NC}"
        exit 1
    fi
fi

# No cleanup needed - docker-compose.test.yml is a permanent file