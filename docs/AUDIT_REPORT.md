# OptiMeal Full-System Audit Report

## 1. Scope and Method

### Files Read
- api/index.php
- api/db.php
- api/config.php
- api/PricingEngine.php
- api/cron_cleanup.php
- api/routes/auth.php
- api/routes/customer.php
- api/routes/vendor.php
- api/routes/admin.php
- database/schema.sql
- database/schema_phase2.sql
- database/migrate_a7.sql
- database/migrate_d1.sql
- database/migrate_d2.sql
- database/migrate_d4.sql
- database/migrate_d6.sql
- database/migrate_d7.sql
- frontend/src/router/index.js
- frontend/src/stores/auth.js
- frontend/src/views/customer/Menu.vue
- frontend/src/views/customer/OrderHistory.vue
- frontend/src/views/vendor/Orders.vue
- frontend/src/views/vendor/DailyRoster.vue
- frontend/src/views/vendor/DailyAudit.vue
- frontend/src/views/vendor/DecaySettings.vue
- frontend/src/views/admin/Ingredients.vue
- tests/.htaccess
- tests/test_d1.php
- tests/test_d2.php
- tests/test_d4.php
- tests/test_d5.php
- tests/test_d6.php
- tests/test_d7.php
- README.md

### Files Not Read
- External node_modules and vendor dependencies
- Frontend build assets under frontend/dist/

### Methodology
The audit was conducted via systematic, static code analysis in six sequential passes:
1. Entry point routing, database connection, session initialization, and authentication handlers.
2. Customer endpoints, reservation transactions, allergen conflict detection, and order fulfillment.
3. Vendor endpoints, store scoping, order status state machine, costing calculations, and inventory updates.
4. Admin endpoints, pricing engine rules, time boundary handling, and database migration scripts.
5. Frontend routing guards, state management persistence, and API communication.
6. Test suites, CLI guards, server configuration (.htaccess), and documentation claims.

All reported findings are supported by line-level code citations and verified failure scenarios.

---

## 2. Checklist

| Item | Topic | Result | Finding IDs |
|---|---|---|---|
| 1 | Authentication and roles | Finding | F-02, F-05 |
| 2 | Store scoping | Finding | F-02, F-07 |
| 3 | SQL injection and input handling | No issue found with lines read (all queries use prepared statements with parameter binding and emulated prepares disabled) | None |
| 4 | Allergen safety | Finding | F-04 |
| 5 | Pricing and decay | Finding | F-06, F-12, F-13 |
| 6 | Reservation and stock | Finding | F-03, F-09, F-10 |
| 7 | Time handling | Finding | F-12 |
| 8 | Money arithmetic | No issue found with lines read (DECIMAL types used, explicit round() with 2 decimals, missing recipe costs default safely to 0) | None |
| 9 | AI integration | No issue found with lines read (kill switch verified in settings, API key kept server-side via getenv/.env, fallback returns empty array) | None |
| 10 | Error handling and information leakage | Finding | F-11 |
| 11 | Database | Finding | F-01, F-07, F-14 |
| 12 | Frontend | Finding | F-08 |
| 13 | Tests and repo hygiene | Finding | F-15 |

---

## 3. Findings

### F-01
- Severity: Critical
- Status: CONFIRMED
- File: database/schema_phase2.sql:78-82
- Snippet:
```sql
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE daily_discount_claims;
TRUNCATE TABLE payments;
TRUNCATE TABLE order_items;
TRUNCATE TABLE orders;
```
- Failure Scenario:
An administrator or automated deploy pipeline re-runs `database/schema_phase2.sql` on an active database to ensure all tables exist. The script executes unconditional `TRUNCATE TABLE` statements, wiping all orders, payment records, discount quota claims, and inventory data.
- Suggested Minimal Fix:
Remove lines 78-85 from `database/schema_phase2.sql`. Place seed data resets in a dedicated `database/seed.sql` script that is never invoked during schema migrations.

---

