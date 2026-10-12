DELIMITER //
CREATE PROCEDURE run_d7_migrations()
BEGIN
    -- M3: Add confirmation columns to recipes if they don't exist
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'recipes' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'recipe_confirmed_by') THEN
        ALTER TABLE recipes ADD COLUMN recipe_confirmed_by INT NULL, ADD COLUMN recipe_confirmed_at DATETIME NULL;
    END IF;

    -- H1: Add store_id to ingredient_submissions to allow store-scoped deduplication
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'ingredient_submissions' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'store_id') THEN
        ALTER TABLE ingredient_submissions ADD COLUMN store_id INT NULL;
        ALTER TABLE ingredient_submissions ADD UNIQUE KEY uq_store_raw_name (store_id, raw_name);
    END IF;
END //
DELIMITER ;
CALL run_d7_migrations();
DROP PROCEDURE run_d7_migrations;
