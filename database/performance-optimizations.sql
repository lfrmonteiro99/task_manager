-- Task Manager API Database Performance Optimizations
-- Optimized indexes for multi-user scenarios and high-performance queries

USE task_manager;

-- =====================================================
-- DROP EXISTING SUBOPTIMAL INDEXES
-- =====================================================

-- Remove some redundant single-column indexes that are covered by compound indexes
-- Keep the most frequently used ones
ALTER TABLE `tasks` DROP INDEX IF EXISTS `idx_status`;
ALTER TABLE `tasks` DROP INDEX IF EXISTS `idx_priority`;
ALTER TABLE `tasks` DROP INDEX IF EXISTS `idx_due_date`;
ALTER TABLE `tasks` DROP INDEX IF EXISTS `idx_done`;

-- =====================================================
-- OPTIMIZED COMPOUND INDEXES FOR USER-SCOPED QUERIES
-- =====================================================

-- Primary index for listing user's tasks (most common query)
-- Covers: SELECT * FROM tasks WHERE user_id = ? ORDER BY due_date
ALTER TABLE `tasks` 
ADD INDEX `idx_user_tasks_optimized` (`user_id`, `due_date`, `done`, `status`, `priority`);

-- Index for overdue tasks query (high priority)
-- Covers: SELECT * FROM tasks WHERE user_id = ? AND due_date < NOW() AND Done = 0
ALTER TABLE `tasks` 
ADD INDEX `idx_user_overdue_tasks` (`user_id`, `done`, `due_date`);

-- Index for task statistics queries
-- Covers: COUNT queries grouped by status, priority, done status
ALTER TABLE `tasks` 
ADD INDEX `idx_user_stats` (`user_id`, `status`, `done`, `priority`);

-- Index for task completion queries
-- Covers: UPDATE tasks SET Done = 1 WHERE ID = ? AND user_id = ?
ALTER TABLE `tasks` 
ADD INDEX `idx_user_task_updates` (`user_id`, `id`, `done`);

-- Index for filtering by priority and status
-- Covers: SELECT * FROM tasks WHERE user_id = ? AND Priority = ? AND Status = ?
ALTER TABLE `tasks` 
ADD INDEX `idx_user_priority_status` (`user_id`, `priority`, `status`, `due_date`);

-- Index for date range queries (useful for dashboard views)
-- Covers: SELECT * FROM tasks WHERE user_id = ? AND due_date BETWEEN ? AND ?
ALTER TABLE `tasks` 
ADD INDEX `idx_user_date_range` (`user_id`, `due_date`, `status`, `done`);

-- =====================================================
-- USERS TABLE OPTIMIZATIONS
-- =====================================================

-- Index for JWT authentication queries (user lookup by email)
-- Already exists but let's ensure it's optimized
ALTER TABLE `users` DROP INDEX IF EXISTS `idx_email`;
ALTER TABLE `users` 
ADD INDEX `idx_auth_lookup` (`email`, `id`);

-- Index for user activity monitoring
ALTER TABLE `users` 
ADD INDEX `idx_user_activity` (`id`, `created_at`, `updated_at`);

-- =====================================================
-- ANALYZE TABLES FOR QUERY OPTIMIZATION
-- =====================================================

ANALYZE TABLE `users`;
ANALYZE TABLE `tasks`;

-- =====================================================
-- CREATE VIEWS FOR COMMON QUERIES
-- =====================================================

-- View for active tasks (not done, not cancelled)
CREATE OR REPLACE VIEW `user_active_tasks` AS
SELECT 
    t.ID,
    t.user_id,
    t.title,
    t.description,
    t.due_date,
    t.Status,
    t.Priority,
    t.set_date,
    CASE 
        WHEN t.due_date < NOW() THEN 'overdue'
        WHEN t.due_date <= DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'due_soon'
        ELSE 'normal'
    END as urgency_status
FROM tasks t
WHERE t.Done = 0 AND t.Status != 'cancelled';

-- View for task statistics per user
CREATE OR REPLACE VIEW `user_task_stats` AS
SELECT 
    user_id,
    COUNT(*) as total_tasks,
    SUM(CASE WHEN Done = 1 THEN 1 ELSE 0 END) as completed_tasks,
    SUM(CASE WHEN Done = 0 THEN 1 ELSE 0 END) as pending_tasks,
    SUM(CASE WHEN Done = 0 AND due_date < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
    SUM(CASE WHEN Priority = 'urgent' AND Done = 0 THEN 1 ELSE 0 END) as urgent_pending,
    SUM(CASE WHEN Priority = 'high' AND Done = 0 THEN 1 ELSE 0 END) as high_priority_pending,
    AVG(CASE WHEN Done = 1 THEN TIMESTAMPDIFF(HOUR, set_date, updated_at) ELSE NULL END) as avg_completion_hours
FROM tasks
GROUP BY user_id;

-- =====================================================
-- PERFORMANCE MONITORING QUERIES
-- =====================================================

-- Query to check index usage
-- Run this periodically to ensure indexes are being used
/*
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    CARDINALITY,
    SUB_PART,
    PACKED,
    NON_UNIQUE
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = 'task_manager' 
ORDER BY TABLE_NAME, INDEX_NAME;
*/

-- Query to monitor slow queries (enable slow query log)
/*
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.5; -- Log queries taking more than 0.5 seconds
SET GLOBAL log_queries_not_using_indexes = 'ON';
*/