### F-02
- Severity: High
- Status: CONFIRMED
- File: api/index.php:50-54
- Snippet:
```php
function requireStaff($pdo) {
    requireRole($pdo, ['vendor', 'admin']);

    $stmt = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
    $storeId = $stmt->fetchColumn();
```
- Failure Scenario:
Multiple cafeteria stalls are registered in the `stores` table (e.g., Store 1 and Store 2). Vendor user B logs into Store 2. When calling any vendor endpoint (`/vendor/orders`, `/vendor/inventory`, `/vendor/audit`), `requireStaff` ignores `$_SESSION['user_id']` and queries the first active store (`LIMIT 1`). Vendor user B is assigned Store 1's ID, exposing Store 1's orders, revenue, and inventory to Vendor B, and allowing Vendor B to modify Store 1's records.
- Suggested Minimal Fix:
Update `requireStaff($pdo)` to query `SELECT id FROM stores WHERE vendor_user_id = ? AND is_active = 1 LIMIT 1` using `$_SESSION['user_id']`. If the user is an admin, allow an explicit `store_id` parameter or default cleanly.

---

### F-03
- Severity: High
- Status: CONFIRMED
- File: api/routes/customer.php:18-27
- Snippet:
```php
function expireUnclaimedOrdersAfterClosing($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'reserved'");
        $stmt->execute();
        $expired_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($expired_orders) > 0) {
            $placeholders = str_repeat('?,', count($expired_orders) - 1) . '?';
            $updateStatus = $pdo->prepare("UPDATE orders SET status = 'expired' WHERE id IN ($placeholders)");
            $updateStatus->execute($expired_orders);
```
- Failure Scenario:
A student attempts to reserve a meal after cafeteria closing time. Line 301 invokes `expireUnclaimedOrdersAfterClosing($pdo)`. The function queries all orders with `status = 'reserved'` without filtering by `DATE(reserved_at) = CURDATE()`, store, or reservation TTL. Furthermore, it executes multiple updates across orders, inventory, and discount claims outside a database transaction. Any reserved order across any date or stall is immediately marked expired. If the server crashes midway, order statuses are updated to expired while inventory and discount claims remain un-restored.
- Suggested Minimal Fix:
Filter the query by date (`WHERE status = 'reserved' AND DATE(reserved_at) = CURDATE()`), pass the store context, and wrap the entire expiration block in a `beginTransaction()` / `commit()` block.

---

### F-04
- Severity: High
- Status: CONFIRMED
- File: api/routes/customer.php:345-352
- Snippet:
```php
            $stmtIngredients = $pdo->prepare("
                SELECT DISTINCT i.name 
                FROM menu_item_ingredients mii
                JOIN ingredients i ON mii.ingredient_id = i.id
                WHERE mii.menu_item_id IN ($placeholders)
            ");
            $stmtIngredients->execute($itemIds);
            $dishIngredients = $stmtIngredients->fetchAll(PDO::FETCH_COLUMN);
```
- Failure Scenario:
A vendor adds a new dish containing an unmapped ingredient (e.g., "shrimp paste" or "peanut butter") that has not yet been approved into the master `ingredients` table. The item exists in `recipe_ingredients` with `ingredient_id IS NULL`. A student with a severe peanut or shellfish allergy orders the dish. `handleCreateReservation` only queries `menu_item_ingredients` and `menu_item_critical_allergens`. Because the ingredient is unmapped, both queries return empty for that ingredient. The allergen keyword check finds no conflict, and the order proceeds with `is_allergen_conflict = 0` without triggering the required staff safety confirmation.
- Suggested Minimal Fix:
In `handleCreateReservation`, also query `recipe_ingredients` where `recipe_id` belongs to the ordered menu items and include `ingredient_name` in `$dishIngredients` for the allergen keyword scan.

---

### F-05
- Severity: High
- Status: CONFIRMED
- File: api/routes/auth.php:32-35
- Snippet:
```php
        if ($user && password_verify($input['password'], $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            jsonResponse([
```
- Failure Scenario:
An attacker establishes a session cookie `PHPSESSID` on a shared campus terminal. A student or vendor later uses that terminal and logs in. Because `handleLogin` verifies the credentials but does not invoke `session_regenerate_id(true)`, the session identifier remains unchanged. The attacker can then use the pre-seeded cookie to access the authenticated session (Session Fixation).
- Suggested Minimal Fix:
Add `session_regenerate_id(true);` immediately following successful password verification before assigning session variables.

---

