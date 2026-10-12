# OptiMeal

## Overview
OptiMeal is a cafeteria menu, ordering, and food safety tracking system designed for single-stall campus canteen operations. It provides nutritional estimation, ingredient and allergen mapping, time-decay dynamic pricing, recipe costing, fulfillment scheduling, and daily financial audit reporting.

---

## Setup and Installation

### Prerequisites
- PHP 8.2 or higher with PDO MySQL and cURL extensions enabled.
- MySQL 8.0 or higher (e.g., via Laragon).
- Apache or compatible web server configured to serve `api/` with `.htaccess` rewrite rules enabled.
- Node.js 18+ and npm for frontend development and builds.

### Database Migrations
Migrations must be executed sequentially against the `optimeal` database. Each migration performs the following changes and carries specific re-run safety characteristics:

1. `database/schema.sql`
   - **Adds**: Base database schema including `users`, `stores`, `menu_items`, `ingredients`, and `menu_item_ingredients`.
   - **Re-run Safety**: Not safe to re-run on existing data. Begins with `DROP DATABASE IF EXISTS optimeal; CREATE DATABASE optimeal;` which destroys all existing tables and data.

2. `database/schema_phase2.sql`
   - **Adds**: Core workflow tables (`pricing_rules`, `orders`, `order_items`, `payments`, `critical_allergens`, `digestive_sensitivities`, `ingredient_allergen_map`) and initial seed data.
   - **Re-run Safety**: Not safe to re-run. Contains direct `ALTER TABLE menu_items ADD COLUMN...` statements and raw `INSERT` queries that produce duplicate column and duplicate primary key errors upon subsequent executions.

3. `database/migrate_a7.sql`
   - **Adds**: Columns `tier1_minutes_before_close`, `tier1_discount_percent`, `tier2_minutes_before_close`, `tier2_discount_percent`, `emergency_clearance_active`, and `emergency_activated_at` to the `settings` table.
   - **Re-run Safety**: Not safe to re-run. Uses direct `ALTER TABLE settings ADD COLUMN...` statements that fail if columns already exist.

4. `database/migrate_d1.sql`
   - **Adds**: Columns `allergen_override` (tinyint) and `allergen_conflicts` (text) to the `orders` table.
   - **Re-run Safety**: Safe to re-run. Employs dynamic SQL querying `INFORMATION_SCHEMA.COLUMNS` to conditionally apply `ALTER TABLE` only when columns are missing.

5. `database/migrate_d2.sql`
   - **Adds**: Tables `recipes` and `recipe_ingredients`.
   - **Re-run Safety**: Safe to re-run. Uses `CREATE TABLE IF NOT EXISTS`.

6. `database/migrate_d4.sql`
   - **Adds**: Table `vendor_ingredient_costs` for per-store ingredient pricing.
   - **Re-run Safety**: Safe to re-run. Uses `CREATE TABLE IF NOT EXISTS`.

7. `database/migrate_d6.sql`
   - **Adds**: Columns `pickup_time` (varchar) and `notification_status` (enum) to the `orders` table.
   - **Re-run Safety**: Safe to re-run. Uses dynamic SQL conditional checks against `INFORMATION_SCHEMA.COLUMNS`.

8. `database/migrate_d7.sql`
   - **Adds**: Columns `recipe_confirmed_by` and `recipe_confirmed_at` to `recipes`; adds `store_id` and unique composite key `(store_id, raw_name)` to `ingredient_submissions`.
   - **Re-run Safety**: Safe to re-run. Executes an idempotent stored procedure checking `INFORMATION_SCHEMA.COLUMNS` before applying alterations.

9. `database/migrate_d8.sql`
   - **Adds**: Column `ingredients_text` (TEXT NULL) to `menu_items` for dish-form raw ingredient retention.
   - **Re-run Safety**: Safe to re-run. Employs dynamic SQL querying `INFORMATION_SCHEMA.COLUMNS` to conditionally apply `ALTER TABLE` only when missing.

