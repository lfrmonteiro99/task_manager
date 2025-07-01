<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Context\RequestContext;
use App\Entities\User;

class RequestContextTest extends TestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = RequestContext::getInstance();
    }

    protected function tearDown(): void
    {
        TestHelper::cleanup();
    }

    public function testRequestContextIsSingleton(): void
    {
        $context1 = RequestContext::getInstance();
        $context2 = RequestContext::getInstance();
        
        $this->assertSame($context1, $context2);
    }

    public function testGetRequestIdIsGenerated(): void
    {
        $requestId = $this->context->getRequestId();
        
        $this->assertIsString($requestId);
        $this->assertStringStartsWith('req_', $requestId);
    }

    public function testSetAndGetUser(): void
    {
        $userData = TestHelper::createTestUser();
        $user = new User();
        $user->setId($userData['id']);
        $user->setEmail($userData['email']);
        $user->setName($userData['name']);
        
        $this->context->setUser($user);
        
        $this->assertSame($user, $this->context->getUser());
        $this->assertEquals($userData['id'], $this->context->getUserId());
        $this->assertEquals($userData['email'], $this->context->getUserEmail());
        $this->assertTrue($this->context->isAuthenticated());
    }

    public function testGetUserWhenNotSet(): void
    {
        $this->assertNull($this->context->getUser());
        $this->assertNull($this->context->getUserId());
        $this->assertNull($this->context->getUserEmail());
        $this->assertFalse($this->context->isAuthenticated());
    }

    public function testMetadataStorage(): void
    {
        $this->context->setMetadata('test_key', 'test_value');
        $this->context->setMetadata('numeric_key', 123);
        $this->context->setMetadata('array_key', ['test' => 'data']);
        
        $this->assertEquals('test_value', $this->context->getMetadata('test_key'));
        $this->assertEquals(123, $this->context->getMetadata('numeric_key'));
        $this->assertEquals(['test' => 'data'], $this->context->getMetadata('array_key'));
        
        $this->assertEquals('default', $this->context->getMetadata('nonexistent_key', 'default'));
        
        $allMetadata = $this->context->getAllMetadata();
        $this->assertIsArray($allMetadata);
        $this->assertArrayHasKey('test_key', $allMetadata);
        $this->assertArrayHasKey('numeric_key', $allMetadata);
        $this->assertArrayHasKey('array_key', $allMetadata);
    }

    public function testGetHeaderWithValidHeaders(): void
    {
        // Simulate HTTP headers in $_SERVER
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        $_SERVER['HTTP_CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'custom-value';
        
        $this->assertEquals('Bearer test-token', $this->context->getHeader('Authorization'));
        $this->assertEquals('application/json', $this->context->getHeader('Content-Type'));
        $this->assertEquals('custom-value', $this->context->getHeader('X-Custom-Header'));
        
        // Test case insensitivity
        $this->assertEquals('Bearer test-token', $this->context->getHeader('authorization'));
        
        // Clean up
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_CONTENT_TYPE'], $_SERVER['HTTP_X_CUSTOM_HEADER']);
    }

    public function testGetHeaderWithMissingHeaders(): void
    {
        $this->assertNull($this->context->getHeader('Nonexistent-Header'));
    }

    public function testToArray(): void
    {
        $userData = TestHelper::createTestUser();
        $user = new User();
        $user->setId($userData['id']);
        $user->setEmail($userData['email']);
        $user->setName($userData['name']);
        
        $this->context->setUser($user);
        $this->context->setMetadata('test_key', 'test_value');
        
        $array = $this->context->toArray();
        
        $this->assertIsArray($array);
        $this->assertArrayHasKey('request_id', $array);
        $this->assertArrayHasKey('user_id', $array);
        $this->assertArrayHasKey('user_email', $array);
        $this->assertArrayHasKey('user_agent', $array);
        $this->assertArrayHasKey('ip_address', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertArrayHasKey('timestamp', $array);
        
        $this->assertEquals($userData['id'], $array['user_id']);
        $this->assertEquals($userData['email'], $array['user_email']);
        $this->assertIsArray($array['metadata']);
        $this->assertEquals('test_value', $array['metadata']['test_key']);
    }

    public function testResetClearsState(): void
    {
        $userData = TestHelper::createTestUser();
        $user = new User();
        $user->setId($userData['id']);
        $user->setEmail($userData['email']);
        $user->setName($userData['name']);
        
        $this->context->setUser($user);
        $this->context->setMetadata('test_key', 'test_value');
        
        $this->assertTrue($this->context->isAuthenticated());
        $this->assertNotEmpty($this->context->getAllMetadata());
        
        RequestContext::reset();
        
        $newContext = RequestContext::getInstance();
        $this->assertFalse($newContext->isAuthenticated());
        $this->assertEmpty($newContext->getAllMetadata());
    }
}