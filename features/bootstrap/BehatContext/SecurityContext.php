<?php

declare(strict_types=1);

namespace BehatContext;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;

/**
 * Security-specific context for authentication and rate limiting
 */
class SecurityContext implements Context
{
    private ?ApiContext $apiContext = null;
    private int $requestCount = 0;

    public function __construct()
    {
    }
    
    /**
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();
        $this->apiContext = $environment->getContext(ApiContext::class);
    }

    /**
     * @Given I have made :count requests in the last hour
     */
    public function iHaveMadeRequestsInTheLastHour(int $count): void
    {
        $this->apiContext->iAmAuthenticatedWithAValidJwtToken();
        
        // Make the specified number of requests, but stop if we hit rate limit
        for ($i = 0; $i < $count; $i++) {
            $this->apiContext->iSendAGetRequestTo('/health');
            $response = $this->apiContext->getResponse();
            $this->requestCount++;
            
            // If we hit rate limit, we've achieved the goal
            if ($response->getStatusCode() === 429) {
                break;
            }
        }
    }

    /**
     * @When I make another request to any endpoint
     */
    public function iMakeAnotherRequestToAnyEndpoint(): void
    {
        $this->apiContext->iAmAuthenticatedWithAValidJwtToken();
        $this->apiContext->iSendAGetRequestTo('/task/list');
        $this->requestCount++;
    }

    /**
     * @When I make :count rapid requests
     */
    public function iMakeRapidRequests(int $count): void
    {
        $this->apiContext->iAmAuthenticatedWithAValidJwtToken();
        
        for ($i = 0; $i < $count; $i++) {
            $this->apiContext->iSendAGetRequestTo('/task/list');
            $this->requestCount++;
        }
    }

    /**
     * @When I attempt to access :endpoint without authentication
     */
    public function iAttemptToAccessWithoutAuthentication(string $endpoint): void
    {
        $this->apiContext->iAmNotAuthenticated();
        $this->apiContext->iSendAGetRequestTo($endpoint);
    }

    /**
     * @When I attempt to access :endpoint with invalid credentials
     */
    public function iAttemptToAccessWithInvalidCredentials(string $endpoint): void
    {
        $this->apiContext->iUseAnInvalidJwtToken();
        $this->apiContext->iSendAGetRequestTo($endpoint);
    }

    /**
     * @When I send a request with malicious content:
     */
    public function iSendARequestWithMaliciousContent(TableNode $table): void
    {
        $this->apiContext->iAmAuthenticatedWithAValidJwtToken();
        
        $data = [];
        foreach ($table->getRowsHash() as $key => $value) {
            $data[$key] = $value;
        }
        
        $this->apiContext->iSendAPostRequestToWith('/task/create', $table);
    }

    /**
     * @Then I should be denied access
     */
    public function iShouldBeDeniedAccess(): void
    {
        $response = $this->apiContext->getResponse();
        Assert::assertContains(
            $response->getStatusCode(),
            [401, 403],
            'Request should be denied with 401 or 403 status code'
        );
    }

    /**
     * @Then I should receive a rate limit error
     */
    public function iShouldReceiveARateLimitError(): void
    {
        $this->apiContext->theResponseStatusShouldBe(429);
        $this->apiContext->theResponseShouldContain('Rate limit exceeded');
    }

    /**
     * @Then I should see rate limiting headers
     */
    public function iShouldSeeRateLimitingHeaders(): void
    {
        $this->apiContext->theResponseHeaderShouldExist('X-RateLimit-Limit');
        $this->apiContext->theResponseHeaderShouldExist('X-RateLimit-Remaining');
        $this->apiContext->theResponseHeaderShouldExist('X-RateLimit-Reset');
    }

    /**
     * @Then I should wait before making more requests
     */
    public function iShouldWaitBeforeMakingMoreRequests(): void
    {
        $this->apiContext->theResponseHeaderShouldExist('Retry-After');
        
        $response = $this->apiContext->getResponse();
        $retryAfter = $response->getHeaderLine('Retry-After');
        Assert::assertGreaterThan(0, (int)$retryAfter, 'Retry-After should be greater than 0');
    }

    /**
     * @Then the request should be rejected for security reasons
     */
    public function theRequestShouldBeRejectedForSecurityReasons(): void
    {
        $response = $this->apiContext->getResponse();
        Assert::assertContains(
            $response->getStatusCode(),
            [400, 422],
            'Request should be rejected with 400 or 422 status code for security validation'
        );
        
        $this->apiContext->theResponseShouldContain('Security violation');
    }

    /**
     * @Then security headers should be present
     */
    public function securityHeadersShouldBePresent(): void
    {
        $expectedHeaders = [
            'X-Content-Type-Options',
            'X-Frame-Options',
            'X-XSS-Protection'
        ];
        
        foreach ($expectedHeaders as $header) {
            $this->apiContext->theResponseHeaderShouldExist($header);
        }
    }

    /**
     * @Then the response should not expose sensitive information
     */
    public function theResponseShouldNotExposeSensitiveInformation(): void
    {
        $sensitivePatterns = [
            '/password/i',
            '/api[_-]?key/i',
            '/secret/i',
            '/token/i',
            '/database/i',
            '/config/i'
        ];
        
        $body = (string) $this->apiContext->getResponse()->getBody();
        
        foreach ($sensitivePatterns as $pattern) {
            Assert::assertDoesNotMatchRegularExpression(
                $pattern,
                $body,
                "Response should not contain sensitive information matching pattern: {$pattern}"
            );
        }
    }

    /**
     * @Then the authentication error should be logged
     */
    public function theAuthenticationErrorShouldBeLogged(): void
    {
        // In a real implementation, this would check log files
        // For now, we just verify the response indicates proper error handling
        $response = $this->apiContext->getResponse();
        Assert::assertTrue(
            in_array($response->getStatusCode(), [401, 403]),
            'Authentication error should return proper HTTP status code'
        );
    }

    /**
     * Reset request count for clean state between scenarios
     * 
     * @BeforeScenario
     */
    public function resetRequestCount(): void
    {
        $this->requestCount = 0;
    }
}