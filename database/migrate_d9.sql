-- OptiMeal Phase D9 Migration: Ingredient Price History Catalog
-- Idempotent table creation for price adjustments and rollbacks

CREATE TABLE IF NOT EXISTS ingredient_price_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    ingredient_name VARCHAR(255) NOT NULL,
    old_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    new_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    cost_unit VARCHAR(50) NOT NULL DEFAULT 'kg',
    action ENUM('manual', 'bulk', 'rollback') NOT NULL,
    batch_id VARCHAR(64) NULL,
    is_rolled_back TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    INDEX idx_price_history_store (store_id),
    INDEX idx_price_history_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
