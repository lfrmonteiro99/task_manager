<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Container\DIContainer;
use App\Config\AppConfig;
use App\Context\RequestContext;
use App\Services\JwtService;
use App\Services\JwtServiceInterface;

class DIContainerTest extends TestCase
{
    private DIContainer $container;

    protected function setUp(): void
    {
        $this->container = DIContainer::getInstance();
    }

    protected function tearDown(): void
    {
        TestHelper::cleanup();
    }

    public function testContainerIsSingleton(): void
    {
        $container1 = DIContainer::getInstance();
        $container2 = DIContainer::getInstance();
        
        $this->assertSame($container1, $container2);
    }

    public function testContainerCanResolveAppConfig(): void
    {
        $config = $this->container->get(AppConfig::class);
        
        $this->assertInstanceOf(AppConfig::class, $config);
    }

    public function testContainerCanResolveRequestContext(): void
    {
        $context = $this->container->get(RequestContext::class);
        
        $this->assertInstanceOf(RequestContext::class, $context);
    }

    public function testContainerCanResolveJwtService(): void
    {
        $jwtService = $this->container->get(JwtService::class);
        
        $this->assertInstanceOf(JwtService::class, $jwtService);
    }

    public function testContainerCanResolveJwtServiceInterface(): void
    {
        $jwtService = $this->container->get(JwtServiceInterface::class);
        
        $this->assertInstanceOf(JwtServiceInterface::class, $jwtService);
        $this->assertInstanceOf(JwtService::class, $jwtService);
    }

    public function testContainerReturnsSameInstanceForSingletons(): void
    {
        $config1 = $this->container->get(AppConfig::class);
        $config2 = $this->container->get(AppConfig::class);
        
        $this->assertSame($config1, $config2);
    }

    public function testContainerCanBindAndResolveCustomServices(): void
    {
        // Test binding a custom service
        $this->container->bind('TestService', function() {
            return new stdClass();
        });
        
        $service = $this->container->get('TestService');
        
        $this->assertInstanceOf(stdClass::class, $service);
    }

    public function testContainerHasMethod(): void
    {
        $this->assertTrue($this->container->has(AppConfig::class));
        $this->assertFalse($this->container->has('NonExistentService'));
    }

    public function testContainerFlushClearsBindings(): void
    {
        $this->container->bind('TestService', function() {
            return new stdClass();
        });
        
        $this->assertTrue($this->container->has('TestService'));
        
        $this->container->flush();
        
        // After flush, custom bindings should be gone but default bindings should be restored
        $this->assertFalse($this->container->has('TestService'));
        $this->assertTrue($this->container->has(AppConfig::class));
    }
}