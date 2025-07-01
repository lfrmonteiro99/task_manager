<?php

declare(strict_types=1);

namespace Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmark tests for common string operations
 */
class StringBench
{
    private array $testStrings;
    private string $testEmail = 'user@example.com';
    private string $testJson = '{"name":"John","email":"john@example.com","tasks":[{"id":1,"title":"Test"}]}';

    public function __construct()
    {
        $this->testStrings = [
            'short' => 'Hello World',
            'medium' => str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 10),
            'long' => str_repeat('A very long string with lots of content to test performance. ', 100)
        ];
    }

    /**
     * Benchmark email validation performance
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchEmailValidation(): void
    {
        filter_var($this->testEmail, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Benchmark JSON encoding performance
     */
    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    public function benchJsonEncode(): void
    {
        json_encode([
            'user_id' => 123,
            'name' => 'John Doe',
            'email' => $this->testEmail,
            'tasks' => array_fill(0, 10, ['id' => 1, 'title' => 'Test Task'])
        ]);
    }

    /**
     * Benchmark JSON decoding performance
     */
    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    public function benchJsonDecode(): void
    {
        json_decode($this->testJson, true);
    }

    /**
     * Benchmark string hashing performance (bcrypt simulation)
     */
    #[Bench\Revs(10)]
    #[Bench\Iterations(5)]
    public function benchPasswordHashing(): void
    {
        password_hash('test_password_123', PASSWORD_DEFAULT);
    }

    /**
     * Benchmark string concatenation vs sprintf
     */
    #[Bench\Revs(20000)]
    #[Bench\Iterations(5)]
    public function benchStringConcatenation(): void
    {
        $result = 'User: ' . $this->testEmail . ' has ' . 5 . ' tasks';
    }

    /**
     * Benchmark sprintf formatting
     */
    #[Bench\Revs(20000)]
    #[Bench\Iterations(5)]
    public function benchSprintfFormatting(): void
    {
        $result = sprintf('User: %s has %d tasks', $this->testEmail, 5);
    }

    /**
     * Benchmark regular expression matching
     */
    #[Bench\Revs(5000)]
    #[Bench\Iterations(5)]
    public function benchRegexMatching(): void
    {
        preg_match('/^\w+@[a-zA-Z_]+?\.[a-zA-Z]{2,3}$/', $this->testEmail);
    }

    /**
     * Benchmark string sanitization (HTML entities)
     */
    #[Bench\Revs(10000)]
    #[Bench\Iterations(5)]
    public function benchHtmlSanitization(): void
    {
        htmlspecialchars('<script>alert("XSS")</script>Hello & World', ENT_QUOTES, 'UTF-8');
    }
}