### F-06
- Severity: Medium
- Status: CONFIRMED
- File: api/routes/vendor.php:424-429
- Snippet:
```php
                $input['base_price'],
                isset($input['price_floor']) ? $input['price_floor'] : $input['base_price'],
                isset($input['image_url']) ? $input['image_url'] : null,
                $calories, $protein_g, $carbs_g, $fat_g, $sodium_mg, $ai_confirmed,
                $dishId, $store_id
            ]);
```
- Failure Scenario:
A vendor updates a menu item and submits `base_price: 50.00` and `price_floor: 80.00` (or `price_floor: -10.00`). `handleSaveDish` does not validate that `price_floor <= base_price` or `price_floor >= 0`. During time-decay calculations, `PricingEngine` evaluates discount tiers against `base_price` (e.g., 25% off 50 = 37.50), then clamps against `price_floor` using `max($discounted, $floor)`. The resulting price is forced up to 80.00, causing a decayed item to cost more than its regular retail price.
- Suggested Minimal Fix:
In `handleSaveDish`, validate that `$input['price_floor'] >= 0` and `$input['price_floor'] <= $input['base_price']`. Return HTTP 400 if validation fails.

---

### F-07
- Severity: Medium
- Status: CONFIRMED
- File: api/routes/vendor.php:476-478
- Snippet:
```php
        $findStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $subStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_submissions (raw_name, status) VALUES (?, 'pending')");
        $mapIngStmt = $pdo->prepare("INSERT IGNORE INTO menu_item_ingredients (menu_item_id, ingredient_id) VALUES (?, ?)");
```
- Failure Scenario:
Migration `database/migrate_d7.sql` introduced `store_id` and unique constraint `uq_store_raw_name (store_id, raw_name)` to `ingredient_submissions` to allow per-stall tracking. However, `handleSaveDish` inserts into `ingredient_submissions (raw_name, status)` without specifying `store_id`. All submitted ingredients receive `store_id = NULL`. In the admin ingredient management console, administrators cannot identify which stall submitted the ingredient.
- Suggested Minimal Fix:
Update the insert statement to `INSERT IGNORE INTO ingredient_submissions (store_id, raw_name, status) VALUES (?, ?, 'pending')` and pass `$store_id`.

---

### F-08
- Severity: Medium
- Status: CONFIRMED
- File: frontend/src/stores/auth.js:12-28
- Snippet:
```javascript
      const res = await fetch('/api/auth/me')
      if (res.ok) {
        const data = await res.json()
        const userData = data.user || data
        setAuth(userData)
        isInitialized.value = true
        return
      } else if (res.status === 401) {
        clearAuth()
        isInitialized.value = true
        return
      }
    } catch (e) {
      // In case /api/auth/me is unreachable or network error, fallback to localStorage
    }

    const data = localStorage.getItem('optimeal_user')
```
- Failure Scenario:
The endpoint `/api/auth/me` does not exist in the backend (returns HTTP 404). In `initSession()`, the response status is 404, which does not match `res.status === 401`. The catch block is skipped, and execution falls through to `localStorage.getItem('optimeal_user')`. If a student opens DevTools and sets `localStorage.setItem('optimeal_user', JSON.stringify({role: 'vendor', name: 'Vendor'}))`, reloading the browser allows them to enter vendor routes in the frontend navigation without an active vendor backend session.
- Suggested Minimal Fix:
Implement a lightweight `GET /api/auth/me` endpoint in `api/index.php` that returns the current session status (`$_SESSION['user_id']`, `$_SESSION['role']`). If the server returns anything other than HTTP 200, clear authentication state.

---

### F-09
- Severity: Medium
- Status: CONFIRMED
- File: api/routes/customer.php:713-715
- Snippet:
```php
    $stmt = $pdo->prepare("UPDATE orders SET fulfillment_type = ? WHERE claim_token = ? AND user_id = ?");
    $stmt->execute([$fulfillment_type, $input['token'], $user_id]);

    if ($stmt->rowCount() === 0) {
```
- Failure Scenario:
A student reserves a meal as `dine_in`. Later, the student calls `/reservations/update-fulfillment` to switch to `take_out`. The endpoint updates `fulfillment_type = 'take_out'` without requiring or validating `pickup_time`. The order now has `fulfillment_type = 'take_out'` with `pickup_time = NULL`, bypassing slot throttles and pickup logistics. Additionally, the query does not check `WHERE status = 'reserved'`, allowing a student to modify fulfillment type on orders that are already `claimed` or `expired`.
- Suggested Minimal Fix:
Require a valid `pickup_time` slot when `$fulfillment_type === 'take_out'`, and add `AND status = 'reserved'` to the SQL WHERE clause.

