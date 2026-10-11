-- database/migrate_d1.sql
-- Idempotent column addition for orders.allergen_override and orders.allergen_conflicts
-- Usage: mysql -u root <your_db> < database/migrate_d1.sql

SET @dbname = DATABASE();
SET @tablename = 'orders';

-- 1. Add allergen_override if not exists
SET @preparedStatement = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE orders ADD COLUMN allergen_override TINYINT(1) NOT NULL DEFAULT 0 AFTER fulfillment_type',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'allergen_override'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Add allergen_conflicts if not exists
SET @preparedStatement = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE orders ADD COLUMN allergen_conflicts TEXT NULL AFTER allergen_override',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = 'allergen_conflicts'
);
PREPARE stmt FROM @preparedStatement;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
