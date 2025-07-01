#!/bin/bash

echo "=== Task Manager API Endpoint Testing ==="
echo "=========================================="

BASE_URL="http://localhost:8080"

echo "1. Testing health endpoint..."
curl -s -X GET "$BASE_URL/health" | jq '.status'

echo "2. Testing user registration..."
REGISTER_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/register" \
  -H "Content-Type: application/json" \
  -d '{"email": "endpointtest@example.com", "password": "password123", "name": "Endpoint Test User"}')

echo "$REGISTER_RESPONSE" | jq '.message'

echo "3. Testing user login..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "endpointtest@example.com", "password": "password123"}')

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.access_token')
echo "Login successful: $(echo "$LOGIN_RESPONSE" | jq '.message')"
echo "Token length: ${#TOKEN}"

if [ ${#TOKEN} -gt 50 ]; then
    echo "4. Testing task listing..."
    curl -s -X GET "$BASE_URL/task/list" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" | jq '.'
    
    echo "5. Testing task creation..."
    CREATE_RESPONSE=$(curl -s -X POST "$BASE_URL/task/create" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"title": "End-to-End Test Task", "description": "Testing complete API functionality", "due_date": "2025-12-01 10:00:00", "priority": "high"}')
    echo "$CREATE_RESPONSE" | jq '.'
    
    echo "6. Testing task statistics..."
    curl -s -X GET "$BASE_URL/task/statistics" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" | jq '.'
    
    echo "7. Testing overdue tasks..."
    curl -s -X GET "$BASE_URL/task/overdue" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" | jq '.'
    
    echo "8. Testing updated task listing..."
    curl -s -X GET "$BASE_URL/task/list" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" | jq '.[] | {id, title, priority}'
else
    echo "ERROR: Failed to get valid token"
fi

echo "=========================================="
echo "=== Endpoint testing completed ==="