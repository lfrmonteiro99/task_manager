<?php

declare(strict_types=1);

namespace BehatContext;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Assert;

/**
 * API Context for HTTP requests and responses
 */
class ApiContext implements Context
{
    private Client $client;
    private ?Response $response = null;
    private array $headers = [];
    private string $baseUrl;
    private string $jwtToken = '';
    private int $testUserId = 0;

    public function __construct()
    {
        $this->baseUrl = $_SERVER['TEST_API_BASE_URL'] ?? $_ENV['TEST_API_BASE_URL'] ?? getenv('TEST_API_BASE_URL') ?: 'http://localhost:8080';
        
        // Ensure we're in test environment
        if (getenv('APP_ENV') !== 'testing') {
            putenv('APP_ENV=testing');
        }
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'http_errors' => false, // Don't throw exceptions on HTTP errors
        ]);

        // Set default headers
        $this->headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        
        // Setup JWT authentication for tests
        $this->setupJwtAuthentication();
        
        // Set default authentication
        $this->iAmAuthenticatedWithAValidJwtToken();
    }
    
    private function setupJwtAuthentication(): void
    {
        try {
            // First try to register a test user
            $testEmail = 'behat_test_' . uniqid() . '@example.com';
            $testPassword = 'testpass123';
            
            $registerResponse = $this->client->post('/auth/register', [
                'headers' => $this->headers,
                'json' => [
                    'name' => 'Behat Test User',
                    'email' => $testEmail,
                    'password' => $testPassword
                ]
            ]);
            
            if ($registerResponse->getStatusCode() === 200 || $registerResponse->getStatusCode() === 201) {
                $data = json_decode((string)$registerResponse->getBody(), true);
                $this->jwtToken = $data['access_token'];
                $this->testUserId = $data['user']['id'];
                return;
            }
            
            // If registration fails, try login with existing test user
            $response = $this->client->post('/auth/login', [
                'headers' => $this->headers,
                'json' => [
                    'email' => $testEmail,
                    'password' => $testPassword
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode((string)$response->getBody(), true);
                $this->jwtToken = $data['access_token'];
                $this->testUserId = $data['user']['id'];
            }
        } catch (\Exception $e) {
            // If authentication fails, set empty token so tests fail appropriately with clear 401 errors
            $this->jwtToken = '';
            $this->testUserId = 0;
        }
    }

    /**
     * @Given I am authenticated with a valid JWT token
     */
    public function iAmAuthenticatedWithAValidJwtToken(): void
    {
        if ($this->jwtToken) {
            $this->headers['Authorization'] = 'Bearer ' . $this->jwtToken;
        }
    }

    /**
     * @Given I am not authenticated
     */
    public function iAmNotAuthenticated(): void
    {
        unset($this->headers['Authorization']);
    }

    /**
     * @Given I use an invalid JWT token
     */
    public function iUseAnInvalidJwtToken(): void
    {
        $this->headers['Authorization'] = 'Bearer invalid-jwt-token';
    }

    /**
     * @When I send a GET request to :endpoint
     */
    public function iSendAGetRequestTo(string $endpoint): void
    {
        try {
            $this->response = $this->client->get($endpoint, [
                'headers' => $this->headers
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @When I send a POST request to :endpoint
     */
    public function iSendAPostRequestTo(string $endpoint): void
    {
        try {
            $this->response = $this->client->post($endpoint, [
                'headers' => $this->headers
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @When I send a POST request to :endpoint with:
     */
    public function iSendAPostRequestToWith(string $endpoint, TableNode $table): void
    {
        $data = [];
        foreach ($table->getRowsHash() as $key => $value) {
            $data[$key] = $value;
        }

        try {
            $this->response = $this->client->post($endpoint, [
                'headers' => $this->headers,
                'json' => $data
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @When I send a POST request to :endpoint with JSON:
     */
    public function iSendAPostRequestToWithJson(string $endpoint, PyStringNode $jsonString): void
    {
        $data = json_decode($jsonString->getRaw(), true);
        
        try {
            $this->response = $this->client->post($endpoint, [
                'headers' => $this->headers,
                'json' => $data
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @When I send a PUT request to :endpoint with:
     */
    public function iSendAPutRequestToWith(string $endpoint, TableNode $table): void
    {
        $data = [];
        foreach ($table->getRowsHash() as $key => $value) {
            $data[$key] = $value;
        }

        try {
            $this->response = $this->client->put($endpoint, [
                'headers' => $this->headers,
                'json' => $data
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @When I send a DELETE request to :endpoint
     */
    public function iSendADeleteRequestTo(string $endpoint): void
    {
        try {
            $this->response = $this->client->delete($endpoint, [
                'headers' => $this->headers
            ]);
        } catch (RequestException $e) {
            $this->response = $e->getResponse();
        }
    }

    /**
     * @Then the response status should be :statusCode
     */
    public function theResponseStatusShouldBe(int $statusCode): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        Assert::assertEquals(
            $statusCode, 
            $this->response->getStatusCode(),
            'Response status code does not match expected value. Response body: ' . $this->response->getBody()
        );
    }

    /**
     * @Then the response should contain :text
     */
    public function theResponseShouldContain(string $text): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $body = (string) $this->response->getBody();
        Assert::assertStringContainsString($text, $body);
    }

    /**
     * @Then the response should not contain :text
     */
    public function theResponseShouldNotContain(string $text): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $body = (string) $this->response->getBody();
        Assert::assertStringNotContainsString($text, $body);
    }

    /**
     * @Then the response should be valid JSON
     */
    public function theResponseShouldBeValidJson(): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $body = (string) $this->response->getBody();
        $decoded = json_decode($body, true);
        Assert::assertNotNull($decoded, 'Response is not valid JSON: ' . $body);
    }

    /**
     * @Then the JSON response should have key :key
     */
    public function theJsonResponseShouldHaveKey(string $key): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $body = (string) $this->response->getBody();
        $data = json_decode($body, true);
        Assert::assertArrayHasKey($key, $data);
    }

    /**
     * @Then the JSON response should have :count items
     */
    public function theJsonResponseShouldHaveItems(int $count): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $body = (string) $this->response->getBody();
        $data = json_decode($body, true);
        Assert::assertCount($count, $data);
    }

    /**
     * @Then the response header :header should be :value
     */
    public function theResponseHeaderShouldBe(string $header, string $value): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        $headerValue = $this->response->getHeaderLine($header);
        Assert::assertEquals($value, $headerValue);
    }

    /**
     * @Then the response header :header should exist
     */
    public function theResponseHeaderShouldExist(string $header): void
    {
        Assert::assertNotNull($this->response, 'No response received');
        Assert::assertTrue($this->response->hasHeader($header));
    }

    /**
     * Get the current response for other contexts
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Get response body as array
     */
    public function getResponseData(): ?array
    {
        if (!$this->response) {
            return null;
        }
        
        $body = (string) $this->response->getBody();
        return json_decode($body, true);
    }

    public function getClient(): Client
    {
        return $this->client;
    }
}