10. `database/migrate_d9.sql`
   - **Adds**: Table `ingredient_price_history` with columns `id`, `store_id`, `ingredient_name`, `old_cost`, `new_cost`, `cost_unit`, `action` (`ENUM('manual','bulk','rollback')`), `batch_id`, `is_rolled_back`, and `created_at`.
   - **Re-run Safety**: Safe to re-run. Uses `CREATE TABLE IF NOT EXISTS`.

### Environment Configuration
The backend loads configuration from `api/.env`. Note that `.env.example` does not exist in the repository; the configuration file must be created manually or configured directly in `api/.env` with the following keys:

- `GEMINI_API_KEY`: API key issued by Google AI Studio used for menu enrichment and recipe suggestions.
- `GEMINI_MODEL`: Model identifier string (defaults to `gemini-3.5-flash` in `api/config.php` if omitted).

---

## Features (Phases D2 to D8)

### Recipes (Phase D2, D7)
- **Functionality**: Vendors define structured recipes for catalogue items, specifying batch `yield_servings` (bounded between 1 and 1,000), preparation instructions, and ingredient rows.
- **Validation**: Units are restricted to a whitelist (`g`, `kg`, `ml`, `l`, `pc`, `tsp`, `tbsp`, `cup`). Quantity caps are enforced per ingredient entry (`g`/`ml` <= 50,000; `kg`/`l` <= 50; other units <= 500). Saving a recipe requires explicit vendor confirmation (`confirmed: true`), which records `recipe_confirmed_by` (user ID) and `recipe_confirmed_at` (timestamp) in the `recipes` table.
- **Relational Integrity**: Saving a recipe preserves existing mapped ingredients in `menu_item_ingredients`, deleting only links absent from the new submission and inserting newly matched ones via `INSERT IGNORE`.
- **Limits**: AI recipe generation is strictly advisory. The suggestion endpoint accepts only ingredients already assigned to the dish and discards unprompted ingredients. The recipe system never checks whether on-hand pantry ingredients are sufficient to prepare the dish.

### Allergen Preview and Safety Model (Phase D2, D7, D8)
- **Functionality**: When recipes are saved or dishes previewed, ingredients are cross-referenced against `ingredient_allergen_map` to identify critical allergens (e.g., Peanuts, Dairy, Shellfish, Soy, Gluten, Fish, Eggs) and digestive sensitivities.
- **Safety Boundary & Single Source of Truth**: Allergen tags are derived exclusively from the database lookup table and never from AI responses. Helper `syncDishIngredientLinks` unifies dish-form and recipe-form ingredient-allergen mapping into a single authoritative link table.
- **Unmapped Ingredient Defense**: Ingredients not present in the master taxonomy are marked as unmapped (`ingredient_id IS NULL`), appended with the suffix `" (Not in allergen list - review)"` in customer views, and queued into `ingredient_submissions` with store attribution. If a student has declared allergens, any unmapped ingredient triggers a safety restriction (`"Needs Review"`, HTTP 400) during checkout unless an allergen risk override is explicitly submitted.

### Prep Plan (Phase D2, D7)
- **Functionality**: Calculates aggregated ingredient requirements across multiple menu items based on vendor-selected planned servings. Multipliers are determined by `planned_servings / yield_servings`. Compatible units are converted and summed (`g` to `kg`, `ml` to `l`), while non-convertible discrete units (`pc`) remain distinct.
- **Limits**: Planned servings are capped at 1,000 per dish. Dishes without a confirmed recipe are omitted from ingredient mathematics and listed under a `needs_recipe` array in the response. The feature does not track on-hand pantry stock or inventory decrements.