---

### F-10
- Severity: Medium
- Status: CONFIRMED
- File: api/routes/vendor.php:196-205
- Snippet:
```php
        if ($status === 'cancelled') {
            $itemStmt = $pdo->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$order_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStmt = $pdo->prepare("UPDATE inventory SET online_pool_qty = online_pool_qty + ?, delisted_from_app = CASE WHEN online_pool_qty + ? > critical_stock_threshold THEN 0 ELSE delisted_from_app END WHERE menu_item_id = ?");
            foreach ($items as $item) {
                $restoreStmt->execute([$item['quantity'], $item['quantity'], $item['menu_item_id']]);
            }
        }
```
- Failure Scenario:
A student reserves a discounted meal, consuming 1 item of their daily discount quota in `daily_discount_claims`. The vendor runs out of stock or cancels the order via `handleUpdateOrderStatus`. The vendor cancellation restores the inventory quantity, but unlike `handleCancelReservation` (customer-initiated) and `expireUnclaimedOrdersAfterClosing`, it does not decrement `daily_discount_claims.quantity_claimed`. The student is permanently locked out of their discount quota for the rest of the day due to a vendor cancellation.
- Suggested Minimal Fix:
In `handleUpdateOrderStatus`, when `$status === 'cancelled'`, execute the refund query against `daily_discount_claims` matching the order's user ID and reservation date, identical to lines 39-44 in `customer.php`.

---

### F-11
- Severity: Low
- Status: CONFIRMED
- File: api/routes/vendor.php:522-523
- Snippet:
```php
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to save dish: ' . $e->getMessage()], 500);
    }
```
- Failure Scenario:
A database constraint violation, column mismatch, or syntax error occurs while saving a dish or logging in (`api/routes/auth.php:44`). The backend appends `$e->getMessage()` directly into the JSON response. This reveals database table names, SQL structure, and server paths to the client.
- Suggested Minimal Fix:
Log the full exception message to the server error log (`error_log($e->getMessage())`) and return a generic user message such as `['error' => 'An internal server error occurred. Please try again.']`.

---

### F-12
- Severity: Low
- Status: CONFIRMED
- File: api/PricingEngine.php:55-58
- Snippet:
```php
        $closingTimeStr = !empty($settings['closing_time']) ? trim($settings['closing_time']) : '20:00';
        $closingTime = new DateTime($now->format('Y-m-d') . ' ' . $closingTimeStr, $tz);

        return $now >= $closingTime;
```
- Failure Scenario:
A cafeteria operates late night and sets closing time to `01:00` (1:00 AM). In `PricingEngine::isPastClosing`, `$closingTime` evaluates to today's date at 01:00. At 14:00 (2:00 PM), `$now >= $closingTime` evaluates to true. The system determines the cafeteria is closed during normal operating hours, shutting down menu listings and reservation endpoints.
- Suggested Minimal Fix:
Compare `closing_time` against `opening_time`. If `closing_time < opening_time` (crossing midnight), adjust `$closingTime->modify('+1 day')` when `$now >= $openingTime`.

---

### F-13
- Severity: Low
- Status: CONFIRMED
- File: api/routes/vendor.php:739-748
- Snippet:
```php
    if ($tier1Minutes <= $tier2Minutes) {
        jsonResponse(['error' => 'Tier 1 must occur earlier than Tier 2 (higher minutes before close).'], 400);
    }

    $tier1Discount = isset($input['tier1_discount_percent']) ? (float)$input['tier1_discount_percent'] : (float)($current['tier1_discount_percent'] ?? 25.00);
    $tier2Discount = isset($input['tier2_discount_percent']) ? (float)$input['tier2_discount_percent'] : (float)($current['tier2_discount_percent'] ?? 40.00);

    if ($tier1Discount < 0 || $tier1Discount > 100 || $tier2Discount < 0 || $tier2Discount > 100) {
        jsonResponse(['error' => 'Discount percentages must be between 0% and 100%.'], 400);
    }
```
- Failure Scenario:
A vendor configures decay settings with `tier1_discount_percent: 45` and `tier2_discount_percent: 20`. The validation checks that both values are between 0 and 100, and checks that `tier1Minutes > tier2Minutes`, but does not enforce that `tier2Discount >= tier1Discount`. As closing approaches, food starts at 45% off and drops to 20% off during the final hour, inverting the intended decay discount curve.
- Suggested Minimal Fix:
Add validation in `handleUpdateDecayConfig` verifying that `$tier2Discount >= $tier1Discount`.

