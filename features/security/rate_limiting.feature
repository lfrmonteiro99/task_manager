Feature: API Rate Limiting
  As a system administrator
  I want to limit API usage per user
  So that the system remains available and performs well

  Scenario: Normal usage within rate limits
    Given I am authenticated with a valid JWT token
    When I make 5 rapid requests
    Then the response status should be 200
    And I should see rate limiting headers

  @skip
  Scenario: Rate limiting kicks in after threshold
    Given I have made 100 requests in the last hour
    When I make another request to any endpoint
    Then I should receive a rate limit error
    And I should see rate limiting headers
    And I should wait before making more requests

  Scenario: Rate limit headers are present in responses
    Given I am authenticated with a valid JWT token
    When I send a GET request to "/task/list"
    Then I should see rate limiting headers

  Scenario: Rate limit applies per user
    Given I am authenticated with a valid JWT token
    When I make 10 rapid requests
    Then I should see rate limiting headers

  @skip  
  Scenario: Rate limit resets after time window
    Given I have made 100 requests in the last hour
    And I wait for the rate limit window to reset
    When I make another request to any endpoint
    Then the response status should be 200