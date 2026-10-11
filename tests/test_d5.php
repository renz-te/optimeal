<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D5 Automated Verification Suite
 * Tests: Financial Audit Additions (COGS & Profit Margin)
 * - Dish Cost per Serving Map computation
 * - Claimed orders COGS computation
 * - Target Math verification: Revenue = ₱300.00, COGS = ₱100.00, Profit = ₱200.00, Margin = 66.7%
 * - Zero revenue division-by-zero defense: Margin % returns 0.0
 * - Unsold inventory raw food waste cost tracking
 * - Apache .htaccess and CLI access security block
 */

echo "=== PHASE D5 AUTOMATED VERIFICATION SUITE ===\n\n";

$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . DIRECTORY_SEPARATOR . 'cookie_vendor_d5.txt';
$cookieStudent = $tempDir . DIRECTORY_SEPARATOR . 'cookie_student_d5.txt';
if (file_exists($cookieVendor)) @unlink($cookieVendor);
if (file_exists($cookieStudent)) @unlink($cookieStudent);

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

// 2. Authenticate Student
$studentLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieStudent);

if ($studentLogin['status'] !== 200) {
    echo "✗ Failed student login: HTTP {$studentLogin['status']}\n";
    exit(1);
}
echo "✓ 2. Student Authenticated\n";

// 3. Ensure test dish 905 ("D4 Roast Chicken Test") is ready with cost = ₱50.00 and base_price = ₱150.00
echo "\n--- TEST STEP 1: Verify Test Dish Setup (₱50 Cost, ₱150 Price) ---\n";
$storeId = 1;

// Set Chicken cost to ₱200/kg
apiRequest("$baseUrl/vendor/ingredient-costs", 'POST', [
    'items' => [
        ['ingredient_name' => 'Chicken', 'cost' => 200.00, 'cost_unit' => 'kg']
    ]
], $cookieVendor);

$stmtDish = $pdo->prepare("SELECT id FROM menu_items WHERE name = 'D4 Roast Chicken Test' AND store_id = ?");
$stmtDish->execute([$storeId]);
$dishId = $stmtDish->fetchColumn();
if (!$dishId) {
    $pdo->prepare("
        INSERT INTO menu_items (store_id, name, description, category, base_price, price_floor, is_active, ai_confirmed)
        VALUES (?, 'D4 Roast Chicken Test', 'Food Cost Engine Test Dish', 'Ulam / Stews', 150.00, 125.00, 1, 1)
    ")->execute([$storeId]);
    $dishId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold, delisted_from_app) VALUES (?, 2, 10, 2, 0)")->execute([$dishId]);
} else {
    $dishId = (int)$dishId;
    $pdo->prepare("UPDATE menu_items SET base_price = 150.00, is_active = 1 WHERE id = ?")->execute([$dishId]);
    $pdo->prepare("UPDATE inventory SET online_pool_qty = 10, walkin_pool_qty = 2, delisted_from_app = 0, sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR), cooked_at = NOW() WHERE menu_item_id = ?")->execute([$dishId]);
}

// Ensure recipe is 250g Chicken, yield 1
apiRequest("$baseUrl/vendor/dish/recipe", 'POST', [
    'menu_item_id' => $dishId,
    'yield_servings' => 1,
    'instructions' => 'Roast at 180C',
    'ingredients' => [
        ['name' => 'Chicken', 'quantity' => 250, 'unit' => 'g']
    ]
], $cookieVendor);

echo "✓ Test Dish configured: ID $dishId, Base Price = ₱150.00, Recipe Cost = ₱50.00/serving\n";

// 4. Archive prior today's orders for store 1 to establish baseline for exact target test
echo "\n--- TEST STEP 2: Baseline Calibration & Order Creation ---\n";
// Temporarily date-shift earlier today orders to yesterday so today's audit reflects precisely the target test
$pdo->prepare("
    UPDATE orders 
    SET reserved_at = DATE_SUB(reserved_at, INTERVAL 1 DAY) 
    WHERE store_id = ? AND DATE(COALESCE(reserved_at, NOW())) = CURDATE()
")->execute([$storeId]);

// Zero-Revenue Margin % check first!
$zeroAudit = apiRequest("$baseUrl/vendor/audit", 'GET', null, $cookieVendor);
$zeroSummary = $zeroAudit['data']['summary'] ?? [];
echo "Zero Revenue Audit Check: Revenue = ₱{$zeroSummary['total_revenue']}, Margin % = {$zeroSummary['gross_margin_pct']}%\n";
if ((float)$zeroSummary['total_revenue'] === 0.0 && (float)$zeroSummary['gross_margin_pct'] === 0.0) {
    echo "✓ Zero-revenue division-by-zero defense confirmed: Margin is 0.0% when revenue is ₱0.00\n";
} else {
    echo "✗ Zero-revenue division-by-zero test failed: " . json_encode($zeroSummary) . "\n";
    exit(1);
}

// 5. Place 2 orders of dish 905 (₱150 each)
echo "\n--- TEST STEP 3: Place 2 Student Orders (₱150 each) & Claim ---\n";
$order1Res = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        ['menu_item_id' => $dishId, 'quantity' => 1]
    ]
], $cookieStudent);

if ($order1Res['status'] !== 200 && $order1Res['status'] !== 201) {
    echo "✗ Failed to create order 1: HTTP {$order1Res['status']} - " . json_encode($order1Res['data']) . "\n";
    exit(1);
}
$order1Id = $order1Res['data']['order_id'] ?? null;

$order2Res = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        ['menu_item_id' => $dishId, 'quantity' => 1]
    ]
], $cookieStudent);