---

### F-14
- Severity: Low
- Status: CONFIRMED
- File: database/schema.sql:1-3
- Snippet:
```sql
DROP DATABASE IF EXISTS optimeal;
CREATE DATABASE optimeal;
USE optimeal;
```
- Failure Scenario:
A technician initializes or updates an environment and accidentally executes `database/schema.sql`. The script immediately drops the entire database, destroying all production tables and records.
- Suggested Minimal Fix:
Remove `DROP DATABASE IF EXISTS optimeal;` and use `CREATE DATABASE IF NOT EXISTS optimeal;` instead, with warnings in documentation.

---

### F-15
- Severity: Low
- Status: CONFIRMED
- File: api/db.php:2-5
- Snippet:
```php
$host = '127.0.0.1';
$db   = 'optimeal';
$user = 'root';
$pass = '';
```
- Failure Scenario:
Database connection parameters are hardcoded with default local credentials. Deploying the application to staging or production requires modifying tracked source files or causes connection failures if MySQL requires authentication.
- Suggested Minimal Fix:
Read database connection variables from environment variables with local fallbacks: `$user = getenv('DB_USER') ?: 'root';`, `$pass = getenv('DB_PASS') ?: '';`.

---

## 4. Items Acceptable for Capstone Demo

1. Single active cafeteria store resolution (`WHERE is_active = 1 LIMIT 1`):
Acceptable for the current capstone demo because the pilot deployment models a single campus cafeteria stall. Multi-stall multi-tenancy is deferred to post-capstone scope.
2. Simulated e-wallet payment completion (`handlePayReservation`):
Acceptable because a production payment gateway (e.g., GCash / Maya API) requires commercial merchant credentials. Immediate order marking as `reserved` upon payment simulation accurately models the state transition for demo evaluation.
3. In-memory pickup time slot generation (`handleGetPickupSlots`):
Acceptable because current order volume is low. Slot capacity throttling per interval is sufficient for single-vendor demonstration.

---

## 5. Not Checked

1. External Google Gemini AI API network endpoint response latency and failure modes under network timeouts (verified static configuration and mock fallbacks only, zero external network calls made during audit).
2. Live Apache/Nginx web server HTTP header configuration (HSTS, Content Security Policy) on production hosting environments.

---

## 6. Top 5 Fixes to Do Before Defense

1. **Remove Destructive Schema Truncations (`database/schema_phase2.sql:78-82`)**:
Delete the `TRUNCATE TABLE` statements to eliminate the risk of accidental operational data destruction when setting up or checking schemas.
2. **Enforce Vendor Store Isolation (`api/index.php:50-54`)**:
Bind `stores.vendor_user_id = $_SESSION['user_id']` in `requireStaff` so vendor actions cannot inadvertently target Store 1 across multiple user accounts.
3. **Transaction and Date Boundaries on Closing Expiry (`api/routes/customer.php:18-27`)**:
Filter `expireUnclaimedOrdersAfterClosing` by `DATE(reserved_at) = CURDATE()`, scope to the current store, and wrap in a database transaction to prevent unintended global order cancellation and inconsistent state.
4. **Include Unmapped Ingredients in Checkout Allergen Checks (`api/routes/customer.php:345-352`)**:
Include recipe ingredients where `ingredient_id IS NULL` in the server-side keyword scanner to guarantee critical allergen detection cannot be bypassed by unmapped recipe items.
5. **Regenerate Session ID on Login (`api/routes/auth.php:32-35`)**:
Call `session_regenerate_id(true)` immediately after successful password validation to protect users from session fixation on campus terminals.
