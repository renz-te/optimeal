<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D3 Automated Verification Suite
 * Tests: Reservation Quota & Stock Protection
 * - Clear un-launched dishes / yesterday's leftovers on Launch
 * - Filter out zero/negative stock dishes from customer menu
 * - Checkout Defense: reject orders when quantity exceeds online_pool_qty with exact error string
 */

echo "=== PHASE D3 AUTOMATED VERIFICATION SUITE ===\n\n";

$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . DIRECTORY_SEPARATOR . 'cookie_vendor_d3.txt';
$cookieStudent = $tempDir . DIRECTORY_SEPARATOR . 'cookie_student_d3.txt';

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

// 1. Authenticate Vendor (canteen@optimeal.test -> Store 1)
$vendorLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'canteen@optimeal.test',
    'password' => 'password123'
], $cookieVendor);

if ($vendorLogin['status'] !== 200) {
    echo "✗ Failed vendor login: HTTP {$vendorLogin['status']}\n";
    exit(1);
}
echo "✓ 1. Vendor Authenticated (Store 1)\n";

$dishAId = null;
$dishBId = null;
try {
    // 2. Authenticate Student (student@optimeal.test)
$studentLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieStudent);

if ($studentLogin['status'] !== 200) {
    echo "✗ Failed student login: HTTP {$studentLogin['status']}\n";
    exit(1);
}
echo "✓ 2. Student Authenticated\n";

// 3. Prepare Test Fixtures: Dish A ("D3 Test Chowder A") and Dish B ("D3 Test Viand B") in Store 1
$storeId = 1;

// Clean up or get Dish A
$stmtA = $pdo->prepare("SELECT id FROM menu_items WHERE name = 'D3 Test Chowder A' AND store_id = ?");
$stmtA->execute([$storeId]);
$dishAId = $stmtA->fetchColumn();
if (!$dishAId) {
    $pdo->prepare("
        INSERT INTO menu_items (store_id, name, description, category, base_price, price_floor, is_active, ai_confirmed)
        VALUES (?, 'D3 Test Chowder A', 'Verification Dish A', 'Sabaw / Soup', 65.00, 50.00, 1, 1)
    ")->execute([$storeId]);
    $dishAId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold, delisted_from_app) VALUES (?, 0, 0, 2, 0)")->execute([$dishAId]);
} else {
    $dishAId = (int)$dishAId;
}

// Clean up or get Dish B
$stmtB = $pdo->prepare("SELECT id FROM menu_items WHERE name = 'D3 Test Viand B' AND store_id = ?");
$stmtB->execute([$storeId]);
$dishBId = $stmtB->fetchColumn();
if (!$dishBId) {
    $pdo->prepare("
        INSERT INTO menu_items (store_id, name, description, category, base_price, price_floor, is_active, ai_confirmed)
        VALUES (?, 'D3 Test Viand B', 'Verification Dish B (Unlaunched)', 'Ulam / Stews', 75.00, 60.00, 1, 1)
    ")->execute([$storeId]);
    $dishBId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold, delisted_from_app) VALUES (?, 0, 0, 2, 0)")->execute([$dishBId]);
} else {
    $dishBId = (int)$dishBId;
}

// Simulate yesterday's leftovers: populate Dish B with leftover stock
$pdo->prepare("UPDATE inventory SET online_pool_qty = 15, delisted_from_app = 0 WHERE menu_item_id = ?")->execute([$dishBId]);
$pdo->prepare("UPDATE menu_items SET is_active = 1 WHERE id = ?")->execute([$dishBId]);
echo "✓ 3. Fixtures ready: Dish A (ID $dishAId), Dish B (ID $dishBId primed with 15 leftover stock)\n";

// 4. Vendor launches Daily Lineup: Launch Dish A with quota 5; DO NOT launch Dish B
echo "\n--- TEST STEP 1: Launch Dish A (Quota: 5), Omit Dish B ---\n";
$launchPayload = [
    'store_id' => $storeId,
    'cooked_at' => date('Y-m-d H:i:s'),
    'sell_by' => date('Y-m-d H:i:s', strtotime('+4 hours')),
    'items' => [
        [
            'menu_item_id' => $dishAId,
            'stock' => 5
        ]
    ]
];

$launchRes = apiRequest("$baseUrl/vendor/menu/launch", 'POST', $launchPayload, $cookieVendor);
if ($launchRes['status'] !== 200) {
    echo "✗ Launch endpoint failed: HTTP {$launchRes['status']} - " . json_encode($launchRes['data']) . "\n";
    exit(1);
}
echo "✓ Launch endpoint succeeded: HTTP 200\n";

// Verify DB state for un-launched Dish B: online_pool_qty must be 0, delisted_from_app = 1, is_active = 0
$checkB = $pdo->prepare("SELECT m.is_active, i.online_pool_qty, i.delisted_from_app FROM menu_items m JOIN inventory i ON m.id = i.menu_item_id WHERE m.id = ?");
$checkB->execute([$dishBId]);
$rowB = $checkB->fetch(PDO::FETCH_ASSOC);

