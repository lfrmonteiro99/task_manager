Feature: Input Security and Validation
  As a security-conscious developer
  I want to prevent malicious input
  So that the system is protected from attacks

  Background:
    Given I am authenticated with a valid JWT token

  Scenario: XSS prevention in task title
    When I send a request with malicious content:
      | title       | <script>alert('xss')</script> |
      | description | Valid description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the request should be rejected for security reasons

  Scenario: SQL injection prevention in task description
    When I send a request with malicious content:
      | title       | Valid Title |
      | description | '; DROP TABLE tasks; -- |
      | due_date    | 2025-12-31 23:59:59 |
    Then the request should be rejected for security reasons

  Scenario: Script tag prevention
    When I send a request with malicious content:
      | title       | Normal Title |
      | description | <script src="evil.js"></script> |
      | due_date    | 2025-12-31 23:59:59 |
    Then the request should be rejected for security reasons

  Scenario: JavaScript event handler prevention
    When I send a request with malicious content:
      | title       | <img src=x onerror=alert(1)> |
      | description | Valid description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 200
    And the response should contain "success"

  Scenario: Security headers are present
    When I send a GET request to "/task/list"
    Then the response status should be 200
    And security headers should be present

  Scenario: Error messages don't expose sensitive information
    When I send a GET request to "/task/999999"
    Then the response should not expose sensitive information

  Scenario: Valid input is accepted
    When I send a POST request to "/task/create" with:
      | title       | Safe Task Title |
      | description | This is a completely safe description |
      | due_date    | 2025-12-31 23:59:59 |
    Then the response status should be 200
    And the response should contain "success"