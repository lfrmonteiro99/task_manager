#!/bin/bash

echo "=== COMPREHENSIVE ENDPOINT TEST ==="
echo "==================================="

BASE_URL="http://localhost:8080"

# Get authentication token
echo "🔐 Getting authentication token..."
TOKEN=$(curl -s -X POST "$BASE_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "directtest@example.com", "password": "password123"}' | jq -r '.access_token')

if [ ${#TOKEN} -lt 50 ]; then
    echo " Failed to get valid token"
    exit 1
fi

echo " Token acquired (length: ${#TOKEN})"

# Test all endpoints in sequence
echo -e "\n Testing task management endpoints..."

echo "1. Task List (should be empty):"
curl -s -X GET "$BASE_URL/task/list" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" | jq '.tasks | length'

echo -e "\n2. Creating test tasks..."
TASK1=$(curl -s -X POST "$BASE_URL/task/create" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title": "High Priority Task", "description": "Critical task", "due_date": "2025-12-15 10:00:00", "priority": "high"}')
echo "Task 1: $(echo "$TASK1" | jq -r '.message // .error')"

TASK2=$(curl -s -X POST "$BASE_URL/task/create" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title": "Medium Priority Task", "description": "Regular task", "due_date": "2025-12-20 14:00:00", "priority": "medium"}')
echo "Task 2: $(echo "$TASK2" | jq -r '.message // .error')"

TASK3=$(curl -s -X POST "$BASE_URL/task/create" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title": "Overdue Task", "description": "Past due task", "due_date": "2024-06-01 10:00:00", "priority": "urgent"}')
echo "Task 3: $(echo "$TASK3" | jq -r '.message // .error')"

echo -e "\n3. Updated task list:"
TASKS=$(curl -s -X GET "$BASE_URL/task/list" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json")
echo "Total tasks: $(echo "$TASKS" | jq '.tasks | length')"
echo "$TASKS" | jq '.tasks[] | {id, title, priority, due_date}'

echo -e "\n4. Task statistics:"
curl -s -X GET "$BASE_URL/task/statistics" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" | jq '.statistics | {total_tasks, pending_tasks, overdue_tasks, completion_rate}'

echo -e "\n5. Overdue tasks:"
curl -s -X GET "$BASE_URL/task/overdue" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" | jq '.overdue_tasks[] | {id, title, due_date}'

echo -e "\n6. Testing task operations..."
FIRST_TASK_ID=$(echo "$TASKS" | jq -r '.tasks[0].id')

if [ "$FIRST_TASK_ID" != "null" ]; then
    echo "Updating task $FIRST_TASK_ID:"
    UPDATE_RESULT=$(curl -s -X PUT "$BASE_URL/task/$FIRST_TASK_ID" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"title": "Updated Task", "description": "This task was updated", "due_date": "2025-12-25 12:00:00", "priority": "urgent"}')
    echo "Update: $(echo "$UPDATE_RESULT" | jq -r '.message // .error')"
    
    echo "Marking task as done:"
    DONE_RESULT=$(curl -s -X POST "$BASE_URL/task/$FIRST_TASK_ID/done" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json")
    echo "Mark done: $(echo "$DONE_RESULT" | jq -r '.message // .error')"
fi

echo -e "\n7. Final statistics:"
curl -s -X GET "$BASE_URL/task/statistics" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" | jq '.statistics | {total_tasks, completed_tasks, pending_tasks, completion_rate}'

echo -e "\n8. Testing authentication scenarios:"
echo "a) No token (should fail):"
NO_AUTH=$(curl -s -X GET "$BASE_URL/task/list" | jq -r '.error')
echo "   Result: $NO_AUTH"

echo "b) Invalid token (should fail):"
INVALID_AUTH=$(curl -s -X GET "$BASE_URL/task/list" \
  -H "Authorization: Bearer invalid.token.here" | jq -r '.error')
echo "   Result: $INVALID_AUTH"

echo "c) Valid token (should work):"
VALID_AUTH=$(curl -s -X GET "$BASE_URL/task/list" \
  -H "Authorization: Bearer $TOKEN" | jq '.tasks | length')
echo "   Result: Found $VALID_AUTH tasks"

echo -e "\n9. Testing other endpoints..."
echo "Profile:"
curl -s -X GET "$BASE_URL/auth/profile" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" | jq '.user | {id, email, name}'

echo -e "\nHealth check:"
curl -s -X GET "$BASE_URL/health" | jq '{status, version}'

echo -e "\n==================================="
echo " ENDPOINT TESTING COMPLETED"
echo "==================================="