if ((int)$rowB['is_active'] === 0 && (int)$rowB['online_pool_qty'] === 0 && (int)$rowB['delisted_from_app'] === 1) {
    echo "✓ DB Check: Dish B (un-launched) has is_active=0, online_pool_qty=0, delisted_from_app=1\n";
} else {
    echo "✗ DB Check Failed for Dish B: " . json_encode($rowB) . "\n";
    exit(1);
}

// Verify DB state for launched Dish A: online_pool_qty must be 5, delisted_from_app = 0, is_active = 1
$checkA = $pdo->prepare("SELECT m.is_active, i.online_pool_qty, i.delisted_from_app FROM menu_items m JOIN inventory i ON m.id = i.menu_item_id WHERE m.id = ?");
$checkA->execute([$dishAId]);
$rowA = $checkA->fetch(PDO::FETCH_ASSOC);

if ((int)$rowA['is_active'] === 1 && (int)$rowA['online_pool_qty'] === 5 && (int)$rowA['delisted_from_app'] === 0) {
    echo "✓ DB Check: Dish A (launched) has is_active=1, online_pool_qty=5, delisted_from_app=0\n";
} else {
    echo "✗ DB Check Failed for Dish A: " . json_encode($rowA) . "\n";
    exit(1);
}

// 5. Customer Menu Verification
echo "\n--- TEST STEP 2: Customer Menu Filter Verification ---\n";
$menuRes = apiRequest("$baseUrl/menu?store_id=$storeId", 'GET', null, $cookieStudent);
if ($menuRes['status'] !== 200) {
    echo "✗ Customer menu fetch failed: HTTP {$menuRes['status']}\n";
    exit(1);
}

$menuItems = $menuRes['data']['items'] ?? $menuRes['data'] ?? [];
$foundA = false;
$foundB = false;

foreach ($menuItems as $m) {
    if ((int)$m['id'] === $dishAId) $foundA = true;
    if ((int)$m['id'] === $dishBId) $foundB = true;
}

if ($foundA && !$foundB) {
    echo "✓ Customer Menu: Dish A is VISIBLE and Dish B is HIDDEN\n";
} else {
    echo "✗ Customer Menu Check Failed: Dish A visible=" . ($foundA ? 'yes' : 'no') . ", Dish B visible=" . ($foundB ? 'yes' : 'no') . "\n";
    exit(1);
}

// 6. Checkout Defense: Attempt to checkout with 6 portions of Dish A (Quota is 5)
echo "\n--- TEST STEP 3: Checkout Defense - Oversell Rejection (Order 6 when Stock is 5) ---\n";
$initialOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

$oversellPayload = [
    'store_id' => $storeId,
    'fulfillment_type' => 'dine_in',
    'items' => [
        [
            'menu_item_id' => $dishAId,
            'quantity' => 6,
            'portion_preference' => 'no_preference'
        ]
    ]
];

$oversellRes = apiRequest("$baseUrl/reservations", 'POST', $oversellPayload, $cookieStudent);
$expectedError = "Out of stock: Only 5 portions of D3 Test Chowder A remaining.";
$actualError = $oversellRes['data']['error'] ?? '';

echo "HTTP Status: {$oversellRes['status']} (Expected: 400)\n";
echo "Response Error: \"$actualError\"\n";
echo "Expected Error: \"$expectedError\"\n";

$newOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();

if ($oversellRes['status'] === 400 && $actualError === $expectedError && $newOrderCount === $initialOrderCount) {
    echo "✓ TEST STEP 3 PASSED: Server rejected 6 portions with exact error string and created 0 orders!\n";
} else {
    echo "✗ TEST STEP 3 FAILED: Rejection did not match expected criteria.\n";
    exit(1);
}

// 7. Checkout Defense: Attempt to checkout with 1 portion of Dish B (Un-launched, Stock 0)
echo "\n--- TEST STEP 4: Checkout Defense - Un-launched Dish B Rejection ---\n";
$unlaunchedPayload = [
    'store_id' => $storeId,
    'fulfillment_type' => 'dine_in',
    'items' => [
        [
            'menu_item_id' => $dishBId,
            'quantity' => 1,
            'portion_preference' => 'no_preference'
        ]
    ]
];

$unlaunchedRes = apiRequest("$baseUrl/reservations", 'POST', $unlaunchedPayload, $cookieStudent);
$expectedErrorB = "Out of stock: Only 0 portions of D3 Test Viand B remaining.";
$actualErrorB = $unlaunchedRes['data']['error'] ?? '';

echo "HTTP Status: {$unlaunchedRes['status']} (Expected: 400)\n";
echo "Response Error: \"$actualErrorB\"\n";

if ($unlaunchedRes['status'] === 400 && $actualErrorB === $expectedErrorB) {
    echo "✓ TEST STEP 4 PASSED: Server rejected un-launched Dish B with exact error string!\n";
} else {
    echo "✗ TEST STEP 4 FAILED: Rejection did not match expected criteria.\n";
    exit(1);
}

