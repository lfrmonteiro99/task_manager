Feature: Task Validation and Error Handling
  As a system administrator
  I want proper validation of task data
  So that data integrity is maintained

  Background:
    Given I am authenticated with a valid JWT token

  Scenario: Creating a task with missing required fields
    When I send a POST request to "/task/create" with:
      | title | |
    Then the response status should be 422
    And the response should contain "Validation failed"

  Scenario: Creating a task with invalid due date
    When I send a POST request to "/task/create" with:
      | title       | Invalid Date Task |
      | description | Task with past due date |
      | due_date    | 2020-01-01 00:00:00 |
    Then the response status should be 422
    And the response should contain "future"

  Scenario: Creating a task with title too short
    When I send a POST request to "/task/create" with:
      | title       | AB |
      | description | Valid description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 422
    And the response should contain "Validation failed"

  Scenario: Creating a task with description too short
    When I send a POST request to "/task/create" with:
      | title       | Valid Title |
      | description | Short |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 422
    And the response should contain "Validation failed"

  Scenario: Updating a non-existent task
    When I send a PUT request to "/task/999" with:
      | title       | Updated Title |
      | description | Updated description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 404
    And the response should contain "not found"

  Scenario: Marking a non-existent task as done
    When I send a POST request to "/task/999/done"
    Then the response status should be 500
    And the response should contain "not found"

  Scenario: Deleting a non-existent task
    When I send a DELETE request to "/task/999"
    Then the response status should be 500
    And the response should contain "not found"

  Scenario: Accessing a specific task that doesn't exist
    When I send a GET request to "/task/999"
    Then the response status should be 404
    And the response should contain "not found"