if ($order2Res['status'] !== 200 && $order2Res['status'] !== 201) {
    echo "✗ Failed to create order 2: HTTP {$order2Res['status']} - " . json_encode($order2Res['data']) . "\n";
    exit(1);
}
$order2Id = $order2Res['data']['order_id'] ?? null;

echo "✓ Created Order #$order1Id (₱150.00) and Order #$order2Id (₱150.00)\n";

// Vendor marks both orders as claimed
$claim1 = apiRequest("$baseUrl/vendor/orders/update", 'POST', [
    'order_id' => $order1Id,
    'status' => 'claimed'
], $cookieVendor);

$claim2 = apiRequest("$baseUrl/vendor/orders/update", 'POST', [
    'order_id' => $order2Id,
    'status' => 'claimed'
], $cookieVendor);

if ($claim1['status'] !== 200 || $claim2['status'] !== 200) {
    echo "✗ Failed to claim orders: HTTP {$claim1['status']} / {$claim2['status']}\n";
    exit(1);
}
echo "✓ Both orders successfully claimed by vendor\n";

// 6. Fetch Daily Audit and verify the exact math target
echo "\n--- TEST STEP 4: Verify Exact Math Target (Revenue = ₱300, COGS = ₱100, Profit = ₱200, Margin = 66.7%) ---\n";
$auditRes = apiRequest("$baseUrl/vendor/audit", 'GET', null, $cookieVendor);
if ($auditRes['status'] !== 200) {
    echo "✗ Failed to fetch audit: HTTP {$auditRes['status']}\n";
    exit(1);
}

$summary = $auditRes['data']['summary'] ?? [];
$revenue = (float)($summary['total_revenue'] ?? 0);
$cogs = (float)($summary['total_cogs'] ?? 0);
$profit = (float)($summary['gross_profit'] ?? 0);
$margin = (float)($summary['gross_margin_pct'] ?? 0);
$claimedCount = (int)($summary['claimed_orders'] ?? 0);

echo "Actual Results from /api/vendor/audit:\n";
echo "  Total Revenue:    ₱" . number_format($revenue, 2) . "  (Target: ₱300.00)\n";
echo "  Total COGS:       ₱" . number_format($cogs, 2) . "  (Target: ₱100.00)\n";
echo "  Gross Profit:     ₱" . number_format($profit, 2) . "  (Target: ₱200.00)\n";
echo "  Gross Margin %:   " . number_format($margin, 1) . "%   (Target: 66.7%)\n";
echo "  Claimed Orders:   $claimedCount        (Target: 2)\n";

$targetMatched = ($revenue === 300.00 && $cogs === 100.00 && $profit === 200.00 && $margin === 66.7 && $claimedCount === 2);

if ($targetMatched) {
    echo "✓ TEST STEP 4 PASSED: Exact financial target perfectly verified!\n";
} else {
    echo "✗ TEST STEP 4 FAILED: Target metrics did not match!\n";
    exit(1);
}

// 7. Verify Unsold Food Inventory Raw Cost vs Retail Value
echo "\n--- TEST STEP 5: Verify Unsold Food Inventory Raw Cost Tracking ---\n";
$unsoldItems = $auditRes['data']['unsold_inventory'] ?? [];
$testDishItem = null;
foreach ($unsoldItems as $ui) {
    if ((int)$ui['id'] === $dishId) {
        $testDishItem = $ui;
        break;
    }
}

if (!$testDishItem) {
    echo "✗ Test dish not found in unsold_inventory list\n";
    exit(1);
}

$remainingStock = (int)$testDishItem['remaining_stock'];
$dishCostPerServing = (float)$testDishItem['cost_per_serving'];
$dishWasteActualCost = (float)$testDishItem['waste_actual_cost'];
$dishUnsoldStockValue = (float)$testDishItem['unsold_stock_value'];
$expectedWasteCost = round($dishCostPerServing * $remainingStock, 2);

echo "Dish Unsold Metrics:\n";
echo "  Remaining Stock:     $remainingStock\n";
echo "  Cost Per Serving:    ₱" . number_format($dishCostPerServing, 2) . "\n";
echo "  Actual Waste Cost:   ₱" . number_format($dishWasteActualCost, 2) . " (Expected: ₱" . number_format($expectedWasteCost, 2) . ")\n";
echo "  Retail Menu Value:   ₱" . number_format($dishUnsoldStockValue, 2) . "\n";

if ($dishCostPerServing === 50.00 && $dishWasteActualCost === $expectedWasteCost && $dishWasteActualCost > 0) {
    echo "✓ TEST STEP 5 PASSED: Raw food waste cost matches cost_per_serving * remaining_stock!\n";
} else {
    echo "✗ TEST STEP 5 FAILED: Raw food waste cost mismatch!\n";
    exit(1);
}

// 8. Direct Web Access Block on tests/
echo "\n--- TEST STEP 6: Direct Web Access Block on tests/ ---\n";
$directWebRes = apiRequest("http://127.0.0.1/optimeal/tests/test_d5.php", 'GET');
echo "Direct web request HTTP Status: {$directWebRes['status']} (Expected: 403)\n";

if ($directWebRes['status'] === 403 || $directWebRes['status'] === 404) {
    echo "✓ TEST STEP 6 PASSED: Web access to test script blocked ({$directWebRes['status']}).\n";
} else {
    echo "✗ TEST STEP 6 FAILED: Expected 403 or 404, got {$directWebRes['status']}\n";
    exit(1);
}

echo "\n============================================\n";
echo "✓ ALL PHASE D5 VERIFICATIONS PASSED CLEANLY!\n";
echo "============================================\n";