// 8. Legitimate Checkout: Reserve all 5 portions of Dish A
echo "\n--- TEST STEP 5: Legitimate Checkout (Reserve exact quota of 5) ---\n";
$legitPayload = [
    'store_id' => $storeId,
    'fulfillment_type' => 'dine_in',
    'items' => [
        [
            'menu_item_id' => $dishAId,
            'quantity' => 5,
            'portion_preference' => 'no_preference'
        ]
    ]
];

$legitRes = apiRequest("$baseUrl/reservations", 'POST', $legitPayload, $cookieStudent);
if ($legitRes['status'] === 200 && !empty($legitRes['data']['order_id'])) {
    echo "✓ TEST STEP 5 PASSED: Reserved 5 portions successfully (Order ID #{$legitRes['data']['order_id']})\n";
} else {
    echo "✗ TEST STEP 5 FAILED: Legitimate checkout failed: HTTP {$legitRes['status']} - " . json_encode($legitRes['data']) . "\n";
    exit(1);
}

// 9. Verify Post-Checkout Stock & Automatic Menu Depletion
echo "\n--- TEST STEP 6: Stock Depletion & Menu Delisting ---\n";
$checkAAfter = $pdo->prepare("SELECT online_pool_qty, delisted_from_app FROM inventory WHERE menu_item_id = ?");
$checkAAfter->execute([$dishAId]);
$stockAAfter = $checkAAfter->fetch(PDO::FETCH_ASSOC);

echo "Dish A Remaining Online Stock: {$stockAAfter['online_pool_qty']} (Expected: 0)\n";

$menuResAfter = apiRequest("$baseUrl/menu?store_id=$storeId", 'GET', null, $cookieStudent);
$menuAfterItems = $menuResAfter['data']['items'] ?? $menuResAfter['data'] ?? [];
$foundAAfter = false;
foreach ($menuAfterItems as $m) {
    if ((int)$m['id'] === $dishAId) $foundAAfter = true;
}

if ((int)$stockAAfter['online_pool_qty'] === 0 && !$foundAAfter) {
    echo "✓ TEST STEP 6 PASSED: Dish A stock reached 0 and disappeared from live customer menu!\n";
} else {
    echo "✗ TEST STEP 6 FAILED: Dish A still appeared or stock not 0.\n";
    exit(1);
}

// 10. Attempt to checkout 1 more portion of Dish A (Now Stock is 0)
echo "\n--- TEST STEP 7: Attempt Checkout on Sold-Out Dish A ---\n";
$soldOutPayload = [
    'store_id' => $storeId,
    'fulfillment_type' => 'dine_in',
    'items' => [
        [
            'menu_item_id' => $dishAId,
            'quantity' => 1,
            'portion_preference' => 'no_preference'
        ]
    ]
];

$soldOutRes = apiRequest("$baseUrl/reservations", 'POST', $soldOutPayload, $cookieStudent);
$expectedSoldOutError = "Out of stock: Only 0 portions of D3 Test Chowder A remaining.";
$actualSoldOutError = $soldOutRes['data']['error'] ?? '';

echo "HTTP Status: {$soldOutRes['status']} (Expected: 400)\n";
echo "Response Error: \"$actualSoldOutError\"\n";

if ($soldOutRes['status'] === 400 && $actualSoldOutError === $expectedSoldOutError) {
    echo "✓ TEST STEP 7 PASSED: Server rejected sold-out dish with exact error string!\n";
} else {
    echo "✗ TEST STEP 7 FAILED: Sold-out rejection failed.\n";
    exit(1);
}

// 11. Security Test: Direct Web Access Block on tests/
echo "\n--- TEST STEP 8: Direct Web Access Block on test script ---\n";
$webTest = apiRequest("http://127.0.0.1/optimeal/tests/test_d3.php", 'GET');
echo "Direct web request HTTP Status: {$webTest['status']} (Expected: 403)\n";
if ($webTest['status'] === 403) {
    echo "✓ TEST STEP 8 PASSED: Apache .htaccess denies web access to test script.\n";
} else {
    echo "✗ TEST STEP 8 FAILED: Direct web access returned {$webTest['status']}\n";
    exit(1);
}

    echo "\n============================================\n";
    echo "✓ ALL PHASE D3 VERIFICATIONS PASSED CLEANLY!\n";
    echo "============================================\n";

} finally {
    if (isset($pdo)) {
        $ids = array_filter([$dishAId ?? null, $dishBId ?? null]);
        if (!empty($ids)) {
            $inD = implode(',', $ids);
            $pdo->exec("DELETE FROM order_items WHERE menu_item_id IN ($inD)");
            $pdo->exec("DELETE FROM orders WHERE id NOT IN (SELECT DISTINCT order_id FROM order_items)");
            $pdo->exec("DELETE FROM inventory WHERE menu_item_id IN ($inD)");
            $pdo->exec("DELETE FROM menu_items WHERE id IN ($inD)");
        }
    }
    if (file_exists($cookieVendor)) @unlink($cookieVendor);
    if (file_exists($cookieStudent)) @unlink($cookieStudent);
}
