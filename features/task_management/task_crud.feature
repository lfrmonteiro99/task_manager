Feature: Task Management CRUD Operations
  As a project manager
  I want to create, read, update, and delete tasks
  So that I can manage project deliverables effectively

  Background:
    Given I am authenticated with a valid JWT token

  Scenario: Creating a new task
    When I send a POST request to "/task/create" with:
      | title       | Complete user documentation |
      | description | Write comprehensive API docs with examples |
      | due_date    | 2025-12-31 23:59:59 |
      | priority    | high |
    Then the response status should be 200
    And the response should contain "Task created successfully"
    And the task should be stored in the database

  Scenario: Creating a task with minimal information
    When I send a POST request to "/task/create" with:
      | title       | Quick task |
      | description | Simple task description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 200
    And the response should contain "success"
    And the task should be stored in the database

  Scenario: Viewing all tasks
    Given I have 3 tasks in the system
    When I request the task list
    Then the response status should be 200
    And the response should be valid JSON
    And I should see 3 tasks in the response

  Scenario: Viewing a specific task
    Given I have a task with title "Important Meeting"
    When I request the created task details
    Then the response status should be 200
    And the response should contain "Important Meeting"
    And the task response should have key "id"
    And the task response should have key "title"
    And the task response should have key "due_date"

  Scenario: Updating an existing task
    Given I have a task with title "Original Title"
    When I update the created task with:
      | title       | Updated Title |
      | description | Updated description with more details |
      | due_date    | 2026-08-15 14:30:00 |
    Then the response status should be 200
    And the response should contain "success"

  Scenario: Marking a task as completed
    Given I have a task with title "Task to Complete"
    When I mark the task as completed
    Then the response status should be 200
    And the response should contain "success"
    And the task should be marked as completed

  Scenario: Deleting a task
    Given I have a task with title "Task to Delete"
    When I delete the task
    Then the response status should be 200
    And the response should contain "success"
    And the task should be removed from the system