Feature: Advanced Search and Filtering (Simplified)
  As a project manager
  I want to use search and filtering capabilities
  So that I can efficiently find and manage tasks

  Background:
    Given I am authenticated with a valid JWT token

  Scenario: Basic text search functionality
    Given I have tasks with the following details:
      | title                | description                     | due_date            |
      | Project Alpha Setup  | Setup for Project Alpha         | 2026-01-15 10:00:00 |
      | Meeting Preparation  | Prepare for quarterly meeting   | 2026-01-20 14:00:00 |
      | Alpha Documentation  | Document Project Alpha process  | 2026-01-25 16:00:00 |
    When I send a GET request to "/task/list?search=Alpha"
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Filter by priority level
    Given I have tasks with the following details:
      | title          | description           | due_date            | priority |
      | Urgent Fix     | Fix critical bug      | 2026-01-10 09:00:00 | urgent   |
      | Important Task | Handle important work | 2026-01-15 12:00:00 | high     |
      | Regular Work   | Normal priority task  | 2026-01-20 15:00:00 | medium   |
    When I send a GET request to "/task/list?priority=urgent"
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Basic pagination test
    Given I have 5 tasks in the system
    When I send a GET request to "/task/list?page=1&limit=3"
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Empty search results
    When I send a GET request to "/task/list?search=nonexistentterm12345"
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Invalid filter parameters graceful handling
    When I send a GET request to "/task/list?priority=supercritical"
    Then the response status should be 200
    And the response should be valid JSON