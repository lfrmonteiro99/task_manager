-- =====================================================
-- TASK MANAGER API - TEST DATABASE SETUP
-- =====================================================

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
    `title` VARCHAR(255) NOT NULL,
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
    
    -- priority filtering with status
    INDEX `idx_user_priority_filter` (`user_id`, `priority`, `status`, `due_date`),
    
    -- Date range queries for dashboard views
    INDEX `idx_user_date_range` (`user_id`, `due_date`, `status`)
    
) ENGINE=InnoDB 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci
  COMMENT='Tasks table with compound indexes for multi-user performance';

-- =====================================================
-- PERFORMANCE VIEWS FOR COMMON QUERIES
-- =====================================================

-- Active tasks view (excludes completed and cancelled)
CREATE OR REPLACE VIEW `user_active_tasks` AS
SELECT 
    t.id,
    t.user_id,
    t.title,
    t.description,
    t.due_date,
    t.status,
    t.priority,
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
WHERE t.done = 0 AND t.status IN ('pending', 'overdue')
ORDER BY t.due_date ASC;

-- Comprehensive user statistics view
CREATE OR REPLACE VIEW `user_task_statistics` AS
SELECT 
    u.id as user_id,
    u.name,
    u.email,
    COALESCE(COUNT(t.id), 0) as total_tasks,
    COALESCE(SUM(CASE WHEN t.done = 1 THEN 1 ELSE 0 END), 0) as completed_tasks,
    COALESCE(SUM(CASE WHEN t.done = 0 AND t.status != 'cancelled' THEN 1 ELSE 0 END), 0) as active_tasks,
    COALESCE(SUM(CASE WHEN t.done = 0 AND t.due_date < NOW() THEN 1 ELSE 0 END), 0) as overdue_tasks,
    COALESCE(SUM(CASE WHEN t.priority = 'urgent' AND t.done = 0 THEN 1 ELSE 0 END), 0) as urgent_pending,
    COALESCE(SUM(CASE WHEN t.priority = 'high' AND t.done = 0 THEN 1 ELSE 0 END), 0) as high_priority_pending,
    -- Performance metrics
    COALESCE(AVG(CASE WHEN t.done = 1 THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at) END), 0) as avg_completion_hours,
    COALESCE(ROUND((SUM(CASE WHEN t.done = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 2), 0) as completion_rate_percent,
    -- Recent activity
    MAX(t.updated_at) as last_task_activity,
    COUNT(CASE WHEN t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as tasks_created_this_week
FROM users u
LEFT JOIN tasks t ON u.id = t.user_id
GROUP BY u.id, u.name, u.email;

-- =====================================================
-- TRIGGERS FOR AUTOMATIC STATUS MANAGEMENT
-- =====================================================

DELIMITER $$

-- Automatically update task status based on due date and completion
CREATE TRIGGER IF NOT EXISTS `update_task_status_on_insert` 
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
END$$

CREATE TRIGGER IF NOT EXISTS `update_task_status_on_update` 
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
END$$

DELIMITER ;

-- =====================================================
-- ANALYZE TABLES FOR QUERY OPTIMIZATION
-- =====================================================

ANALYZE TABLE `users`;
ANALYZE TABLE `tasks`;

SELECT 'Test database setup completed successfully!' as status;