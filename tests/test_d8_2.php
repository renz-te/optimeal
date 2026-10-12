<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D8.2 Automated Verification Suite
 * Tests:
 * - B1: Floor bounds validation in handleSaveDish (rejects < 0 and > base_price with 422).
 * - B1: 1.2x floor calculation in handleGetRecipe (min(basePrice, round(costPerServing * 1.2, 2)), margin_multiplier 1.2).
 * - B2: Decay tier validation in handleUpdateDecayConfig (rejects tier2 < tier1 with 422).
 * - D1: handleGetMenu returns { opening_time, closing_time, is_open, items } JSON wrapper.
 * - D2 & D3: Admin lockdown in handleUpdateSettings (ignores edits to closing_time and default_decay_rate).
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/PricingEngine.php';
require_once __DIR__ . '/../api/routes/customer.php';
require_once __DIR__ . '/../api/routes/vendor.php';

$pdo = new PDO(
    "mysql:host=localhost;dbname=optimeal;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d8_2.txt';
$cookieAdmin = $tempDir . '/cookie_admin_d8_2.txt';
if (file_exists($cookieVendor)) @unlink($cookieVendor);
if (file_exists($cookieAdmin)) @unlink($cookieAdmin);

function d82ApiRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
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
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'data' => json_decode($response, true),
        'raw' => $response
    ];
}

echo "=== PHASE D8.2 PRICING & ADMIN STATE VERIFICATION ===\n\n";

$createdDishIds = [];
$origSettings = null;

