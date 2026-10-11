-- Phase A7: Audit and Cleanup Reference Script for Test Data
-- SAFETY RULE: DO NOT execute DELETE queries automatically. 
-- Review the SELECT statements first to audit test rows across the database.
-- Matching DELETE statements are intentionally commented out.

USE optimeal;

-- 1. Identify test menu items created during automated and manual testing
SELECT id, name, category, base_price, is_active, created_at 
FROM menu_items 
WHERE name LIKE '%Test%' 
   OR name LIKE '%Proxy%' 
   OR name IN ('Burger', 'Talong', 'Adidas', 'Orange');

-- To delete test menu items (cascades to inventory and order_items with restrict check):
-- DELETE FROM menu_items WHERE name LIKE '%Test%' OR name LIKE '%Proxy%' OR name IN ('Burger', 'Talong', 'Adidas', 'Orange');

-- 2. Identify test orders and reservations created during verification
SELECT id, user_id, status, claim_token, total_amount, fulfillment_type, reserved_at 
FROM orders 
WHERE claim_token IN ('7788', '2356', '8535', '2359')
   OR total_amount >= 5000.00;

-- To delete test orders:
-- DELETE FROM orders WHERE claim_token IN ('7788', '2356', '8535', '2359') OR total_amount >= 5000.00;

-- 3. Identify test ingredient submissions from taxonomy queue testing
SELECT id, raw_name, status, resolved_ingredient_id, created_at 
FROM ingredient_submissions 
WHERE raw_name LIKE '%Test%' 
   OR raw_name IN ('Dragon Fruit Syrup', 'Mock Allergen Ingredient');

-- To delete test ingredient submissions:
-- DELETE FROM ingredient_submissions WHERE raw_name LIKE '%Test%' OR raw_name IN ('Dragon Fruit Syrup', 'Mock Allergen Ingredient');

-- 4. Identify test daily discount claims created during test orders
SELECT id, user_id, menu_item_id, claim_date, quantity_claimed 
FROM daily_discount_claims 
WHERE user_id IN (4, 6);

-- To clean up test discount claims:
-- DELETE FROM daily_discount_claims WHERE user_id IN (4, 6);
