<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\Database;

echo " Task Manager Database Migration\n";
echo "=================================\n\n";

try {
    $config = require __DIR__ . '/config/database.php';
    
    // Connect to MySQL without specifying database first
    $pdo = new PDO(
        "mysql:host={$config['host']}",
        $config['username'],
        $config['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo " Connected to MySQL server\n";
    
    // Read and execute migration script
    $migrationSql = file_get_contents(__DIR__ . '/sql/init.sql');
    if (!$migrationSql) {
        throw new Exception('Could not read migration file');
    }
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $migrationSql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            echo " Executing: " . substr($statement, 0, 50) . "...\n";
            $pdo->exec($statement);
        }
    }
    
    echo "\n Database migration completed successfully!\n";
    echo " Created:\n";
    echo "   - task_manager database\n";
    echo "   - task_manager_test database\n";
    echo "   - users table with authentication fields\n";
    echo "   - tasks table with user_id foreign key\n";
    echo "   - Proper indexes for performance\n\n";
    
    // Test database connection
    $database = new Database($config['host'], $config['dbname'], $config['username'], $config['password']);
    $testConnection = $database->getConnection();
    
    echo " Database connection test successful\n";
    echo " Your Task Manager API is ready with authentication!\n\n";
    
    echo " Next steps:\n";
    echo "1. Register a new user: POST /auth/register\n";
    echo "2. Login to get JWT token: POST /auth/login\n";
    echo "3. Use Bearer token for all task operations\n";
    echo "4. Each user will have their own isolated tasks\n\n";
    
} catch (Exception $e) {
    echo " Migration failed: " . $e->getMessage() . "\n";
    echo "Please check your database configuration and try again.\n";
    exit(1);
}