#!/bin/bash

# Start test database if not running
echo "Starting test database..."
docker-compose up -d db_test

# Wait for database to be ready
echo "Waiting for database to be ready..."
sleep 10

# Run tests with Docker environment
echo "Running tests..."
export DB_TEST_HOST=localhost:3307
export DB_TEST_NAME=task_manager_test
export DB_USER=taskuser
export DB_PASS=taskpass

./vendor/bin/phpunit

echo "Tests completed!"