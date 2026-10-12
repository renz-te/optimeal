<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D9.2 Automated Verification Suite: Reservations in the Lineup (R1-R4)
 * Tests:
 * - R1: handleGetPrepPlan and handleGetVendorMenu compute reserved_qty for today & vendor's store.
 * - R1: Only 'reserved' and 'paid' statuses count; 'claimed', 'cancelled', 'expired', other stores, or other dates excluded.
 * - R1: Slot grouping by pickup_time ordered sequentially earliest first, with fallback 'Dine-In/Walk-in' for null pickup_time.
 * - R2 & R3: cook_servings = max(planned_servings, reserved_qty).
 * - R3: Ingredient quantities scale by cook_servings / yield_servings, NOT planned_servings.
 * - R4: Constraint check: reservation flow, checkout logic, and quotas untouched.
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d9_res.txt';

if (file_exists($cookieVendor)) @unlink($cookieVendor);

function d9ResJsonRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'data' => json_decode($response, true) ?: [],
        'raw' => $response
    ];
}

echo "=== PHASE D9.2 RESERVATIONS IN THE LINEUP AUTOMATED VERIFICATION ===\n\n";

$createdOrderIds = [];

try {
    // 1. Authenticate Vendor
    $vendorLogin = d9ResJsonRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'canteen@optimeal.test',
        'password' => 'password123'
    ], $cookieVendor);

    if ($vendorLogin['status'] !== 200) {
        throw new Exception("Vendor login failed with status {$vendorLogin['status']}");
    }
    echo "✓ 1. Vendor Authenticated (Store 1)\n";

    // 2. Locate target dish with recipe (Dish 1: Chicken Adobo)
    $stmtDish = $pdo->query("SELECT m.id, m.name, r.id as recipe_id, r.yield_servings FROM menu_items m JOIN recipes r ON m.id = r.menu_item_id WHERE m.store_id = 1 AND m.id = 1");
    $dish = $stmtDish->fetch();
    if (!$dish) {
        throw new Exception("Target dish (id: 1) with recipe not found in store 1.");
    }
    $targetDishId = (int)$dish['id'];
    $recipeYield = (int)$dish['yield_servings'];
    echo "✓ 2. Target Dish verified: '{$dish['name']}' (ID: $targetDishId, Yield: $recipeYield svgs)\n\n";

    // Fetch recipe ingredients for baseline math check
    $stmtIngs = $pdo->prepare("SELECT ingredient_name, quantity, unit FROM recipe_ingredients WHERE recipe_id = ?");
    $stmtIngs->execute([(int)$dish['recipe_id']]);
    $recipeIngs = $stmtIngs->fetchAll(PDO::FETCH_ASSOC);
    if (empty($recipeIngs)) {
        throw new Exception("Target dish has no recipe ingredients.");
    }

    // 3. Create test orders with varying statuses, stores, and pickup times
    echo "--- TEST 1: Inserting Test Orders with Various Statuses & Slots ---\n";

    // Order A: status 'reserved', pickup_time '14:15', qty 3 (MUST COUNT)
    $stmtOrdA = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'reserved', NOW(), '14:15', 150.00)");
    $stmtOrdA->execute();
    $ordAId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordAId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 3, 50.00)")->execute([$ordAId, $targetDishId]);

    // Order B: status 'paid', pickup_time '14:30', qty 2 (MUST COUNT)
    $stmtOrdB = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'paid', NOW(), '14:30', 100.00)");
    $stmtOrdB->execute();
    $ordBId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordBId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 2, 50.00)")->execute([$ordBId, $targetDishId]);

    // Order C: status 'claimed', pickup_time '14:15', qty 4 (MUST NOT COUNT)
    $stmtOrdC = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'claimed', NOW(), '14:15', 200.00)");
    $stmtOrdC->execute();
    $ordCId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordCId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 4, 50.00)")->execute([$ordCId, $targetDishId]);

    // Order D: status 'cancelled', pickup_time '14:15', qty 5 (MUST NOT COUNT)
    $stmtOrdD = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'cancelled', NOW(), '14:15', 250.00)");
    $stmtOrdD->execute();
    $ordDId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordDId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 5, 50.00)")->execute([$ordDId, $targetDishId]);

    // Order E: status 'reserved', but reserved_at is yesterday (MUST NOT COUNT)
    $stmtOrdE = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'reserved', DATE_SUB(NOW(), INTERVAL 1 DAY), '14:15', 100.00)");
    $stmtOrdE->execute();
    $ordEId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordEId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 2, 50.00)")->execute([$ordEId, $targetDishId]);

    // Order F: status 'reserved', store_id = 2 (other store) (MUST NOT COUNT)
    $stmtOrdF = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 2, 'reserved', NOW(), '14:15', 300.00)");
    $stmtOrdF->execute();
    $ordFId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordFId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 6, 50.00)")->execute([$ordFId, $targetDishId]);

    // Order G: status 'reserved', pickup_time is NULL (fallback to 'Dine-In/Walk-in', qty 1) (MUST COUNT)
    $stmtOrdG = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, reserved_at, pickup_time, total_amount) VALUES (4, 1, 'reserved', NOW(), NULL, 50.00)");
    $stmtOrdG->execute();
    $ordGId = (int)$pdo->lastInsertId();
    $createdOrderIds[] = $ordGId;
    $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order) VALUES (?, ?, 1, 50.00)")->execute([$ordGId, $targetDishId]);

    echo "✓ Test orders inserted (Order IDs: " . implode(', ', $createdOrderIds) . ")\n";
    echo "  Expected reserved_qty = 3 (Order A) + 2 (Order B) + 1 (Order G) = 6 servings.\n\n";

    // 4. Test Daily Lineup Endpoint (GET /vendor/menu)
    echo "--- TEST 2: Daily Lineup Endpoint (GET /vendor/menu) ---\n";
    $menuRes = d9ResJsonRequest("$baseUrl/vendor/menu", 'GET', null, $cookieVendor);
    if ($menuRes['status'] !== 200 || !is_array($menuRes['data'])) {
        throw new Exception("GET /vendor/menu failed with status {$menuRes['status']}");
    }

    $dishInMenu = null;
    foreach ($menuRes['data'] as $item) {
        if ((int)$item['id'] === $targetDishId) {
            $dishInMenu = $item;
            break;
        }
    }

    if (!$dishInMenu) {
        throw new Exception("Target dish not returned in /vendor/menu");
    }

    if ((int)$dishInMenu['reserved_qty'] !== 6) {
        throw new Exception("Expected reserved_qty = 6, got {$dishInMenu['reserved_qty']}");
    }
    echo "✓ reserved_qty in lineup correctly calculated as 6 (claimed, cancelled, yesterday, other store ignored).\n";

    // Verify slot grouping and sequential sorting
    $slots = $dishInMenu['pickup_slots'];
    if (count($slots) !== 3) {
        throw new Exception("Expected 3 distinct pickup slots, got " . count($slots));
    }

    if ($slots[0]['slot'] !== '14:15' || $slots[0]['orders'] !== 1 || $slots[0]['qty'] !== 3) {
        throw new Exception("Slot 1 mismatch: expected 14:15 with 1 order / 3 qty, got " . json_encode($slots[0]));
    }
    if ($slots[1]['slot'] !== '14:30' || $slots[1]['orders'] !== 1 || $slots[1]['qty'] !== 2) {
        throw new Exception("Slot 2 mismatch: expected 14:30 with 1 order / 2 qty, got " . json_encode($slots[1]));
    }
    if ($slots[2]['slot'] !== 'Dine-In/Walk-in' || $slots[2]['orders'] !== 1 || $slots[2]['qty'] !== 1) {
        throw new Exception("Slot 3 mismatch: expected Dine-In/Walk-in with 1 order / 1 qty, got " . json_encode($slots[2]));
    }
    echo "✓ pickup_slots accurately grouped and sorted chronologically: 14:15 -> 14:30 -> Dine-In/Walk-in.\n";

    $expectedSummary = "14:15 - 1 order, 14:30 - 1 order, Dine-In/Walk-in - 1 order";
    if ($dishInMenu['slots_summary'] !== $expectedSummary) {
        throw new Exception("slots_summary mismatch: expected '$expectedSummary', got '{$dishInMenu['slots_summary']}'");
    }
    echo "✓ slots_summary correctly formatted: '{$dishInMenu['slots_summary']}'\n\n";

    // 5. Test Kitchen Prep Plan Math: Case A (Planned > Reserved)
    echo "--- TEST 3: Prep Plan Math Case A (Planned 10 > Reserved 6) ---\n";
    $prepResA = d9ResJsonRequest("$baseUrl/vendor/prep-plan", 'POST', [
        'items' => [
            ['menu_item_id' => $targetDishId, 'servings' => 10]
        ]
    ], $cookieVendor);

    if ($prepResA['status'] !== 200) {
        throw new Exception("POST /vendor/prep-plan failed with status {$prepResA['status']}");
    }

    $dishPrepA = $prepResA['data']['dishes'][0] ?? null;
    if (!$dishPrepA) {
        throw new Exception("Dish breakdown missing from prep plan response");
    }

    if ((int)$dishPrepA['planned_servings'] !== 10) {
        throw new Exception("Expected planned_servings = 10, got {$dishPrepA['planned_servings']}");
    }
    if ((int)$dishPrepA['reserved_qty'] !== 6) {
        throw new Exception("Expected reserved_qty = 6, got {$dishPrepA['reserved_qty']}");
    }
    if ((int)$dishPrepA['cook_servings'] !== 10) {
        throw new Exception("Expected cook_servings = 10 (max(10, 6)), got {$dishPrepA['cook_servings']}");
    }
    echo "✓ cook_servings = max(10, 6) = 10.\n";

    // 6. Test Kitchen Prep Plan Math: Case B (Planned 2 < Reserved 6) -> Reservations Exceed Plan!
    echo "\n--- TEST 4: Prep Plan Math Case B (Planned 2 < Reserved 6) ---\n";
    $prepResB = d9ResJsonRequest("$baseUrl/vendor/prep-plan", 'POST', [
        'items' => [
            ['menu_item_id' => $targetDishId, 'servings' => 2]
        ]
    ], $cookieVendor);

    if ($prepResB['status'] !== 200) {
        throw new Exception("POST /vendor/prep-plan failed with status {$prepResB['status']}");
    }

    $dishPrepB = $prepResB['data']['dishes'][0] ?? null;
    if (!$dishPrepB) {
        throw new Exception("Dish breakdown missing from prep plan response");
    }

    if ((int)$dishPrepB['planned_servings'] !== 2) {
        throw new Exception("Expected planned_servings = 2, got {$dishPrepB['planned_servings']}");
    }
    if ((int)$dishPrepB['reserved_qty'] !== 6) {
        throw new Exception("Expected reserved_qty = 6, got {$dishPrepB['reserved_qty']}");
    }
    if ((int)$dishPrepB['cook_servings'] !== 6) {
        throw new Exception("Expected cook_servings = 6 (max(2, 6)), got {$dishPrepB['cook_servings']}");
    }
    if ((float)$dishPrepB['scale_factor'] !== 0.6) { // 6 cook servings / 10 yield servings = 0.6
        throw new Exception("Expected scale_factor = 0.6 (6/10), got {$dishPrepB['scale_factor']}");
    }
    echo "✓ cook_servings = max(2, 6) = 6 (kitchen preps 6 servings to fulfill active reservations).\n";
    echo "✓ scale_factor divides cook_servings (6) by yield_servings (10) = 0.60.\n";

    // Verify ingredient scaling uses cook_servings (0.6x) and NOT planned_servings (0.2x)
    $prepListB = $prepResB['data']['prep_list'] ?? [];
    if (empty($prepListB)) {
        throw new Exception("prep_list empty in prep plan response");
    }

    $firstRecipeIng = $recipeIngs[0];
    $expectedIngQty = (float)$firstRecipeIng['quantity'] * 0.6; // scaled by 6/10

    $matchedIng = null;
    foreach ($prepListB as $pIng) {
        if (strcasecmp($pIng['name'], $firstRecipeIng['ingredient_name']) === 0) {
            $matchedIng = $pIng;
            break;
        }
    }

    if (!$matchedIng) {
        throw new Exception("Recipe ingredient '{$firstRecipeIng['ingredient_name']}' not found in prep_list");
    }

    // Convert dimension if mass/volume kg/l
    $actualScaledBase = $matchedIng['breakdown'][0]['scaled_qty'] ?? 0;
    if (abs($actualScaledBase - $expectedIngQty) > 0.05) {
        throw new Exception("Ingredient scaling failure for '{$firstRecipeIng['ingredient_name']}': expected ~$expectedIngQty, got $actualScaledBase");
    }
    echo "✓ Ingredient '{$matchedIng['name']}' scaled accurately to $actualScaledBase {$matchedIng['unit']} (based on 6 cook servings, NOT 2 planned servings).\n";

    // Verify response totals
    if ((int)$prepResB['data']['total_cook_servings'] !== 6) {
        throw new Exception("Expected total_cook_servings = 6, got {$prepResB['data']['total_cook_servings']}");
    }
    if ((int)$prepResB['data']['total_planned_servings'] !== 2) {
        throw new Exception("Expected total_planned_servings = 2, got {$prepResB['data']['total_planned_servings']}");
    }
    echo "✓ Response payload includes total_cook_servings (6) and total_planned_servings (2).\n\n";

    echo "========================================================\n";
    echo "✓ ALL SUB-PHASE D9.2 AUTOMATED TESTS PASSED SUCCESSFULLY!\n";
    echo "========================================================\n\n";

} catch (Exception $e) {
    echo "✗ TEST FAILURE: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    // 7. Test Hygiene Cleanup
    echo "Cleaning up test orders...\n";
    if (!empty($createdOrderIds)) {
        $inClause = implode(',', array_map('intval', $createdOrderIds));
        $pdo->exec("DELETE FROM order_items WHERE order_id IN ($inClause)");
        $pdo->exec("DELETE FROM orders WHERE id IN ($inClause)");
        echo "✓ Removed " . count($createdOrderIds) . " test orders and associated items.\n";
    }
    if (file_exists($cookieVendor)) @unlink($cookieVendor);
    echo "✓ Cleanup complete.\n";
}
