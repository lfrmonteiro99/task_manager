<?php

declare(strict_types=1);

namespace Benchmarks;

use App\Models\Database;
use PhpBench\Attributes as Bench;

/**
 * Benchmark tests for database performance
 */
class DatabaseBench
{
    private Database $database;

    public function __construct()
    {
        $this->database = new Database();
    }

    /**
     * Benchmark simple SELECT query performance
     */
    #[Bench\Revs(50)]
    #[Bench\Iterations(3)]
    public function benchSimpleSelect(): void
    {
        $stmt = $this->database->getConnection()->prepare("SELECT COUNT(*) FROM tasks");
        $stmt->execute();
        $stmt->fetch();
    }

    /**
     * Benchmark user tasks query performance (using view)
     */
    #[Bench\Revs(25)]
    #[Bench\Iterations(3)]
    public function benchUserTasksView(): void
    {
        $stmt = $this->database->getConnection()->prepare(
            "SELECT * FROM user_active_tasks WHERE user_id = ? LIMIT 10"
        );
        $stmt->execute([1]);
        $stmt->fetchAll();
    }

    /**
     * Benchmark statistics view performance
     */
    #[Bench\Revs(15)]
    #[Bench\Iterations(3)]
    public function benchStatisticsView(): void
    {
        $stmt = $this->database->getConnection()->prepare(
            "SELECT * FROM user_task_statistics WHERE user_id = ?"
        );
        $stmt->execute([1]);
        $stmt->fetch();
    }

    /**
     * Benchmark connection pool performance
     */
    #[Bench\Revs(100)]
    #[Bench\Iterations(3)]
    public function benchConnectionPool(): void
    {
        $connection = $this->database->getConnection();
        // Just getting connection to test pool performance
        $connection->getAttribute(\PDO::ATTR_CONNECTION_STATUS);
    }
}