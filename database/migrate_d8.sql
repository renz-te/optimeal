DELIMITER //
CREATE PROCEDURE run_d8_migrations()
BEGIN
    -- A2: Add ingredients_text column to menu_items to store dish-level ingredients
    IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'menu_items' AND TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'ingredients_text') THEN
        ALTER TABLE menu_items ADD COLUMN ingredients_text TEXT NULL;
    END IF;
END //
DELIMITER ;
CALL run_d8_migrations();
DROP PROCEDURE run_d8_migrations;
