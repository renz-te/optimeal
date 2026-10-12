-- database/migrate_b3.sql
-- Idempotent column addition for settings.global_announcement

SET @dbname = DATABASE();
SET @tablename = 'settings';

SET @preparedStatement = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE settings ADD COLUMN global_announcement VARCHAR(255) NULL',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'global_announcement'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
