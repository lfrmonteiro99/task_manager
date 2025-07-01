# Task Manager API - Makefile
# Automatically enables COMPOSE_BAKE for better performance

.PHONY: help build up down restart logs test rebuild setup

export COMPOSE_BAKE=true

# Default target
help:
	@echo "Task Manager API - Available Make Commands"
	@echo "=========================================="
	@echo ""
	@echo "Setup & Management:"
	@echo "  setup     - Quick setup for new developers (build + up)"
	@echo "  build     - Build all containers"
	@echo "  up        - Start all services"
	@echo "  down      - Stop all services"
	@echo "  restart   - Restart all services (down + up)"
	@echo "  rebuild   - Full rebuild with no cache"
	@echo ""
	@echo "Development:"
	@echo "  logs      - View recent application logs"
	@echo "  test      - Run complete test suite"
	@echo ""
	@echo "Additional Commands:"
	@echo "  composer lint      - Check PSR-12 code style"
	@echo "  composer format    - Auto-fix code style"
	@echo "  composer analyze   - Run PHPStan analysis"
	@echo "  composer bench     - Run performance benchmarks"
	@echo ""

# Build containers
build:
	@echo " Building containers with Bake..."
	docker-compose build

# Start services  
up:
	@echo " Starting services..."
	docker-compose up -d

# Stop services
down:
	@echo " Stopping services..."
	docker-compose down

# Restart services
restart: down up

# View logs
logs:
	@echo " Viewing logs..."
	./view-logs.sh recent

# Run tests
test:
	@echo " Running test suite..."
	./run-tests.sh

# Full rebuild and restart
rebuild:
	@echo " Full rebuild and restart..."
	docker-compose down
	docker-compose build --no-cache
	docker-compose up -d

# Quick setup for new developers
setup: build up
	@echo " Task Manager API is ready!"
	@echo " API available at: http://localhost:8080"
	@echo " View logs with: make logs"
	@echo " Run tests with: make test"