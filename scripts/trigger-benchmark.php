<?php
/**
 * Database Trigger Performance Benchmark
 * 
 * This script measures the performance impact of database triggers
 * by comparing operations with and without triggers.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class TriggerBenchmark
{
    private PDO $pdo;
    private array $results = [];
    
    public function __construct()
    {
        $this->pdo = new PDO(
            "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function runBenchmark(): void
    {
        echo " Database Trigger Performance Benchmark\n";
        echo "=========================================\n\n";
        
        $this->setupTestData();
        
        echo " Running benchmarks...\n\n";
        
        // Test INSERT operations
        $this->benchmarkInserts();
        
        // Test UPDATE operations  
        $this->benchmarkUpdates();
        
        // Show results
        $this->displayResults();
        
        $this->cleanup();
    }
    
    private function setupTestData(): void
    {
        echo " Setting up test data...\n";
        
        // Create a test user
        $this->pdo->exec("
            INSERT IGNORE INTO users (name, email, password_hash) 
            VALUES ('Benchmark User', 'benchmark@test.com', 'test')
        ");
        
        // Get user ID
        $stmt = $this->pdo->query("SELECT id FROM users WHERE email = 'benchmark@test.com'");
        $this->testUserId = $stmt->fetchColumn();
        
        echo " Test data ready\n\n";
    }
    
    private function benchmarkInserts(): void
    {
        echo " Benchmarking INSERT operations...\n";
        
        $iterations = 1000;
        
        // Benchmark with triggers (current state)
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->pdo->exec("
                INSERT INTO tasks (user_id, title, description, due_date, priority) 
                VALUES ({$this->testUserId}, 'Benchmark Task $i', 'Test description', 
                        DATE_ADD(NOW(), INTERVAL 1 DAY), 'medium')
            ");
        }
        
        $timeWithTriggers = microtime(true) - $start;
        
        // Clean up for next test
        $this->pdo->exec("DELETE FROM tasks WHERE user_id = {$this->testUserId}");
        
        // Temporarily disable triggers for comparison
        $this->pdo->exec("DROP TRIGGER IF EXISTS update_task_status_on_insert");
        
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->pdo->exec("
                INSERT INTO tasks (user_id, title, description, due_date, priority, status) 
                VALUES ({$this->testUserId}, 'Benchmark Task $i', 'Test description', 
                        DATE_ADD(NOW(), INTERVAL 1 DAY), 'medium', 'pending')
            ");
        }
        
        $timeWithoutTriggers = microtime(true) - $start;
        
        // Restore trigger
        $this->restoreInsertTrigger();
        
        $this->results['insert'] = [
            'with_triggers' => $timeWithTriggers,
            'without_triggers' => $timeWithoutTriggers,
            'iterations' => $iterations
        ];
        
        echo "  With triggers:    " . number_format($timeWithTriggers, 4) . "s\n";
        echo "  Without triggers: " . number_format($timeWithoutTriggers, 4) . "s\n";
        echo "  Overhead:         " . number_format((($timeWithTriggers - $timeWithoutTriggers) / $timeWithoutTriggers) * 100, 2) . "%\n\n";
    }
    
    private function benchmarkUpdates(): void
    {
        echo " Benchmarking UPDATE operations...\n";
        
        // Prepare some tasks to update
        $this->pdo->exec("DELETE FROM tasks WHERE user_id = {$this->testUserId}");
        for ($i = 0; $i < 100; $i++) {
            $this->pdo->exec("
                INSERT INTO tasks (user_id, title, description, due_date, priority) 
                VALUES ({$this->testUserId}, 'Update Test Task $i', 'Test description', 
                        DATE_ADD(NOW(), INTERVAL 1 DAY), 'medium')
            ");
        }
        
        // Get task IDs
        $stmt = $this->pdo->query("SELECT id FROM tasks WHERE user_id = {$this->testUserId}");
        $taskIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $iterations = 500;
        
        // Benchmark with triggers
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $taskId = $taskIds[$i % count($taskIds)];
            $this->pdo->exec("UPDATE tasks SET done = 1 WHERE id = $taskId");
            $this->pdo->exec("UPDATE tasks SET done = 0 WHERE id = $taskId");
        }
        
        $timeWithTriggers = microtime(true) - $start;
        
        // Disable update trigger
        $this->pdo->exec("DROP TRIGGER IF EXISTS update_task_status_on_update");
        
        $start = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $taskId = $taskIds[$i % count($taskIds)];
            $this->pdo->exec("UPDATE tasks SET done = 1, status = 'completed' WHERE id = $taskId");
            $this->pdo->exec("UPDATE tasks SET done = 0, status = 'pending' WHERE id = $taskId");
        }
        
        $timeWithoutTriggers = microtime(true) - $start;
        
        // Restore trigger
        $this->restoreUpdateTrigger();
        
        $this->results['update'] = [
            'with_triggers' => $timeWithTriggers,
            'without_triggers' => $timeWithoutTriggers,
            'iterations' => $iterations
        ];
        
        echo "  With triggers:    " . number_format($timeWithTriggers, 4) . "s\n";
        echo "  Without triggers: " . number_format($timeWithoutTriggers, 4) . "s\n";
        echo "  Overhead:         " . number_format((($timeWithTriggers - $timeWithoutTriggers) / $timeWithoutTriggers) * 100, 2) . "%\n\n";
    }
    
    private function displayResults(): void
    {
        echo " BENCHMARK SUMMARY\n";
        echo "===================\n\n";
        
        foreach ($this->results as $operation => $data) {
            $overhead = (($data['with_triggers'] - $data['without_triggers']) / $data['without_triggers']) * 100;
            $avgWithTriggers = ($data['with_triggers'] / $data['iterations']) * 1000; // ms
            $avgWithoutTriggers = ($data['without_triggers'] / $data['iterations']) * 1000; // ms
            
            echo strtoupper($operation) . " Operations ({$data['iterations']} iterations):\n";
            echo "  Average time per operation:\n";
            echo "    With triggers:    " . number_format($avgWithTriggers, 3) . " ms\n";
            echo "    Without triggers: " . number_format($avgWithoutTriggers, 3) . " ms\n";
            echo "    Overhead:         " . number_format($overhead, 2) . "%\n";
            echo "    Absolute diff:    " . number_format($avgWithTriggers - $avgWithoutTriggers, 3) . " ms\n\n";
        }
        
        echo " PERFORMANCE VERDICT:\n";
        $avgOverhead = array_sum(array_map(function($data) {
            return (($data['with_triggers'] - $data['without_triggers']) / $data['without_triggers']) * 100;
        }, $this->results)) / count($this->results);
        
        if ($avgOverhead < 5) {
            echo " EXCELLENT: Triggers have minimal impact (<5% overhead)\n";
        } elseif ($avgOverhead < 10) {
            echo " GOOD: Triggers have acceptable impact (<10% overhead)\n";
        } elseif ($avgOverhead < 20) {
            echo "  MODERATE: Consider optimization if performance is critical\n";
        } else {
            echo " HIGH: Triggers may need optimization or alternatives\n";
        }
        
        echo "   Average overhead: " . number_format($avgOverhead, 2) . "%\n\n";
        
        echo " RECOMMENDATIONS:\n";
        if ($avgOverhead < 5) {
            echo "   Keep triggers - excellent value/cost ratio\n";
            echo "   Benefits (data consistency) outweigh minimal cost\n";
        } else {
            echo "   Consider application-level status management\n";
            echo "   Monitor performance under production load\n";
        }
    }
    
    private function restoreInsertTrigger(): void
    {
        $this->pdo->exec("
            CREATE TRIGGER `update_task_status_on_insert` 
            BEFORE INSERT ON `tasks`
            FOR EACH ROW
            BEGIN
                IF NEW.done = 1 THEN
                    SET NEW.status = 'completed';
                ELSEIF NEW.due_date < NOW() AND NEW.done = 0 THEN
                    SET NEW.status = 'overdue';
                ELSE
                    SET NEW.status = 'pending';
                END IF;
            END
        ");
    }
    
    private function restoreUpdateTrigger(): void
    {
        $this->pdo->exec("
            CREATE TRIGGER `update_task_status_on_update` 
            BEFORE UPDATE ON `tasks`
            FOR EACH ROW
            BEGIN
                IF NEW.done = 1 AND OLD.done = 0 THEN
                    SET NEW.status = 'completed';
                ELSEIF NEW.done = 0 AND NEW.due_date < NOW() THEN
                    SET NEW.status = 'overdue';
                ELSEIF NEW.done = 0 AND NEW.due_date >= NOW() AND OLD.status = 'overdue' THEN
                    SET NEW.status = 'pending';
                END IF;
            END
        ");
    }
    
    private function cleanup(): void
    {
        echo "🧹 Cleaning up test data...\n";
        $this->pdo->exec("DELETE FROM tasks WHERE user_id = {$this->testUserId}");
        $this->pdo->exec("DELETE FROM users WHERE email = 'benchmark@test.com'");
        echo " Cleanup complete\n";
    }
}

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Set defaults if not set
$_ENV['DB_HOST'] = $_ENV['DB_HOST'] ?? 'localhost';
$_ENV['DB_NAME'] = $_ENV['DB_NAME'] ?? 'task_manager';
$_ENV['DB_USER'] = $_ENV['DB_USER'] ?? 'taskuser';
$_ENV['DB_PASS'] = $_ENV['DB_PASS'] ?? 'taskpass';

try {
    $benchmark = new TriggerBenchmark();
    $benchmark->runBenchmark();
} catch (Exception $e) {
    echo " Benchmark failed: " . $e->getMessage() . "\n";
    exit(1);
}