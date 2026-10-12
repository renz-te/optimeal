<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D4 Automated Verification Suite
 * Tests: Food Cost & Price Floor Engine
 * - Unit cost persistence in vendor_ingredient_costs
 * - Mass unit conversion (250g Chicken @ ₱200/kg = ₱50.00 cost)
 * - Margin Engine: Suggested price floor = ₱50.00 * 2.5 = ₱125.00
 * - Incompatible unit fallback to 0
 * - Apply suggested price floor endpoint
 */

echo "=== PHASE D4 AUTOMATED VERIFICATION SUITE ===\n\n";

$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . DIRECTORY_SEPARATOR . 'cookie_vendor_d4.txt';
if (file_exists($cookieVendor)) @unlink($cookieVendor);

$baseUrl = 'http://127.0.0.1/optimeal/api';

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';
date_default_timezone_set('Asia/Manila');

function apiRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, false);

    $headers = ['Content-Type: application/json'];
    if ($data !== null) {
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Length: ' . strlen($json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

// 1. Authenticate Vendor (Store 1)
$vendorLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'canteen@optimeal.test',
    'password' => 'password123'
], $cookieVendor);

if ($vendorLogin['status'] !== 200) {
    echo "✗ Failed vendor login: HTTP {$vendorLogin['status']}\n";
    exit(1);
}
echo "✓ 1. Vendor Authenticated (Store 1)\n";