### Reservation and Inventory Protection (Phase D3, D6, D7, D8)
- **Functionality**: Walk-in ordering is the baseline; customer online reservation is optional. Online checkout enforces pessimistic concurrency via `SELECT ... FOR UPDATE` within a database transaction, verifying that `online_pool_qty >= ordered_quantity`.
- **Fulfillment and Pickup Slots**: Take-out orders require selecting a 15-minute pickup slot generated by `generatePickupSlots`. Available slots are bounded between `max(now, opening_time)` and `closing_time`. Pickup times must be valid future slots and cannot exceed the `sell_by` safety timestamp of any item in the order. Updating an existing order's fulfillment type via `handleUpdateFulfillment` is restricted to `reserved` orders and strictly re-validates pickup times against item `sell_by` timestamps.
- **Order Expiry**: Reservation TTL expiration is computed as `max(standard_ttl_time, pickup_slot_time)`, strictly capped at cafeteria `closing_time`. Unclaimed order cleanup runs inside an isolated transaction.
- **Limits**: Cafeteria operating schedules crossing midnight (e.g., opening at 08:00 and closing at 02:00) are unsupported and return an empty slot list.

### Food Cost and Price Floor Engine (Phase D4, D7, D8)
- **Functionality**: Vendors can configure ingredient unit purchase costs in `vendor_ingredient_costs` (e.g., 200 PHP per 1 kg). The system calculates dish cost per serving by matching recipe ingredients against stored costs with mass and volume conversion.
- **Floor Bounding**: The suggested price floor uses `FLOOR_COST_MULTIPLIER = 1.2` (`cost_per_serving * 1.2`, capped at `base_price`). Manual price floor submissions exceeding `base_price` or below zero are rejected with HTTP 422. Decay tier configurations enforce `tier2_discount_percent >= tier1_discount_percent`.
- **Pricing Decay Engine**: Dynamic clearance decay calculations in `PricingEngine::calculateCurrentPrice` enforce `min(base_price, max(price_floor, discounted_price))`. Discounted prices never breach the price floor or exceed the base price.

### Daily Audit Additions (Phase D5, D7)
- **Functionality**: Extends the vendor daily audit with financial metrics for claimed orders:
  - Cost of Goods Sold (COGS): Sum of `cost_per_serving * quantity_claimed` across all claimed orders today.
  - Gross Profit: `total_revenue - total_cogs`.
  - Gross Margin: `(gross_profit / total_revenue) * 100`, defended against division by zero (returns 0.0% when revenue is zero).
  - Unsold stock tracking: Displays retail potential (`unsold_stock_value`) alongside raw food cost value (`waste_actual_cost`).
- **Limits**: If any sold dish lacks a complete recipe cost calculation, the audit reports `is_partial_cost: true` with a formatted `cost_coverage` string (e.g., `"3 of 4 ingredients costed"`), and the frontend displays a `(Partial)` label next to COGS and Gross Margin metrics.

### Order Ready Notification (Phase D6, D7)
- **Functionality**: Vendors mark orders as ready via `POST /api/vendor/orders/notify-ready`, updating `orders.notification_status` from `none` to `ready_sent`. The endpoint responds with `"Marked ready - visible in student's order history"`.
- **Limits**: This is a database status flag reflected in student UI order queries. No push notifications, WebPush service workers, APNs, FCM, SMS, or email dispatches exist.

### Backend Hygiene and Polish (Phase D8)
- **Store Resolution**: `requireStaff` resolves active stores via `stores.vendor_user_id` fallback matching `requireVendor`.
- **Error Shielding**: Intentional business validation errors throw `BusinessException` (emitting HTTP 400/422). Database exceptions and internal runtime errors are caught, logged to PHP `error_log`, and shielded behind generic HTTP 500 JSON responses.
- **Authentication Hardening**: Session IDs are regenerated upon login via `session_regenerate_id(true)`. CLI background scripts enforce `php_sapi_name() === 'cli'`.

