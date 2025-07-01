Feature: API Authentication and Authorization
  As a system administrator
  I want secure access control to the API
  So that only authorized users can manage tasks

  Scenario: Accessing protected endpoints without authentication
    When I attempt to access "/task/list" without authentication
    Then I should be denied access
    And the response status should be 401
    And the response should contain "Unauthorized"

  Scenario: Accessing protected endpoints with invalid JWT token
    When I attempt to access "/task/list" with invalid credentials
    Then I should be denied access
    And the response status should be 401
    And the response should contain "Invalid"

  Scenario: Successful authentication with valid JWT token
    Given I am authenticated with a valid JWT token
    When I send a GET request to "/task/list"
    Then the response status should be 200
    And the response should be valid JSON

  Scenario: Creating tasks requires authentication
    When I attempt to access "/task/create" without authentication
    Then I should be denied access
    And the authentication error should be logged

  Scenario: Updating tasks requires authentication
    When I attempt to access "/task/1" without authentication
    Then I should be denied access

  Scenario: Deleting tasks requires authentication
    Given I am not authenticated
    When I send a DELETE request to "/task/1"
    Then I should be denied access

  Scenario: Health endpoint is publicly accessible  
    Given I am not authenticated
    When I send a GET request to "/health"
    Then the response status should be 200
    And the response should be valid JSON