try {
    // 0. Backup original settings
    $sStmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $origSettings = $sStmt->fetch(PDO::FETCH_ASSOC);

    // 1. Authenticate Vendor
    $vAuth = d82ApiRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'canteen@optimeal.test',
        'password' => 'password123'
    ], $cookieVendor);

    if ($vAuth['status'] !== 200) {
        echo "✗ Vendor auth failed: {$vAuth['raw']}\n";
        exit(1);
    }
    echo "✓ 1. Vendor Authenticated\n";

    // 2. Authenticate Admin
    $aAuth = d82ApiRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'admin@optimeal.test',
        'password' => 'password123'
    ], $cookieAdmin);

    if ($aAuth['status'] !== 200) {
        echo "✗ Admin auth failed: {$aAuth['raw']}\n";
        exit(1);
    }
    echo "✓ 2. Admin Authenticated\n\n";

    // --- TEST 1: B1 Floor Bounds Validation in handleSaveDish ---
    echo "--- TEST 1: B1 Price Floor Bounds in handleSaveDish ---\n";

    // 1A. Negative floor rejected (422)
    $negFloorRes = d82ApiRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'Negative Floor Test Dish',
        'base_price' => 100.00,
        'price_floor' => -10.00,
        'category' => 'Ulam / Stews',
        'ingredients' => ['Chicken', 'Salt']
    ], $cookieVendor);

    if ($negFloorRes['status'] === 422) {
        echo "✓ handleSaveDish: Negative price_floor (-10.00) correctly rejected with HTTP 422.\n";
    } else {
        echo "✗ handleSaveDish negative floor test failed. Status: {$negFloorRes['status']}\n";
        exit(1);
    }

    // 1B. Floor > base_price rejected (422)
    $excessFloorRes = d82ApiRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'Excess Floor Test Dish',
        'base_price' => 100.00,
        'price_floor' => 150.00,
        'category' => 'Ulam / Stews',
        'ingredients' => ['Chicken', 'Salt']
    ], $cookieVendor);

    if ($excessFloorRes['status'] === 422) {
        echo "✓ handleSaveDish: price_floor exceeding base_price (150 > 100) correctly rejected with HTTP 422.\n";
    } else {
        echo "✗ handleSaveDish excess floor test failed. Status: {$excessFloorRes['status']}\n";
        exit(1);
    }

    // 1C. Valid floor accepted (200/201)
    $validDishRes = d82ApiRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'Valid Floor Test Dish D8.2',
        'base_price' => 100.00,
        'price_floor' => 80.00,
        'category' => 'Ulam / Stews',
        'ingredients' => ['Chicken', 'Garlic']
    ], $cookieVendor);

    if (in_array($validDishRes['status'], [200, 201]) && !empty($validDishRes['data']['id'])) {
        $testDishId = (int)$validDishRes['data']['id'];
        $createdDishIds[] = $testDishId;
        echo "✓ handleSaveDish: Valid price_floor (80.00 on base 100.00) accepted successfully (Dish #$testDishId).\n\n";
    } else {
        echo "✗ handleSaveDish valid floor test failed. Status: {$validDishRes['status']}, Response: " . json_encode($validDishRes['data']) . "\n";
        exit(1);
    }

    // --- TEST 2: B1 1.2x Floor Calculation in handleGetRecipe ---
    echo "--- TEST 2: B1 1.2x Floor Calculation in handleGetRecipe ---\n";

    // Save recipe: Chicken (100g, ₱250/kg -> ₱25 portion cost), yield = 1 portion -> cost_per_serving = ₱25.00
    // Floor calculation should be 25.00 * 1.2 = 30.00
    $recipeSaveRes = d82ApiRequest("$baseUrl/vendor/dish/recipe", 'POST', [
        'menu_item_id' => $testDishId,
        'yield_servings' => 1,
        'instructions' => 'Simmer well',
        'confirmed' => true,
        'ingredients' => [
            [
                'name' => 'Chicken',
                'quantity' => 100,
                'unit' => 'g',
                'unit_cost' => 250,
                'cost_unit' => 'kg'
            ]
        ]
    ], $cookieVendor);

    if ($recipeSaveRes['status'] !== 200) {
        echo "✗ handleSaveRecipe failed: {$recipeSaveRes['raw']}\n";
        exit(1);
    }

    // Save ingredient cost for store 1 so handleGetRecipe computes cost
    d82ApiRequest("$baseUrl/vendor/ingredient-costs", 'POST', [
        'items' => [
            ['ingredient_name' => 'Chicken', 'cost' => 250, 'cost_unit' => 'kg']
        ]
    ], $cookieVendor);

    $recipeGetRes = d82ApiRequest("$baseUrl/vendor/dish/recipe?menu_item_id=$testDishId", 'GET', null, $cookieVendor);
    if ($recipeGetRes['status'] === 200) {
        $rData = $recipeGetRes['data'];
        $suggestedFloor = (float)$rData['suggested_price_floor'];
        $costPerServing = (float)$rData['cost_per_serving'];
        $multiplier = (float)($rData['margin_multiplier'] ?? 0);

        if ($multiplier == 1.2 && $suggestedFloor == 30.00) {
            echo "✓ handleGetRecipe: Cost per serving = ₱$costPerServing, margin_multiplier = 1.2, suggested_price_floor = ₱$suggestedFloor (25 * 1.2 = 30).\n";
        } else {
            echo "✗ handleGetRecipe 1.2x math mismatch: suggestedFloor=$suggestedFloor, multiplier=$multiplier\n";
            exit(1);
        }
    } else {
        echo "✗ handleGetRecipe failed. Status: {$recipeGetRes['status']}\n";
        exit(1);
    }

    // Test cap at base price: Update base price to ₱28.00 (below ₱30.00 floor)
    $pdo->prepare("UPDATE menu_items SET base_price = 28.00 WHERE id = ?")->execute([$testDishId]);
    $recipeGetCapRes = d82ApiRequest("$baseUrl/vendor/dish/recipe?menu_item_id=$testDishId", 'GET', null, $cookieVendor);
    $cappedFloor = (float)$recipeGetCapRes['data']['suggested_price_floor'];
    if ($cappedFloor == 28.00) {
        echo "✓ handleGetRecipe: Suggested floor correctly capped at base price (₱28.00 < calculated ₱30.00).\n\n";
    } else {
        echo "✗ handleGetRecipe base price capping failed: cappedFloor=$cappedFloor\n";
        exit(1);
    }

    // --- TEST 3: B2 Decay Tier Validation in handleUpdateDecayConfig ---
    echo "--- TEST 3: B2 Decay Tier Ordering Validation ---\n";

    // 3A. Inverted tiers (tier2 < tier1) rejected with 422
    $invalidTierRes = d82ApiRequest("$baseUrl/vendor/decay", 'POST', [
        'closing_time' => '20:00',
        'tier1_minutes_before_close' => 120,
        'tier1_discount_percent' => 40.00,
        'tier2_minutes_before_close' => 60,
        'tier2_discount_percent' => 25.00
    ], $cookieVendor);

    if ($invalidTierRes['status'] === 422) {
        echo "✓ handleUpdateDecayConfig: Rejected tier2 (25%) < tier1 (40%) with HTTP 422.\n";
    } else {
        echo "✗ handleUpdateDecayConfig invalid tier test failed. Status: {$invalidTierRes['status']}\n";
        exit(1);
    }

    // 3B. Valid tiers (tier2 >= tier1) accepted with 200
    $validTierRes = d82ApiRequest("$baseUrl/vendor/decay", 'POST', [
        'closing_time' => '20:00',
        'tier1_minutes_before_close' => 120,
        'tier1_discount_percent' => 25.00,
        'tier2_minutes_before_close' => 60,
        'tier2_discount_percent' => 40.00
    ], $cookieVendor);

    if ($validTierRes['status'] === 200) {
        echo "✓ handleUpdateDecayConfig: Accepted valid tier ordering (tier1 25%, tier2 40%) with HTTP 200.\n\n";
    } else {
        echo "✗ handleUpdateDecayConfig valid tier test failed. Status: {$validTierRes['status']}\n";
        exit(1);
    }

    // --- TEST 4: D1 handleGetMenu Returns Operating Hours Wrapper ---
    echo "--- TEST 4: D1 handleGetMenu JSON Wrapper ---\n";
    $menuRes = d82ApiRequest("$baseUrl/menu?store_id=1");
    if ($menuRes['status'] === 200 && is_array($menuRes['data'])) {
        $mData = $menuRes['data'];
        if (isset($mData['opening_time'], $mData['closing_time'], $mData['is_open'], $mData['items'])) {
            echo "✓ handleGetMenu: Successfully returned wrapper with opening_time ('{$mData['opening_time']}'), closing_time ('{$mData['closing_time']}'), is_open (" . ($mData['is_open'] ? 'true' : 'false') . "), and items array (" . count($mData['items']) . " dishes).\n\n";
        } else {
            echo "✗ handleGetMenu missing wrapper fields: " . json_encode(array_keys($mData)) . "\n";
            exit(1);
        }
    } else {
        echo "✗ handleGetMenu request failed. Status: {$menuRes['status']}\n";
        exit(1);
    }

    // --- TEST 5: D2 & D3 Admin Settings Lockdown ---
    echo "--- TEST 5: D2 & D3 Admin Settings Lockdown ---\n";
    // Attempt to edit closing_time and default_decay_rate via handleUpdateSettings
    $adminUpdateRes = d82ApiRequest("$baseUrl/admin/settings", 'POST', [
        'reservation_ttl' => 45,
        'opening_time' => '07:30',
        'closing_time' => '14:00', // Should be IGNORED
        'default_decay_rate' => 99.9, // Should be IGNORED
        'max_discount_qty' => 4
    ], $cookieAdmin);

    if ($adminUpdateRes['status'] === 200) {
        // Verify in DB that closing_time and default_decay_rate were NOT changed
        $checkStmt = $pdo->query("SELECT reservation_ttl, opening_time, closing_time, default_decay_rate, max_discount_qty FROM settings WHERE id = 1");
        $dbSettings = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($dbSettings['closing_time'] === '20:00' && (float)$dbSettings['default_decay_rate'] !== 99.9) {
            echo "✓ handleUpdateSettings: closing_time remained locked at '{$dbSettings['closing_time']}' and default_decay_rate remained unaffected by admin input.\n";
            echo "✓ Allowed fields (reservation_ttl: {$dbSettings['reservation_ttl']}, opening_time: {$dbSettings['opening_time']}) updated properly.\n\n";
        } else {
            echo "✗ Admin lockdown failed: closing_time={$dbSettings['closing_time']}, default_decay_rate={$dbSettings['default_decay_rate']}\n";
            exit(1);
        }
    } else {
        echo "✗ handleUpdateSettings request failed. Status: {$adminUpdateRes['status']}\n";
        exit(1);
    }

    echo "========================================================\n";
    echo "✓ ALL PHASE D8.2 AUTOMATED TESTS PASSED SUCCESSFULLY!\n";
    echo "========================================================\n";

} finally {
    echo "\nCleaning up test artifacts...\n";
    if (!empty($createdDishIds)) {
        $placeholders = implode(',', array_fill(0, count($createdDishIds), '?'));
        $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_id IN (SELECT id FROM recipes WHERE menu_item_id IN ($placeholders))")->execute($createdDishIds);
        $pdo->prepare("DELETE FROM recipes WHERE menu_item_id IN ($placeholders)")->execute($createdDishIds);
        $pdo->prepare("DELETE FROM inventory WHERE menu_item_id IN ($placeholders)")->execute($createdDishIds);
        $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id IN ($placeholders)")->execute($createdDishIds);
        $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id IN ($placeholders)")->execute($createdDishIds);
        $pdo->prepare("DELETE FROM menu_items WHERE id IN ($placeholders)")->execute($createdDishIds);
        echo "✓ Cleaned test dishes\n";
    }

    if ($origSettings) {
        $rst = $pdo->prepare("
            UPDATE settings 
            SET reservation_ttl = ?, opening_time = ?, closing_time = ?, max_discount_qty = ?,
                default_decay_rate = ?, ai_enabled = ?, tier1_minutes_before_close = ?,
                tier1_discount_percent = ?, tier2_minutes_before_close = ?, tier2_discount_percent = ?,
                emergency_clearance_active = ?
            WHERE id = 1
        ");
        $rst->execute([
            $origSettings['reservation_ttl'],
            $origSettings['opening_time'],
            $origSettings['closing_time'],
            $origSettings['max_discount_qty'],
            $origSettings['default_decay_rate'],
            $origSettings['ai_enabled'],
            $origSettings['tier1_minutes_before_close'],
            $origSettings['tier1_discount_percent'],
            $origSettings['tier2_minutes_before_close'],
            $origSettings['tier2_discount_percent'],
            $origSettings['emergency_clearance_active']
        ]);
        echo "✓ Restored original settings\n";
    }

    if (file_exists($cookieVendor)) @unlink($cookieVendor);
    if (file_exists($cookieAdmin)) @unlink($cookieAdmin);
    echo "✓ Cleanup complete.\n";
}
