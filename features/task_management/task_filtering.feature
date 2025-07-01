Feature: Task Filtering and Statistics
  As a project manager
  I want to filter tasks and view statistics
  So that I can monitor project progress and identify issues

  Background:
    Given I am authenticated with a valid JWT token

  Scenario: Viewing overdue tasks endpoint
    When I request overdue tasks
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Viewing task statistics with no tasks
    When I request task statistics
    Then the response status should be 200
    And the response should be valid JSON
    And the statistics should show 0 total tasks
    And the statistics should show 0 completed tasks

  Scenario: Viewing task statistics with mixed tasks
    Given I have 5 tasks in the system
    And I have completed tasks
    When I request task statistics
    Then the response status should be 200
    And the response should be valid JSON
    And the JSON response should have key "statistics"
    And the statistics should show 6 total tasks

  Scenario: Task list is properly ordered
    Given I have tasks with the following details:
      | title    | due_date            |
      | Task A   | 2026-12-31 23:59:59 |
      | Task B   | 2026-01-15 12:00:00 |
      | Task C   | 2026-06-30 18:30:00 |
    When I request the task list
    Then the response status should be 200
    And the response should be valid JSON
    And I should see 3 tasks in the response

  Scenario: Empty task list
    When I request the task list
    Then the response status should be 200
    And the response should be valid JSON
    And I should see 0 tasks in the response