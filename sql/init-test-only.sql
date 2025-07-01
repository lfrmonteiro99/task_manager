-- =====================================================
-- TASK MANAGER API - OPTIMIZED DATABASE SETUP
-- =====================================================
-- This script sets up the complete database schema with performance optimizations
-- Consolidated from init.sql + performance-optimizations.sql

SET SESSION default_storage_engine = 'InnoDB';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- =====================================================
-- DATABASE CREATION
-- =====================================================

CREATE DATABASE IF NOT EXISTS task_manager
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

CREATE DATABASE IF NOT EXISTS task_manager_test
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE task_manager_test;

-- =====================================================
-- USERS TABLE - OPTIMIZED
-- =====================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    
    -- Optimized index for JWT authentication (email + id lookup)
    INDEX `idx_auth_lookup` (`email`, `id`),
    
    -- Index for user activity monitoring and admin queries
    INDEX `idx_user_activity` (`created_at`, `updated_at`)
    
) ENGINE=InnoDB 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci
  COMMENT='User accounts with optimized authentication indexes';

-- =====================================================
-- TASKS TABLE - HIGHLY OPTIMIZED
-- =====================================================

CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,              -- Increased from 50 for flexibility
    `description` TEXT NOT NULL,
    `due_date` DATETIME NOT NULL,
    `done` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('pending', 'completed', 'overdue', 'cancelled') NOT NULL DEFAULT 'pending',
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (`id`),
    
    -- Foreign key constraint
    CONSTRAINT `fk_tasks_user_id` 
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE,
    
    -- =====================================================
    -- COMPOUND INDEXES FOR OPTIMAL QUERY PERFORMANCE
    -- =====================================================
    
    -- Most frequent query: List user's tasks ordered by due date
    INDEX `idx_user_tasks_list` (`user_id`, `due_date`, `done`, `status`),
    
    -- Overdue tasks query (critical performance)
    INDEX `idx_user_overdue` (`user_id`, `done`, `due_date`) 
        COMMENT 'Optimized for overdue task queries',
    
    -- Task completion and updates
    INDEX `idx_user_task_ops` (`user_id`, `id`, `done`, `status`),
    
    -- Statistics and reporting queries
    INDEX `idx_user_stats` (`user_id`, `status`, `done`, `priority`),
    
    -- Priority filtering with status
    INDEX `idx_user_priority_filter` (`user_id`, `priority`, `status`, `due_date`),
    
    -- Date range queries for dashboard views
    INDEX `idx_user_date_range` (`user_id`, `due_date`, `status`)
    
) ENGINE=InnoDB 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci
  COMMENT='Tasks table with compound indexes for multi-user performance';

-- =====================================================
-- SAMPLE DATA FOR TESTING (OPTIONAL)
-- =====================================================

-- Insert default admin user for testing
INSERT IGNORE INTO `users` (`id`, `email`, `password_hash`, `name`) VALUES
(1, 'admin@taskmanager.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin User'),
(2, 'test@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Test User');

-- Sample tasks for demonstration
INSERT IGNORE INTO `tasks` (`id`, `user_id`, `title`, `description`, `due_date`) VALUES
(1, 1, 'Setup Production Environment', 'Configure production servers and deploy application', '2025-12-31 23:59:59'),
(2, 1, 'Implement Caching Layer', 'Add Redis caching for improved performance', '2025-07-15 17:00:00'),
(3, 2, 'Write API Documentation', 'Create comprehensive API documentation', '2025-08-01 12:00:00');

-- =====================================================
-- PERFORMANCE VIEWS FOR COMMON QUERIES
-- =====================================================

-- Active tasks view (excludes completed and cancelled)
CREATE OR REPLACE VIEW `user_active_tasks` AS
SELECT 
    t.ID,
    t.user_id,
    t.title,
    t.description,
    t.due_date,
    t.Status,
    t.Priority,
    t.created_at,
    t.updated_at,
    CASE 
        WHEN t.due_date < NOW() THEN 'overdue'
        WHEN t.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
        WHEN t.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY) THEN 'due_this_week'
        ELSE 'normal'
    END as urgency_status,
    TIMESTAMPDIFF(HOUR, NOW(), t.due_date) as hours_remaining
FROM tasks t
WHERE t.Done = 0 AND t.Status IN ('pending', 'overdue')
ORDER BY t.due_date ASC;

