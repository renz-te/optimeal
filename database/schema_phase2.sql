USE optimeal;

-- Pricing Rules table
CREATE TABLE IF NOT EXISTS pricing_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    menu_item_id INT NOT NULL UNIQUE,
    rush_hour_end_time TIME NOT NULL,
    decay_start_offset_minutes INT NOT NULL DEFAULT 0,
    decay_rate_percent DECIMAL(5, 2) NOT NULL, -- e.g., 5.00 for 5%
    decay_interval_minutes INT NOT NULL,
    min_stock_to_trigger_decay INT NOT NULL DEFAULT 1,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    store_id INT NOT NULL,
    status ENUM('reserved', 'paid', 'claimed', 'expired', 'cancelled') NOT NULL DEFAULT 'reserved',
    reserved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ttl_expires_at TIMESTAMP NULL,
    claim_token VARCHAR(255) UNIQUE,
    total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    INDEX idx_orders_ttl_expires_at (ttl_expires_at)
);

-- Order Items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price_at_order DECIMAL(10, 2) NOT NULL,
    portion_preference ENUM('no_preference', 'lean_meat', 'bone_fat', 'extra_sauce') NOT NULL DEFAULT 'no_preference',
    best_effort_disclaimer_ack BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL,
    method VARCHAR(50) NOT NULL, -- e.g., 'cash', 'e-wallet'
    status ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

-- Anti-hoarding: Daily Discount Claims
CREATE TABLE IF NOT EXISTS daily_discount_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    claim_date DATE NOT NULL,
    quantity_claimed INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
    UNIQUE INDEX idx_user_item_date (user_id, menu_item_id, claim_date),
    INDEX idx_claims_date (claim_date)
);

-- SEED DATA --

-- Turn off foreign key checks temporarily for a clean reset of seed data
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE daily_discount_claims;
TRUNCATE TABLE payments;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
TRUNCATE TABLE pricing_rules;
TRUNCATE TABLE inventory;
TRUNCATE TABLE menu_item_digestive_sensitivities;
TRUNCATE TABLE menu_item_critical_allergens;
TRUNCATE TABLE digestive_sensitivities;
TRUNCATE TABLE critical_allergens;
TRUNCATE TABLE menu_items;
TRUNCATE TABLE stores;
TRUNCATE TABLE users;
SET FOREIGN_KEY_CHECKS = 1;

-- Seed Users
INSERT INTO users (name, email, password_hash, role) VALUES 
('Admin User', 'admin@optimeal.local', 'hashed_pwd_placeholder', 'admin'),
('Vendor Alice', 'vendor1@optimeal.local', 'hashed_pwd_placeholder', 'vendor'),
('Vendor Bob', 'vendor2@optimeal.local', 'hashed_pwd_placeholder', 'vendor'),
('Student Charlie', 'student1@optimeal.local', 'hashed_pwd_placeholder', 'student'),
('Student Diana', 'student2@optimeal.local', 'hashed_pwd_placeholder', 'student');

-- Seed Stores
INSERT INTO stores (vendor_user_id, name, description) VALUES 
((SELECT id FROM users WHERE email='vendor1@optimeal.local'), 'Alice''s Canteen', 'Serving home-style meals and daily specials.'),
((SELECT id FROM users WHERE email='vendor2@optimeal.local'), 'Bob''s Grill', 'Quick bites, grilled meats, and refreshing drinks.');

-- Seed Allergens & Sensitivities
INSERT INTO critical_allergens (name) VALUES 
('Peanuts'), ('Shellfish'), ('Eggs'), ('Dairy'), ('Tree Nuts');

INSERT INTO digestive_sensitivities (name) VALUES 
('Coconut Milk/Gata'), ('Hot Pepper/Capsaicin'), ('Garlic'), ('Onion');

-- Seed Menu Items
-- Store 1: Alice's Canteen
INSERT INTO menu_items (store_id, name, base_price, price_floor, description, is_active) VALUES 
(1, 'Chicken Adobo', 60.00, 40.00, 'Classic Filipino chicken adobo.', 1),
(1, 'Pork Sinigang', 75.00, 50.00, 'Sour tamarind soup with pork.', 1),
(1, 'Bicol Express', 80.00, 55.00, 'Spicy pork belly cooked in coconut milk.', 1),
(1, 'Tortang Talong', 45.00, 30.00, 'Eggplant omelette.', 1),
(1, 'Peanut Kare-Kare', 90.00, 65.00, 'Beef stew in rich peanut sauce.', 1);

-- Store 2: Bob's Grill
INSERT INTO menu_items (store_id, name, base_price, price_floor, description, is_active) VALUES 
(2, 'Grilled Liempo', 85.00, 60.00, 'Grilled pork belly.', 1),
(2, 'Spicy Sisig', 70.00, 50.00, 'Sizzling minced pork with chilies.', 1),
(2, 'Buttered Shrimp', 95.00, 70.00, 'Garlic butter shrimp.', 1),
(2, 'Chicken Inasal', 80.00, 55.00, 'Grilled chicken marinated in lemongrass.', 1),
(2, 'Lechon Kawali', 100.00, 75.00, 'Crispy deep-fried pork belly.', 1);

-- Map Allergens and Sensitivities
-- 1: Chicken Adobo (none)
-- 2: Pork Sinigang (none)
-- 3: Bicol Express (Coconut Milk, Hot Pepper)
INSERT INTO menu_item_digestive_sensitivities (menu_item_id, sensitivity_id) VALUES 
(3, (SELECT id FROM digestive_sensitivities WHERE name='Coconut Milk/Gata')),
(3, (SELECT id FROM digestive_sensitivities WHERE name='Hot Pepper/Capsaicin'));

-- 4: Tortang Talong (Eggs)
INSERT INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES 
(4, (SELECT id FROM critical_allergens WHERE name='Eggs'));

-- 5: Peanut Kare-Kare (Peanuts)
INSERT INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES 
(5, (SELECT id FROM critical_allergens WHERE name='Peanuts'));

-- 6: Grilled Liempo (none)
-- 7: Spicy Sisig (Hot Pepper)
INSERT INTO menu_item_digestive_sensitivities (menu_item_id, sensitivity_id) VALUES 
(7, (SELECT id FROM digestive_sensitivities WHERE name='Hot Pepper/Capsaicin'));

-- 8: Buttered Shrimp (Shellfish, Dairy)
INSERT INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES 
(8, (SELECT id FROM critical_allergens WHERE name='Shellfish')),
(8, (SELECT id FROM critical_allergens WHERE name='Dairy'));

-- Seed Inventory & Pricing Rules for each menu item
INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold)
SELECT id, 10, 15, 2 FROM menu_items;

INSERT INTO pricing_rules (menu_item_id, rush_hour_end_time, decay_start_offset_minutes, decay_rate_percent, decay_interval_minutes, min_stock_to_trigger_decay)
SELECT id, '13:30:00', 30, 5.00, 15, 3 FROM menu_items;
