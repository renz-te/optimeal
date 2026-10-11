-- OptiMeal Phase D4 Migration: Vendor Ingredient Costs
-- Idempotent script. Run as:
-- mysql -u root <your_db> < database/migrate_d4.sql

CREATE TABLE IF NOT EXISTS vendor_ingredient_costs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    ingredient_id INT NULL,
    ingredient_name VARCHAR(255) NOT NULL,
    cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    cost_unit VARCHAR(50) NOT NULL DEFAULT 'kg',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE SET NULL,
    UNIQUE KEY uq_store_ingredient (store_id, ingredient_name),
    INDEX idx_store_costs (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