### Dish Photos, Kitchen Prep Scaling & Ingredient Price Catalog (Phase D9)
- **Dish Photos (Phase D9.1)**: Secure uploads to `uploads/dishes/` verified with both `finfo` MIME validation and `getimagesize`. Maximum file size is 2MB, files are renamed to random 32-character hex filenames, and script execution is blocked via `uploads/dishes/.htaccess`. Old images are purged upon replacement or dish deletion with strict path traversal prevention. Frontend renders lazy-loaded images with blur placeholders and category fallbacks.
- **Reservations in Lineup (Phase D9.2)**: The daily lineup dynamically computes `reserved_qty` for today's orders with `reserved` and `paid` statuses, grouped chronologically by pickup slot (defaulting to `"Dine-In/Walk-in"`). In the kitchen prep plan, `cook_servings = max(planned_servings, reserved_qty)` dynamically scales ingredient amounts without altering checkout quotas or reservation limits. Displays verbatim warning: `"Reservations exceed plan: cooking X servings"`.
- **Ingredient Price Catalog & Polish (Phase D9.3)**: Raw ingredient costs are decoupled from the recipe editor into a dedicated catalog (`/vendor/prices`). Recipes strictly query catalog costs. Manual edits log `manual` history, and bulk percentage adjustments simulate previews without database mutations. Bulk adjustments generate unique batch IDs and log `bulk` history. Rollbacks restore previous costs, log `rollback`, and enforce double-rollback defense with HTTP 422 (`"This change has already been rolled back."`). Price adjustments calculate suggested price floors (`min(base_price, cost * 1.2)`) and return affected dishes, but never mutate active dish price floors automatically. Verbatim notices: allergen warning `"Some ingredients are not in the allergen list. Students with allergies will be asked to review them before ordering."` and cost concentration note `"<Ingredient Name> is <X>% of batch cost. Check the quantity."`.

---

## AI-Assisted Menu Enrichment & Safety Sandbox

OptiMeal integrates Google Gemini for nutritional data estimation and recipe drafting.

### Vendor Workflow
In the vendor dish editor, clicking "Auto-fill with AI" sends the dish name and portion description to the backend. The backend prompts Gemini to return structured JSON containing estimated macronutrients (calories, protein, carbs, fat) and a proposed ingredient list. The vendor can edit values before saving.

### Sandboxed Allergen Safety Model
To avoid allergen hallucinations:
1. The AI is restricted to estimating nutritional quantities and ingredient strings.
2. The AI is never used to determine allergen safety or dietary compliance.
3. Upon saving, ingredient strings are matched against `ingredients` and mapped via `ingredient_allergen_map`. Unmapped items remain tagged as unmapped and unknown.
4. Dietary conflict detection operates purely on relational database rules and keyword checks.

### Caching and Kill Switch
- **Cache**: Requests are cached in `ai_query_cache` keyed by normalized MD5 hashes. Recipe suggestions use composite dish and ingredient hash keys. Repeated requests bypass the Gemini API entirely.
- **Kill Switch**: If `settings.ai_enabled` is set to 0, AI endpoints immediately reject requests with HTTP 403 and `fallback: true` before checking the cache.
- **Rate Limit Degradation**: If the Gemini API returns HTTP 429 or fails, the backend returns a structured error with `fallback: true`, prompting manual input.

---

## Known Technical Limitations

1. **No Push Delivery**: Notifications are simulated via database state flags (`orders.notification_status = 'ready_sent'`). Students only see status updates by polling or reloading order history.
2. **Single Cafeteria Architecture**: Multi-stall tenant isolation is not implemented. Settings and operational parameters are scoped to a single cafeteria store instance (`id = 1`).
3. **No Raw Pantry Inventory**: The system tracks finished dish stock (`inventory.online_pool_qty`, `walkin_pool_qty`), but does not track or deduct raw ingredient pantry inventory (e.g., kilograms of chicken remaining in storage).
4. **No Seating or Service Periods**: There are no tables, seating capacities, dining room reservations, or defined breakfast/lunch/dinner service periods.
5. **Midnight-Crossing Operating Hours**: Cafeteria hours that span across midnight (e.g., opening at 08:00 and closing at 02:00) are unsupported and return zero pickup slots.
6. **Gemini Free-Tier Rate Limits**: The Gemini free-tier quota is limited to approximately 15 requests per minute and 1,500 requests per day. Exhaustion triggers the manual fallback workflow.
7. **Sanitary and Reheat Compliance**: Food holding temperature and sanitary compliance are physical operations handled outside the software; the application only enforces timestamp expiration (`sell_by`).
8. **COGS Dynamic Catalog Valuation**: Cost of Goods Sold (COGS) in the Daily Audit ledger and recipe costing calculations evaluate against current vendor catalog prices (`vendor_ingredient_costs`), rather than immutable historical pricing snapshots at the exact moment of order placement. Historical price changes are logged in `ingredient_price_history`, but order records do not store point-in-time ingredient unit cost snapshots.

