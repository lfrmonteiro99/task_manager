#!/bin/bash

# Docker Compose wrapper with Bake enabled
# Usage: ./docker-build.sh [docker-compose commands]

export COMPOSE_BAKE=true

if [ $# -eq 0 ]; then
    echo " Docker Compose with Bake enabled"
    echo "Usage: $0 [docker-compose commands]"
    echo ""
    echo "Examples:"
    echo "  $0 build           # Build all services with Bake"
    echo "  $0 build app       # Build specific service with Bake"  
    echo "  $0 up -d           # Start services"
    echo "  $0 down            # Stop services"
    echo ""
else
    echo " Running: docker-compose $@ (with COMPOSE_BAKE=true)"
    docker-compose "$@"
fi