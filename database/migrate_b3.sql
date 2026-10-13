SET @dbname = DATABASE();

-- 1. override_closing_time
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'settings'
      AND COLUMN_NAME = 'override_closing_time'
);
SET @sql = IF(@col_exists = 0, 'ALTER TABLE settings ADD COLUMN override_closing_time TIME NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. override_closing_date
SET @col_exists2 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = 'settings'
      AND COLUMN_NAME = 'override_closing_date'
);
SET @sql2 = IF(@col_exists2 = 0, 'ALTER TABLE settings ADD COLUMN override_closing_date DATE NULL;', 'SELECT 1;');
PREPARE stmt FROM @sql2; EXECUTE stmt; DEALLOCATE PREPARE stmt;