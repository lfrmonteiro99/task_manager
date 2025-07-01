<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use Exception;

class TestDatabase extends Database
{
    private ?PDO $connection = null;

    public function __construct()
    {
        parent::__construct();
    }

    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            try {
                // Use test database port when running outside Docker
                $host = getenv('DB_TEST_HOST') ?: '127.0.0.1';
                $port = getenv('DB_TEST_PORT') ?: '3307'; // Test DB runs on port 3307
                $dbname = getenv('DB_TEST_NAME') ?: 'task_manager_test';
                $username = getenv('DB_USER') ?: 'taskuser';
                $password = getenv('DB_PASS') ?: 'taskpass';

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $this->connection = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new Exception("Database connection failed: " . $e->getMessage());
            }
        }

        return $this->connection;
    }

    public function createTestTable(): void
    {
        // Create users table first
        $usersSQL = "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_email` (`email`)
        ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        // Create tasks table with proper schema
        $tasksSQL = "CREATE TABLE IF NOT EXISTS `tasks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NOT NULL,
            `due_date` DATETIME NOT NULL,
            `done` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status` ENUM('pending', 'completed', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
            `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_user_tasks_list` (`user_id`, `due_date`, `done`, `status`),
            CONSTRAINT `fk_tasks_user_id` FOREIGN KEY (`user_id`) 
                REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        $this->getConnection()->exec($usersSQL);
        $this->getConnection()->exec($tasksSQL);
    }

    public function cleanTestTable(): void
    {
        $this->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->getConnection()->exec("TRUNCATE TABLE tasks");
        $this->getConnection()->exec("TRUNCATE TABLE users");
        $this->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    public function dropTestTable(): void
    {
        $this->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 0");
        $this->getConnection()->exec("DROP TABLE IF EXISTS tasks");
        $this->getConnection()->exec("DROP TABLE IF EXISTS users");
        $this->getConnection()->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
}
