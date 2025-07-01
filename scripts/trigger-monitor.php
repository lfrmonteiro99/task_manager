<?php
/**
 * Database Trigger Performance Monitor
 * 
 * This script monitors the performance impact of database triggers
 * by measuring operation times and providing analysis.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class TriggerMonitor
{
    private PDO $pdo;
    
    public function __construct()
    {
        $this->pdo = new PDO(
            "mysql:host=" . getenv('DB_HOST') . ";dbname=" . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    
    public function runAnalysis(): void
    {
        echo " Database Trigger Performance Analysis\n";
        echo "========================================\n\n";
        
        $this->analyzeCurrentTriggers();
        $this->measureOperationTimes();
        $this->providerecommendations();
    }
    
    private function analyzeCurrentTriggers(): void
    {
        echo " Current Database Triggers:\n";
        echo "-----------------------------\n";
        
        $stmt = $this->pdo->query("
            SELECT 
                TRIGGER_NAME,
                EVENT_MANIPULATION,
                ACTION_TIMING,
                CHAR_LENGTH(ACTION_STATEMENT) as trigger_complexity
            FROM information_schema.TRIGGERS 
            WHERE TRIGGER_SCHEMA = DATABASE()
            ORDER BY TRIGGER_NAME
        ");
        
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($triggers)) {
            echo "   No triggers found\n\n";
            return;
        }
        
        foreach ($triggers as $trigger) {
            echo "   {$trigger['TRIGGER_NAME']}\n";
            echo "     Event: {$trigger['EVENT_MANIPULATION']}\n";
            echo "     Timing: {$trigger['ACTION_TIMING']}\n";
            echo "     Complexity: {$trigger['trigger_complexity']} characters\n";
            
            // Analyze complexity
            if ($trigger['trigger_complexity'] < 200) {
                echo "     Impact:  Very Low (simple logic)\n";
            } elseif ($trigger['trigger_complexity'] < 500) {
                echo "     Impact:  Low (moderate logic)\n";
            } elseif ($trigger['trigger_complexity'] < 1000) {
                echo "     Impact:   Medium (complex logic)\n";
            } else {
                echo "     Impact:  High (very complex logic)\n";
            }
            echo "\n";
        }
    }
    
    private function measureOperationTimes(): void
    {
        echo "⏱️  Performance Measurement:\n";
        echo "----------------------------\n";
        
        $testUserId = $this->getOrCreateTestUser();
        
        // Measure INSERT performance
        $this->measureInsertPerformance($testUserId);
        
        // Measure UPDATE performance  
        $this->measureUpdatePerformance($testUserId);
        
        // Clean up
        $this->cleanupTestData($testUserId);
    }
    
    private function getOrCreateTestUser(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute(['trigger-monitor@test.com']);
        $userId = $stmt->fetchColumn();
        
        if (!$userId) {
            $this->pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)")
                     ->execute(['Trigger Monitor', 'trigger-monitor@test.com', 'test']);
            $userId = $this->pdo->lastInsertId();
        }
        
        return (int)$userId;
    }
    
    private function measureInsertPerformance(int $userId): void
    {
        echo " INSERT Operation Performance:\n";
        
        $iterations = 100;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            $this->pdo->prepare("
                INSERT INTO tasks (user_id, title, description, due_date, priority) 
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $userId,
                "Performance Test Task $i",
                "Performance testing description",
                date('Y-m-d H:i:s', strtotime('+1 day')),
                'medium'
            ]);
            
            $times[] = (microtime(true) - $start) * 1000; // Convert to milliseconds
        }
        
        $this->displayPerformanceStats('INSERT', $times);
    }
    
    private function measureUpdatePerformance(int $userId): void
    {
        echo "\n UPDATE Operation Performance:\n";
        
        // Get some task IDs
        $stmt = $this->pdo->prepare("SELECT id FROM tasks WHERE user_id = ? LIMIT 50");
        $stmt->execute([$userId]);
        $taskIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($taskIds)) {
            echo "    No tasks available for UPDATE testing\n";
            return;
        }
        
        $times = [];
        $iterations = 100;
        
        for ($i = 0; $i < $iterations; $i++) {
            $taskId = $taskIds[$i % count($taskIds)];
            $newStatus = ($i % 2 === 0) ? 1 : 0;
            
            $start = microtime(true);
            
            $this->pdo->prepare("UPDATE tasks SET done = ? WHERE id = ?")
                     ->execute([$newStatus, $taskId]);
            
            $times[] = (microtime(true) - $start) * 1000; // Convert to milliseconds
        }
        
        $this->displayPerformanceStats('UPDATE', $times);
    }
    
    private function displayPerformanceStats(string $operation, array $times): void
    {
        $min = min($times);
        $max = max($times);
        $avg = array_sum($times) / count($times);
        $median = $this->calculateMedian($times);
        
        // Calculate 95th percentile
        sort($times);
        $percentile95Index = (int) floor(0.95 * count($times));
        $percentile95 = $times[$percentile95Index];
        
        echo "  Average time: " . number_format($avg, 3) . " ms\n";
        echo "  Median time:  " . number_format($median, 3) . " ms\n";
        echo "  Min time:     " . number_format($min, 3) . " ms\n";
        echo "  Max time:     " . number_format($max, 3) . " ms\n";
        echo "  95th %ile:    " . number_format($percentile95, 3) . " ms\n";
        
        // Performance assessment
        if ($avg < 1) {
            echo "  Assessment:    Excellent (< 1ms average)\n";
        } elseif ($avg < 5) {
            echo "  Assessment:    Good (< 5ms average)\n";
        } elseif ($avg < 10) {
            echo "  Assessment:     Acceptable (< 10ms average)\n";
        } else {
            echo "  Assessment:    Needs optimization (> 10ms average)\n";
        }
    }
    
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }
        
        return $values[$middle];
    }
    
    private function providerecommendations(): void
    {
        echo "\n Performance Recommendations:\n";
        echo "-------------------------------\n";
        
        // Analyze trigger complexity
        $stmt = $this->pdo->query("
            SELECT COUNT(*) as trigger_count,
                   AVG(CHAR_LENGTH(ACTION_STATEMENT)) as avg_complexity
            FROM information_schema.TRIGGERS 
            WHERE TRIGGER_SCHEMA = DATABASE()
        ");
        
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($stats['trigger_count'] == 0) {
            echo "   No triggers detected - consider adding for data consistency\n";
            return;
        }
        
        echo "   Trigger Analysis:\n";
        echo "     - Number of triggers: {$stats['trigger_count']}\n";
        echo "     - Average complexity: " . number_format($stats['avg_complexity'], 0) . " characters\n\n";
        
        if ($stats['avg_complexity'] < 300) {
            echo "   RECOMMENDATION: Keep current triggers\n";
            echo "     - Simple logic with minimal performance impact\n";
            echo "     - Benefits (data consistency) outweigh costs\n";
            echo "     - Continue monitoring for performance changes\n\n";
        } else {
            echo "    RECOMMENDATION: Consider optimization\n";
            echo "     - Triggers are becoming complex\n";
            echo "     - Consider moving logic to application layer\n";
            echo "     - Monitor performance under high load\n\n";
        }
        
        echo "   Best Practices:\n";
        echo "     1. Keep trigger logic simple and fast\n";
        echo "     2. Avoid database queries within triggers\n";
        echo "     3. Use BEFORE triggers when possible (less I/O)\n";
        echo "     4. Monitor performance regularly\n";
        echo "     5. Consider application-level logic for complex business rules\n\n";
        
        echo "   Monitoring Tips:\n";
        echo "     - Set up alerts for slow INSERT/UPDATE operations\n";
        echo "     - Use MySQL Performance Schema for detailed analysis\n";
        echo "     - Monitor trigger execution times in production\n";
        echo "     - Consider load testing with realistic data volumes\n";
    }
    
    private function cleanupTestData(int $userId): void
    {
        $this->pdo->prepare("DELETE FROM tasks WHERE user_id = ?")->execute([$userId]);
        $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
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
    $monitor = new TriggerMonitor();
    $monitor->runAnalysis();
} catch (Exception $e) {
    echo " Analysis failed: " . $e->getMessage() . "\n";
    exit(1);
}