-- Comprehensive user statistics view
CREATE OR REPLACE VIEW `user_task_statistics` AS
SELECT 
    u.id as user_id,
    u.name,
    u.email,
    COALESCE(COUNT(t.ID), 0) as total_tasks,
    COALESCE(SUM(CASE WHEN t.Done = 1 THEN 1 ELSE 0 END), 0) as completed_tasks,
    COALESCE(SUM(CASE WHEN t.Done = 0 AND t.Status != 'cancelled' THEN 1 ELSE 0 END), 0) as active_tasks,
    COALESCE(SUM(CASE WHEN t.Done = 0 AND t.due_date < NOW() THEN 1 ELSE 0 END), 0) as overdue_tasks,
    COALESCE(SUM(CASE WHEN t.Priority = 'urgent' AND t.Done = 0 THEN 1 ELSE 0 END), 0) as urgent_pending,
    COALESCE(SUM(CASE WHEN t.Priority = 'high' AND t.Done = 0 THEN 1 ELSE 0 END), 0) as high_priority_pending,
    -- Performance metrics
    COALESCE(AVG(CASE WHEN t.Done = 1 THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END), 0) as avg_completion_hours,
    COALESCE(ROUND((SUM(CASE WHEN t.Done = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(t.ID), 0)) * 100, 2), 0) as completion_rate_percent,
    -- Recent activity
    MAX(t.updated_at) as last_task_activity,
    COUNT(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as tasks_created_this_week
FROM users u
LEFT JOIN tasks t ON u.id = t.user_id
GROUP BY u.id, u.name, u.email;

-- =====================================================
-- TRIGGERS FOR AUTOMATIC STATUS MANAGEMENT
-- =====================================================

-- Automatically update task status based on due date and completion
CREATE TRIGGER `update_task_status_on_insert` 
BEFORE INSERT ON `tasks`
FOR EACH ROW
BEGIN
    IF NEW.Done = 1 THEN
        SET NEW.Status = 'completed';
    ELSEIF NEW.due_date < NOW() AND NEW.Done = 0 THEN
        SET NEW.Status = 'overdue';
    ELSE
        SET NEW.Status = 'pending';
    END IF;
END;

CREATE TRIGGER `update_task_status_on_update` 
BEFORE UPDATE ON `tasks`
FOR EACH ROW
BEGIN
    IF NEW.Done = 1 AND OLD.Done = 0 THEN
        SET NEW.Status = 'completed';
    ELSEIF NEW.Done = 0 AND NEW.due_date < NOW() THEN
        SET NEW.Status = 'overdue';
    ELSEIF NEW.Done = 0 AND NEW.due_date >= NOW() AND OLD.Status = 'overdue' THEN
        SET NEW.Status = 'pending';
    END IF;
END;

-- =====================================================
-- PERFORMANCE OPTIMIZATION FINALIZATION
-- =====================================================

-- Analyze tables for optimal query execution plans
ANALYZE TABLE `users`;
ANALYZE TABLE `tasks`;

-- =====================================================
-- DATABASE CONFIGURATION RECOMMENDATIONS
-- =====================================================

/*
-- RECOMMENDED MySQL CONFIGURATION SETTINGS:
-- Add these to your my.cnf file for optimal performance:

[mysqld]
# InnoDB optimizations
innodb_buffer_pool_size = 256M          # Adjust based on available RAM
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 1
innodb_file_per_table = 1

# Query optimization
query_cache_type = 1
query_cache_size = 64M
query_cache_limit = 2M

# Connection optimization
max_connections = 200
thread_cache_size = 16

# Slow query logging for monitoring
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.5
log_queries_not_using_indexes = 1
*/

-- =====================================================
-- INDEX USAGE MONITORING QUERIES
-- =====================================================

/*
-- Run these queries periodically to monitor index performance:

-- Check index cardinality and usage
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    CARDINALITY,
    NON_UNIQUE,
    COMMENT
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = 'task_manager' 
    AND TABLE_NAME IN ('users', 'tasks')
ORDER BY TABLE_NAME, INDEX_NAME;

-- Check for unused indexes (requires MySQL 5.6+)
SELECT 
    object_schema,
    object_name,
    index_name
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = 'task_manager'
    AND index_name IS NOT NULL
    AND count_star = 0
ORDER BY object_name, index_name;

-- Monitor slow queries
SELECT 
    ROUND(last_seen, 0) as last_seen_seconds_ago,
    query_time,
    lock_time,
    rows_sent,
    rows_examined,
    SUBSTR(sql_text, 1, 100) as query_sample
FROM performance_schema.events_statements_history_long
WHERE schema_name = 'task_manager'
    AND query_time > 0.1
ORDER BY last_seen DESC
LIMIT 10;
*/

-- =====================================================
-- MAINTENANCE PROCEDURES
-- =====================================================

-- Procedure to clean up old completed tasks (optional maintenance)
CREATE PROCEDURE `cleanup_old_tasks`(IN days_old INT)
BEGIN
    DECLARE affected_rows INT DEFAULT 0;
    
    DELETE FROM tasks 
    WHERE Done = 1 
        AND Status = 'completed' 
        AND updated_at < DATE_SUB(NOW(), INTERVAL days_old DAY);
    
    GET DIAGNOSTICS affected_rows = ROW_COUNT;
    SELECT CONCAT('Cleaned up ', affected_rows, ' old completed tasks') as result;
END;

-- Procedure to update overdue task statuses
CREATE PROCEDURE `update_overdue_tasks`()
BEGIN
    DECLARE affected_rows INT DEFAULT 0;
    
    UPDATE tasks 
    SET Status = 'overdue' 
    WHERE Done = 0 
        AND due_date < NOW() 
        AND Status != 'overdue';
    
    GET DIAGNOSTICS affected_rows = ROW_COUNT;
    SELECT CONCAT('Updated ', affected_rows, ' tasks to overdue status') as result;
END;

-- =====================================================
-- SETUP COMPLETE
-- =====================================================

SELECT 'Task Manager database setup completed successfully!' as status,
       'All tables, indexes, views, and triggers created' as details,
       NOW() as completed_at;