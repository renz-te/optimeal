-- OptiMeal Phase B4.1 Migration: Settings, Cafeteria, Notifications & Preferences
-- Idempotent schema alterations and table creations

SET @dbname = DATABASE();

-- ============================================================================
-- 1. SETTINGS TABLE ALTERATIONS
-- ============================================================================

-- Ensure override_closing_time exists
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'override_closing_time');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN override_closing_time TIME NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure override_expires_at exists
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'override_expires_at');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN override_expires_at DATE NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Feature flags
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_reservations');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_reservations BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_paymongo');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_paymongo BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_ai_recipe_generation');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_ai_recipe_generation BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_global_announcements');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_global_announcements BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_decay_pricing');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_decay_pricing BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'enable_reservation_quota_suggestions');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN enable_reservation_quota_suggestions BOOLEAN NOT NULL DEFAULT TRUE;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Announcement columns
ALTER TABLE settings MODIFY COLUMN global_announcement TEXT NULL;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'announcement_severity');
SET @sql = IF(@col = 0, "ALTER TABLE settings ADD COLUMN announcement_severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info';", 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'settings' AND COLUMN_NAME = 'announcement_expires_at');
SET @sql = IF(@col = 0, 'ALTER TABLE settings ADD COLUMN announcement_expires_at TIMESTAMP NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================================
-- 2. CAFETERIA TABLE CREATION / ALTERATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS cafeteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT 'Main Campus Cafeteria',
    tagline VARCHAR(60) NULL,
    contact_email VARCHAR(255) NULL,
    contact_number VARCHAR(20) NULL,
    lunch_cutoff TIME DEFAULT '09:00:00',
    dinner_cutoff TIME DEFAULT '14:00:00',
    pickup_slot_capacity INT DEFAULT 10,
    walkin_buffer_pct INT DEFAULT 20,
    default_quota INT DEFAULT 5,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure columns exist if cafeteria table was already present
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'tagline');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN tagline VARCHAR(60) NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'contact_email');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN contact_email VARCHAR(255) NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'contact_number');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN contact_number VARCHAR(20) NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'lunch_cutoff');
SET @sql = IF(@col = 0, "ALTER TABLE cafeteria ADD COLUMN lunch_cutoff TIME DEFAULT '09:00:00';", 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'dinner_cutoff');
SET @sql = IF(@col = 0, "ALTER TABLE cafeteria ADD COLUMN dinner_cutoff TIME DEFAULT '14:00:00';", 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'pickup_slot_capacity');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN pickup_slot_capacity INT DEFAULT 10;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'walkin_buffer_pct');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN walkin_buffer_pct INT DEFAULT 20;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'cafeteria' AND COLUMN_NAME = 'default_quota');
SET @sql = IF(@col = 0, 'ALTER TABLE cafeteria ADD COLUMN default_quota INT DEFAULT 5;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed default cafeteria record if empty
INSERT IGNORE INTO cafeteria (id, name, tagline, contact_email, contact_number, lunch_cutoff, dinner_cutoff, pickup_slot_capacity, walkin_buffer_pct, default_quota)
VALUES (1, 'Main Campus Cafeteria', 'Nutritious & Affordable Campus Dining', 'canteen@optimeal.test', '09123456789', '09:00:00', '14:00:00', 10, 20, 5);

-- ============================================================================
-- 3. NOTIFICATIONS TABLE ALTERATIONS
-- ============================================================================

-- Add title column
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'title');
SET @sql = IF(@col = 0, "ALTER TABLE notifications ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT 'Notification' AFTER user_id;", 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Expand type ENUM (including 'new_order' to preserve backwards compatibility)
ALTER TABLE notifications 
    MODIFY COLUMN type ENUM(
        'order_status',
        'system_alert',
        'quota_reached',
        'decay_started',
        'low_stock',
        'announcement',
        'new_order'
    ) NOT NULL DEFAULT 'system_alert';

-- ============================================================================
-- 4. USER PREFERENCES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    default_portion VARCHAR(50) NOT NULL DEFAULT 'no_preference',
    notify_quota_hit BOOLEAN NOT NULL DEFAULT TRUE,
    notify_order_status BOOLEAN NOT NULL DEFAULT TRUE,
    notify_decay BOOLEAN NOT NULL DEFAULT TRUE,
    notify_menu_published BOOLEAN NOT NULL DEFAULT TRUE,
    notify_low_stock BOOLEAN NOT NULL DEFAULT TRUE,
    notify_daily_audit BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. ALLERGEN OVERRIDES TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS allergen_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    allergen_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    INDEX idx_allergen_overrides_user (user_id),
    INDEX idx_allergen_overrides_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. DATA SEEDING
-- ============================================================================

-- Ensure settings table has at least one row
INSERT IGNORE INTO settings (id, reservation_ttl, opening_time, closing_time)
VALUES (1, 30, '07:00', '20:00');

-- Seed user_preferences with default values for all existing users in users table
INSERT IGNORE INTO user_preferences (user_id, default_portion, notify_quota_hit, notify_order_status, notify_decay, notify_menu_published, notify_low_stock, notify_daily_audit)
SELECT id, 'no_preference', TRUE, TRUE, TRUE, TRUE, TRUE, TRUE FROM users;
