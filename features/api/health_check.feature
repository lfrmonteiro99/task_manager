Feature: Health Check and System Monitoring
  As a system administrator
  I want to monitor system health
  So that I can ensure the API is functioning properly

  Scenario: Basic health check is publicly accessible
    Given I am not authenticated
    When I send a GET request to "/health"
    Then the response status should be 200
    And the response should be valid JSON
    And the JSON response should have key "checks"

  Scenario: Health check shows system status
    When I send a GET request to "/health"
    Then the response status should be 200
    And the response should be valid JSON
    And the JSON response should have key "checks"
    And the response should contain "database"

  Scenario: Health check shows database status
    When I send a GET request to "/health"
    Then the response status should be 200
    And the response should contain "database"
    And the JSON response should have key "checks"

  Scenario: Health check shows Redis status
    When I send a GET request to "/health"
    Then the response status should be 200
    And the response should contain "redis"
    And the JSON response should have key "checks"

  Scenario: Health check is consistently accessible
    When I send a GET request to "/health"
    And I send a GET request to "/health"
    And I send a GET request to "/health"
    Then the response status should be 200