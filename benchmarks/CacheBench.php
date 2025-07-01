<?php

declare(strict_types=1);

namespace Benchmarks;

use App\Cache\CacheFactory;
use PhpBench\Attributes as Bench;

/**
 * Benchmark tests for cache performance
 */
class CacheBench
{
    private $cache;
    private string $testKey = 'benchmark_test_key';
    private array $testData;

    public function __construct()
    {
        $this->cache = CacheFactory::create();
        $this->testData = [
            'user_id' => 1,
            'tasks' => array_fill(0, 100, [
                'id' => random_int(1, 1000),
                'title' => 'Test Task ' . uniqid(),
                'description' => 'Benchmark test task description',
                'due_date' => '2025-12-31 23:59:59',
                'priority' => 'medium',
                'status' => 'pending'
            ])
        ];
    }

    /**
     * Benchmark cache SET operations
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCacheSet(): void
    {
        $this->cache->set($this->testKey, $this->testData, 3600);
    }

    /**
     * Benchmark cache GET operations
     */
    #[Bench\Revs(2000)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'seedCache'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCacheGet(): void
    {
        $this->cache->get($this->testKey);
    }

    /**
     * Benchmark cache DELETE operations
     */
    #[Bench\Revs(1000)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'seedCache'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCacheDelete(): void
    {
        $this->cache->delete($this->testKey);
        $this->seedCache(); // Re-seed for next iteration
    }

    /**
     * Benchmark cache EXISTS operations
     */
    #[Bench\Revs(3000)]
    #[Bench\Iterations(5)]
    #[Bench\BeforeMethods(['setUp', 'seedCache'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchCacheExists(): void
    {
        $this->cache->exists($this->testKey);
    }

    public function setUp(): void
    {
        $this->cache->delete($this->testKey);
    }

    public function seedCache(): void
    {
        $this->cache->set($this->testKey, $this->testData, 3600);
    }

    public function tearDown(): void
    {
        $this->cache->delete($this->testKey);
    }
}