-- database/migrate_d6.sql
-- Idempotent column addition for orders.pickup_time and orders.notification_status
-- Usage: mysql -u root <your_db> < database/migrate_d6.sql

SET @dbname = DATABASE();
SET @tablename = 'orders';

-- 1. Add pickup_time if not exists
SET @preparedStatement = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE orders ADD COLUMN pickup_time VARCHAR(10) NULL AFTER fulfillment_type',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'pickup_time'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add notification_status if not exists
SET @preparedStatement = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE orders ADD COLUMN notification_status ENUM(\'none\', \'ready_sent\') NOT NULL DEFAULT \'none\' AFTER pickup_time',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'notification_status'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
