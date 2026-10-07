DROP DATABASE IF EXISTS optimeal;
CREATE DATABASE optimeal;
USE optimeal;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'vendor', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Stores table
CREATE TABLE IF NOT EXISTS stores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendor_user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (vendor_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Menu Items table
CREATE TABLE IF NOT EXISTS menu_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    store_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    base_price DECIMAL(10, 2) NOT NULL,
    price_floor DECIMAL(10, 2) NOT NULL,
    description TEXT,
    image_url VARCHAR(255),
    calories INT DEFAULT NULL,
    protein_g INT DEFAULT NULL,
    carbs_g INT DEFAULT NULL,
    fat_g INT DEFAULT NULL,
    sodium_mg INT DEFAULT NULL,
    ai_confirmed BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

-- Ingredients table
CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
);

-- Junction table for Ingredients
CREATE TABLE IF NOT EXISTS menu_item_ingredients (
    menu_item_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    PRIMARY KEY (menu_item_id, ingredient_id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
);

-- Critical Allergens table (immune-mediated, triggers blocking cart modal)
CREATE TABLE IF NOT EXISTS critical_allergens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
);

-- Ingredient to Allergen Mapping table
CREATE TABLE IF NOT EXISTS ingredient_allergen_map (
    ingredient_id INT NOT NULL,
    allergen_id INT NOT NULL,
    PRIMARY KEY (ingredient_id, allergen_id),
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
    FOREIGN KEY (allergen_id) REFERENCES critical_allergens(id) ON DELETE CASCADE
);

-- Digestive Sensitivities table (motility triggers, triggers a soft warning only, not a hard block)
CREATE TABLE IF NOT EXISTS digestive_sensitivities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
);

-- Junction table for Critical Allergens
CREATE TABLE IF NOT EXISTS menu_item_critical_allergens (
    menu_item_id INT NOT NULL,
    allergen_id INT NOT NULL,
    PRIMARY KEY (menu_item_id, allergen_id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (allergen_id) REFERENCES critical_allergens(id) ON DELETE CASCADE
);

-- Junction table for Digestive Sensitivities
CREATE TABLE IF NOT EXISTS menu_item_digestive_sensitivities (
    menu_item_id INT NOT NULL,
    sensitivity_id INT NOT NULL,
    PRIMARY KEY (menu_item_id, sensitivity_id),
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    FOREIGN KEY (sensitivity_id) REFERENCES digestive_sensitivities(id) ON DELETE CASCADE
);

-- Inventory table & dual-channel allocation
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL UNIQUE,
    walkin_pool_qty INT NOT NULL DEFAULT 0,
    online_pool_qty INT NOT NULL DEFAULT 0,
    critical_stock_threshold INT NOT NULL DEFAULT 2,
    delisted_from_app BOOLEAN DEFAULT FALSE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

-- AI Query Cache table
CREATE TABLE IF NOT EXISTS ai_query_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dish_name VARCHAR(255) UNIQUE NOT NULL,
    response_payload TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