---

## Automated Verification Testing

Verification test scripts are located in the `tests/` directory.

### Test Files
- `tests/test_d1.php`: Timezone lock (+08:00 Asia/Manila), allergen override logging, staff claim confirmation, and daily audit pool checks.
- `tests/test_d2.php`: AI kill switch, cache hits without API calls, recipe saving, allergen preview, and prep plan unit scaling.
- `tests/test_d3.php`: Daily lineup launch synchronization, customer menu filtering, oversell prevention, and delisting.
- `tests/test_d4.php`: Ingredient cost assignment, dish cost calculation, margin multiplier suggested floor, and incompatible unit fallback.
- `tests/test_d5.php`: Daily audit COGS, gross profit, zero-revenue margin division-by-zero defense, and unsold waste cost tracking.
- `tests/test_d6.php`: 15-minute pickup slot generation math, checkout slot validation, vendor queue pickup time visibility, and notify-ready state updates.
- `tests/test_d7.php`: Price floor bounds, handleApplyPriceFloor HTTP 422 validation, structured ingredient costing, recipe confirmation tracking, selective ingredient retention, unmapped ingredient queuing, customer unmapped preview suffix, pickup slot sell-by validation, prep plan unreciped dish omission, and notify ready text.
- `tests/test_d8_1.php`: Single source of truth ingredient-allergen link synchronization, unmapped ingredient checkout safety block, override persistence, and safe customer checkout.
- `tests/test_d8_2.php`: 1.2x cost multiplier floor bounding, floor boundary validation (HTTP 422), decay tier discount validation, closed menu state, and single-store settings isolation.
- `tests/test_d8_3.php`: Backend hygiene: cron CLI guard, session regeneration, exception shielding with BusinessException, fulfillment re-validation against sell-by, cancellation claims restoration, and test cleanup in finally.
- `tests/test_d9.php`: Dish photo upload dual-validation (finfo + getimagesize), 2MB cap, random hex filenames, .htaccess block, and deletion cleanup.
- `tests/test_d9_reservations.php`: Today's reserved/paid order counting, pickup slot chronological grouping, cook_servings prep scaling, and verbatim warning banner checks.
- `tests/test_d9_catalog.php`: Price catalog manual save, bulk adjust preview and apply, history listing, rollback, double-rollback defense (HTTP 422), and price floor sovereignty.

### How to Run Tests
Execute each test from the command line interface:
```bash
php tests/test_d1.php
php tests/test_d2.php
php tests/test_d3.php
php tests/test_d4.php
php tests/test_d5.php
php tests/test_d6.php
php tests/test_d7.php
php tests/test_d8_1.php
php tests/test_d8_2.php
php tests/test_d8_3.php
php tests/test_d9.php
php tests/test_d9_reservations.php
php tests/test_d9_catalog.php
```

### Test Prerequisites
1. Laragon running Apache and MySQL with the `optimeal` database configured.
2. All migrations applied in order (`schema.sql` through `migrate_d9.sql`).
3. Demo user accounts present:
   - Vendor: `canteen@optimeal.test` / `password123`
   - Student: `student@optimeal.test` / `password123`

### Test Suite Rules
- **Direct CLI Execution Only**: Every test file enforces a strict line 2 guard:
  ```php
  if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
  ```
  Direct HTTP access via web browser is rejected.
- **Zero External API Calls**: Tests operate against local databases and pre-seeded cache records; they never dispatch live HTTP requests to the Gemini API.
- **Automated Cleanup**: Tests clean up inserted test rows (orders, inventory, menu items, recipes, cookies) upon completion in `finally` blocks.
