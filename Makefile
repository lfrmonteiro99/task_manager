# Task Manager API - Makefile
# Automatically enables COMPOSE_BAKE for better performance

.PHONY: build up down restart logs test

export COMPOSE_BAKE=true

# Build containers
build:
	@echo "🚀 Building containers with Bake..."
	docker-compose build

# Start services  
up:
	@echo "🚀 Starting services..."
	docker-compose up -d

# Stop services
down:
	@echo "🛑 Stopping services..."
	docker-compose down

# Restart services
restart: down up

# View logs
logs:
	@echo "📋 Viewing logs..."
	./view-logs.sh recent

# Run tests
test:
	@echo "🧪 Running test suite..."
	./run-tests.sh

# Full rebuild and restart
rebuild:
	@echo "🔄 Full rebuild and restart..."
	docker-compose down
	docker-compose build --no-cache
	docker-compose up -d

# Quick setup for new developers
setup: build up
	@echo "✅ Task Manager API is ready!"
	@echo "📍 API available at: http://localhost:8080"
	@echo "📋 View logs with: make logs"
	@echo "🧪 Run tests with: make test"