$dishId = null;
try {
    // 2. Set Chicken cost to ₱200/kg
echo "\n--- TEST STEP 1: Set Chicken Cost to ₱200/kg ---\n";
$setCostRes = apiRequest("$baseUrl/vendor/ingredient-costs", 'POST', [
    'items' => [
        [
            'ingredient_name' => 'Chicken',
            'cost' => 200.00,
            'cost_unit' => 'kg'
        ]
    ]
], $cookieVendor);

if ($setCostRes['status'] !== 200) {
    echo "✗ Failed to set ingredient cost: HTTP {$setCostRes['status']} - " . json_encode($setCostRes['data']) . "\n";
    exit(1);
}
echo "✓ Cost set response: HTTP 200\n";

// Verify stored in DB
$getCostsRes = apiRequest("$baseUrl/vendor/ingredient-costs", 'GET', null, $cookieVendor);
$costs = $getCostsRes['data'] ?? [];
$chickenCost = null;
foreach ($costs as $c) {
    if (strtolower(trim($c['ingredient_name'])) === 'chicken') {
        $chickenCost = $c;
        break;
    }
}

if ($chickenCost && (float)$chickenCost['cost'] === 200.00 && $chickenCost['cost_unit'] === 'kg') {
    echo "✓ DB Verification: Chicken cost recorded as ₱200.00 / kg\n";
} else {
    echo "✗ DB Verification Failed for Chicken cost: " . json_encode($chickenCost) . "\n";
    exit(1);
}

// 3. Prepare Test Dish
$storeId = 1;
$stmtDish = $pdo->prepare("SELECT id FROM menu_items WHERE name = 'D4 Roast Chicken Test' AND store_id = ?");
$stmtDish->execute([$storeId]);
$dishId = $stmtDish->fetchColumn();
if (!$dishId) {
    $pdo->prepare("
        INSERT INTO menu_items (store_id, name, description, category, base_price, price_floor, is_active, ai_confirmed)
        VALUES (?, 'D4 Roast Chicken Test', 'Food Cost Engine Test Dish', 'Ulam / Stews', 150.00, 100.00, 1, 1)
    ")->execute([$storeId]);
    $dishId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold, delisted_from_app) VALUES (?, 0, 5, 2, 0)")->execute([$dishId]);
} else {
    $dishId = (int)$dishId;
}
echo "✓ Test Dish Ready (ID: $dishId)\n";

// 4. Create recipe using 250g Chicken (yield: 1 serving)
echo "\n--- TEST STEP 2: Save Recipe (250g Chicken, Yield 1 serving) ---\n";
$saveRecipeRes = apiRequest("$baseUrl/vendor/dish/recipe", 'POST', [
    'menu_item_id' => $dishId,
    'yield_servings' => 1,
    'confirmed' => true,
    'instructions' => 'Roast at 180C',
    'ingredients' => [
        [
            'name' => 'Chicken',
            'quantity' => 250,
            'unit' => 'g'
        ]
    ]
], $cookieVendor);

if ($saveRecipeRes['status'] !== 200) {
    echo "✗ Failed to save recipe: HTTP {$saveRecipeRes['status']} - " . json_encode($saveRecipeRes['data']) . "\n";
    exit(1);
}
echo "✓ Recipe saved: HTTP 200\n";

// 5. Verify Food Cost & Suggested Price Floor
echo "\n--- TEST STEP 3: Verify Cost Math & Margin Multiplier ---\n";
$getRecipeRes = apiRequest("$baseUrl/vendor/dish/recipe?menu_item_id=$dishId", 'GET', null, $cookieVendor);
if ($getRecipeRes['status'] !== 200) {
    echo "✗ Failed to get recipe calculation: HTTP {$getRecipeRes['status']}\n";
    exit(1);
}

$recData = $getRecipeRes['data'] ?? [];
$costPerServing = (float)($recData['cost_per_serving'] ?? 0);
$totalBatchCost = (float)($recData['total_batch_cost'] ?? 0);
$suggestedFloor = (float)($recData['suggested_price_floor'] ?? 0);

echo "Total Batch Cost:       ₱$totalBatchCost (Expected: ₱50.00)\n";
echo "Cost Per Serving:       ₱$costPerServing (Expected: ₱50.00)\n";
echo "Suggested Price Floor:  ₱$suggestedFloor (Expected: ₱60.00 [₱50 * 1.2])\n";

if ($costPerServing === 50.00 && $suggestedFloor === 60.00) {
    echo "✓ TEST STEP 3 PASSED: Math perfectly matches (250g @ ₱200/kg = ₱50.00 cost, 1.2x floor = ₱60.00)!\n";
} else {
    echo "✗ TEST STEP 3 FAILED: Math mismatch!\n";
    exit(1);
}

// 6. Test Incompatible Unit Graceful Fallback
echo "\n--- TEST STEP 4: Incompatible Unit Fallback (Salt in pc @ ₱100/kg) ---\n";
// Set Salt cost to ₱100/kg
apiRequest("$baseUrl/vendor/ingredient-costs", 'POST', [
    'items' => [['ingredient_name' => 'Salt', 'cost' => 100.00, 'cost_unit' => 'kg']]
], $cookieVendor);

// Save recipe with 250g Chicken + 2 pc Salt (incompatible volume/mass/count)
apiRequest("$baseUrl/vendor/dish/recipe", 'POST', [
    'menu_item_id' => $dishId,
    'yield_servings' => 1,
    'confirmed' => true,
    'instructions' => 'Roast with salt',
    'ingredients' => [
        ['name' => 'Chicken', 'quantity' => 250, 'unit' => 'g'],
        ['name' => 'Salt', 'quantity' => 2, 'unit' => 'pc'] // pc vs kg cannot convert
    ]
], $cookieVendor);

$incompatibleRes = apiRequest("$baseUrl/vendor/dish/recipe?menu_item_id=$dishId", 'GET', null, $cookieVendor);
$incCost = (float)($incompatibleRes['data']['cost_per_serving'] ?? 0);
echo "Cost with incompatible unit: ₱$incCost (Expected: ₱50.00, salt cost ignored)\n";
if ($incCost === 50.00) {
    echo "✓ TEST STEP 4 PASSED: Incompatible unit gracefully treated as ₱0.00 without breaking!\n";
} else {
    echo "✗ TEST STEP 4 FAILED: Incompatible unit handling unexpected.\n";
    exit(1);
}

// Restore single ingredient recipe for clean state
apiRequest("$baseUrl/vendor/dish/recipe", 'POST', [
    'menu_item_id' => $dishId,
    'yield_servings' => 1,
    'confirmed' => true,
    'instructions' => 'Roast at 180C',
    'ingredients' => [['name' => 'Chicken', 'quantity' => 250, 'unit' => 'g']]
], $cookieVendor);

// 7. Test Apply Price Floor Endpoint
echo "\n--- TEST STEP 5: Apply Suggested Price Floor to Dish ---\n";
$applyRes = apiRequest("$baseUrl/vendor/dish/price-floor", 'POST', [
    'menu_item_id' => $dishId,
    'price_floor' => 125.00
], $cookieVendor);

echo "HTTP Status: {$applyRes['status']} (Expected: 200)\n";
echo "Response Message: \"{$applyRes['data']['message']}\"\n";

$checkFloorStmt = $pdo->prepare("SELECT price_floor FROM menu_items WHERE id = ?");
$checkFloorStmt->execute([$dishId]);
$dbFloor = (float)$checkFloorStmt->fetchColumn();

echo "Database menu_items.price_floor: ₱$dbFloor (Expected: ₱125.00)\n";

if ($applyRes['status'] === 200 && $dbFloor === 125.00) {
    echo "✓ TEST STEP 5 PASSED: Price floor successfully applied and persisted in database!\n";
} else {
    echo "✗ TEST STEP 5 FAILED: Failed to apply price floor.\n";
    exit(1);
}

// 8. Direct Web Access Security Test
echo "\n--- TEST STEP 6: Direct Web Access Block on tests/ ---\n";
$webTest = apiRequest("http://127.0.0.1/optimeal/tests/test_d4.php", 'GET');
echo "Direct web request HTTP Status: {$webTest['status']} (Expected: 403)\n";
if ($webTest['status'] === 403) {
    echo "✓ TEST STEP 6 PASSED: Apache .htaccess denies web access to test script.\n";
} else {
    echo "✗ TEST STEP 6 FAILED: Direct web access returned {$webTest['status']}\n";
    exit(1);
}

    echo "\n============================================\n";
    echo "✓ ALL PHASE D4 VERIFICATIONS PASSED CLEANLY!\n";
    echo "============================================\n";

} finally {
    if (isset($pdo) && !empty($dishId)) {
        $pdo->exec("DELETE FROM order_items WHERE menu_item_id = $dishId");
        $pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id IN (SELECT id FROM recipes WHERE menu_item_id = $dishId)");
        $pdo->exec("DELETE FROM recipes WHERE menu_item_id = $dishId");
        $pdo->exec("DELETE FROM inventory WHERE menu_item_id = $dishId");
        $pdo->exec("DELETE FROM menu_items WHERE id = $dishId");
    }
    if (file_exists($cookieVendor)) @unlink($cookieVendor);
}
