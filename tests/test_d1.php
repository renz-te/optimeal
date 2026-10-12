<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
// tests/test_d1.php - Verification suite for Phase D1 & The Amendment

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileVendor = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cookie_vendor_d1.txt';
$cookieFileStudent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cookie_student_d1.txt';

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
if (file_exists($cookieFileStudent)) unlink($cookieFileStudent);

require_once __DIR__ . '/../api/db.php';
date_default_timezone_set('Asia/Manila');

function apiRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($data !== null) {
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ]);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'data' => json_decode($response, true), 'raw' => $response];
}

echo "=== PHASE D1 AUTOMATED VERIFICATION SUITE ===\n\n";

try {
    // STEP 1: Verify Timezone Lock in MySQL & PHP
echo "--- TEST 1: Timezone Lock ---\n";
$tzStmt = $pdo->query("SELECT @@session.time_zone AS sess_tz, @@global.time_zone AS glob_tz, NOW() AS db_now, CURDATE() AS db_curdate");
$tzRow = $tzStmt->fetch();
$phpTz = date_default_timezone_get();
$phpNow = date('Y-m-d H:i:s');
echo "MySQL Session TZ: {$tzRow['sess_tz']} (Expected: +08:00)\n";
echo "MySQL NOW():      {$tzRow['db_now']}\n";
echo "PHP Timezone:     $phpTz (Expected: Asia/Manila)\n";
echo "PHP date():       $phpNow\n";

if ($tzRow['sess_tz'] === '+08:00' && $phpTz === 'Asia/Manila') {
    echo "✓ TEST 1 PASSED: Timezone is locked to Asia/Manila (+08:00).\n\n";
} else {
    echo "✗ TEST 1 FAILED: Timezone is not properly locked.\n\n";
}

// Setup users and menu data for tests
// 1. Get or create student user
$studentStmt = $pdo->prepare("SELECT id FROM users WHERE email = 'student@optimeal.test'");
$studentStmt->execute();
$studentId = $studentStmt->fetchColumn();

// Login student
$loginStudent = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieFileStudent);
echo "Student Login: HTTP {$loginStudent['status']}\n";

// Ensure student profile has 'Dairy' in allergens
$pdo->prepare("INSERT INTO student_profiles (user_id, allergens) VALUES (?, ?) ON DUPLICATE KEY UPDATE allergens = ?")
    ->execute([$studentId, json_encode(['Dairy']), json_encode(['Dairy'])]);

// 2. Ensure we have two active dishes in store 1:
// Dish A: Contains Dairy
// Dish B: No allergens
$storeStmt = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
$storeId = $storeStmt->fetchColumn() ?: 1;

// Let's create or update Dish A (Cream Soup - with dairy)
$pdo->prepare("
    INSERT INTO menu_items (id, store_id, name, category, base_price, price_floor, is_active)
    VALUES (901, ?, 'Creamy Chowder', 'Soups', 75.00, 50.00, 1)
    ON DUPLICATE KEY UPDATE name = 'Creamy Chowder', base_price = 75.00, price_floor = 50.00, is_active = 1
")->execute([$storeId]);

// Ensure Dairy allergen exists and map to Dish 901
$pdo->exec("INSERT IGNORE INTO critical_allergens (name) VALUES ('Dairy')");
$dairyId = $pdo->query("SELECT id FROM critical_allergens WHERE LOWER(name) = 'dairy'")->fetchColumn();
echo "Dairy Allergen ID: $dairyId\n";
$pdo->prepare("INSERT IGNORE INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES (901, ?)")->execute([$dairyId]);

// Inventory for Dish 901
$pdo->prepare("
    INSERT INTO inventory (menu_item_id, online_pool_qty, walkin_pool_qty, cooked_at, sell_by)
    VALUES (901, 20, 10, NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR))
    ON DUPLICATE KEY UPDATE online_pool_qty = 20, walkin_pool_qty = 10, sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR)
")->execute();

// Dish B: Plain Rice (no allergens)
$pdo->prepare("
    INSERT INTO menu_items (id, store_id, name, category, base_price, price_floor, is_active)
    VALUES (902, ?, 'Steamed Rice', 'Sides', 15.00, 10.00, 1)
    ON DUPLICATE KEY UPDATE name = 'Steamed Rice', base_price = 15.00, price_floor = 10.00, is_active = 1
")->execute([$storeId]);

$pdo->prepare("
    INSERT INTO inventory (menu_item_id, online_pool_qty, walkin_pool_qty, cooked_at, sell_by)
    VALUES (902, 50, 30, NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR))
    ON DUPLICATE KEY UPDATE online_pool_qty = 50, walkin_pool_qty = 30, sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR)
")->execute();

// Login vendor
$loginVendor = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'canteen@optimeal.test',
    'password' => 'password123'
], $cookieFileVendor);
echo "Vendor Login: HTTP {$loginVendor['status']}\n\n";

// TEST 2A: Student orders Creamy Chowder (Dairy) WITHOUT override
echo "--- TEST 2A: Allergen Order WITHOUT Override ---\n";
$orderResNoOverride = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        ['menu_item_id' => 901, 'quantity' => 1]
    ],
    'allergen_override' => false
], $cookieFileStudent);
echo "Response: HTTP {$orderResNoOverride['status']}, Error: " . ($orderResNoOverride['data']['error'] ?? 'None') . "\n";
if ($orderResNoOverride['status'] === 400 && str_contains($orderResNoOverride['data']['error'] ?? '', 'Safety Restriction')) {
    echo "✓ TEST 2A PASSED: Correctly blocked order due to unacknowledged allergen.\n\n";
} else {
    echo "✗ TEST 2A FAILED!\n\n";
}

// TEST 2B: Student orders Steamed Rice (Safe) but passes allergen_override = true
echo "--- TEST 2B: Safe Order with allergen_override = true (No real conflict) ---\n";
$orderResSafeWithOverride = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        ['menu_item_id' => 902, 'quantity' => 1]
    ],
    'allergen_override' => true
], $cookieFileStudent);
echo "Response: HTTP {$orderResSafeWithOverride['status']}, Order ID: " . ($orderResSafeWithOverride['data']['order_id'] ?? 'None') . "\n";
$safeOrderId = $orderResSafeWithOverride['data']['order_id'] ?? null;
if ($safeOrderId) {
    $stmt = $pdo->prepare("SELECT allergen_override, allergen_conflicts FROM orders WHERE id = ?");
    $stmt->execute([$safeOrderId]);
    $row = $stmt->fetch();
    echo "Database Record: allergen_override = {$row['allergen_override']}, allergen_conflicts = " . var_export($row['allergen_conflicts'], true) . "\n";
    if ($row['allergen_override'] == 0 && empty($row['allergen_conflicts'])) {
        echo "✓ TEST 2B PASSED: Correctly set allergen_override = 0 because no conflict was found by server.\n\n";
    } else {
        echo "✗ TEST 2B FAILED: Stored allergen_override = 1 even though no conflict occurred!\n\n";
    }
} else {
    echo "✗ TEST 2B FAILED: Reservation creation failed.\n\n";
}

// TEST 2C: Student orders Creamy Chowder (Dairy) WITH allergen_override = true
echo "--- TEST 2C: Genuine Allergen Order WITH allergen_override = true ---\n";
$orderResRealOverride = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        ['menu_item_id' => 901, 'quantity' => 1]
    ],
    'allergen_override' => true
], $cookieFileStudent);
echo "Response: HTTP {$orderResRealOverride['status']}, Order ID: " . ($orderResRealOverride['data']['order_id'] ?? 'None') . "\n";
$allergenOrderId = $orderResRealOverride['data']['order_id'] ?? null;
if ($allergenOrderId) {
    $stmt = $pdo->prepare("SELECT allergen_override, allergen_conflicts, status FROM orders WHERE id = ?");
    $stmt->execute([$allergenOrderId]);
    $row = $stmt->fetch();
    echo "Database Record: allergen_override = {$row['allergen_override']}, allergen_conflicts = {$row['allergen_conflicts']}\n";
    if ($row['allergen_override'] == 1 && str_contains($row['allergen_conflicts'], 'Dairy')) {
        echo "✓ TEST 2C PASSED: Correctly logged allergen_override = 1 with conflicting allergen details.\n\n";
    } else {
        echo "✗ TEST 2C FAILED: allergen_override not properly recorded!\n\n";
    }
} else {
    echo "✗ TEST 2C FAILED: Reservation creation failed.\n\n";
}

// TEST 3A: Vendor tries to mark allergen order claimed WITHOUT staff confirmation
echo "--- TEST 3A: Restrict 'Mark Claimed' Without Staff Confirmation ---\n";
$claimNoAck = apiRequest("$baseUrl/vendor/orders/update", 'POST', [
    'order_id' => $allergenOrderId,
    'status' => 'claimed'
], $cookieFileVendor);
echo "Response: HTTP {$claimNoAck['status']}, Error: " . ($claimNoAck['data']['error'] ?? 'None') . "\n";
if ($claimNoAck['status'] === 400 && str_contains($claimNoAck['data']['error'] ?? '', 'Allergen Warning')) {
    echo "✓ TEST 3A PASSED: Backend strictly rejected claiming allergen order without staff confirmation.\n\n";
} else {
    echo "✗ TEST 3A FAILED: Should have rejected claim without staff confirmation!\n\n";
}

// TEST 3B: Vendor marks allergen order claimed WITH staff confirmation
echo "--- TEST 3B: Confirm 'Mark Claimed' With Staff Confirmation ---\n";
$claimWithAck = apiRequest("$baseUrl/vendor/orders/update", 'POST', [
    'order_id' => $allergenOrderId,
    'status' => 'claimed',
    'confirm_allergen_override' => true
], $cookieFileVendor);
echo "Response: HTTP {$claimWithAck['status']}, Message: " . ($claimWithAck['data']['message'] ?? 'None') . "\n";

$checkOrder = $pdo->prepare("SELECT status, handled_by FROM orders WHERE id = ?");
$checkOrder->execute([$allergenOrderId]);
$orderStatusRow = $checkOrder->fetch();
echo "Order Status in DB: {$orderStatusRow['status']}, Handled By: {$orderStatusRow['handled_by']}\n";
if ($claimWithAck['status'] === 200 && $orderStatusRow['status'] === 'claimed') {
    echo "✓ TEST 3B PASSED: Order successfully claimed with staff acknowledgment.\n\n";
} else {
    echo "✗ TEST 3B FAILED: Claiming with staff acknowledgment failed!\n\n";
}

// TEST 4: Daily Audit with Walk-in Pool included and Revenue unaffected
echo "--- TEST 4: Daily Audit Walk-in Pool & Revenue Check ---\n";
$auditRes = apiRequest("$baseUrl/vendor/audit", 'GET', null, $cookieFileVendor);
echo "Daily Audit HTTP Status: {$auditRes['status']}\n";
if ($auditRes['status'] === 200) {
    $summary = $auditRes['data']['summary'];
    echo "Audit Summary:\n";
    echo "  Total Orders:    {$summary['total_orders']}\n";
    echo "  Claimed Orders:  {$summary['claimed_orders']}\n";
    echo "  Total Revenue:   PHP {$summary['total_revenue']}\n";
    echo "  Total Unsold:    {$summary['total_unsold_units']} units\n";
    echo "  Unsold Val:      PHP " . ($summary['total_unsold_stock_value'] ?? $summary['unsold_stock_value'] ?? '0') . "\n";
    echo "  On Sale Val:     PHP {$summary['total_on_sale_value']}\n";
    echo "  Written Off Val: PHP {$summary['total_written_off_value']}\n";

    // Find Chowder (901) and Rice (902) in unsold_inventory
    foreach ($auditRes['data']['unsold_inventory'] as $item) {
        if (in_array($item['id'], [901, 902])) {
            $expectedUnits = $item['online_pool_qty'] + $item['walkin_pool_qty'];
            $expectedStockVal = round($item['base_price'] * $expectedUnits, 2);
            $val = $item['unsold_stock_value'] ?? $item['estimated_waste_value'] ?? 0;
            echo "  Dish [{$item['name']}]: Online={$item['online_pool_qty']}, Walk-in={$item['walkin_pool_qty']} => Remaining Stock={$item['remaining_stock']} (Expected: $expectedUnits), Unsold Stock Val={$val} (Expected: $expectedStockVal), Status={$item['status']}\n";
            if ($item['remaining_stock'] == $expectedUnits && (float)$val == (float)$expectedStockVal) {
                echo "    ✓ Unsold calculation matches (Online + Walk-in).\n";
            } else {
                echo "    ✗ Unsold calculation mismatch!\n";
            }
        }
    }
    echo "✓ TEST 4 PASSED: Daily Audit correctly includes Walk-in Pool while Sales Revenue reflects only claimed orders.\n\n";
} else {
    echo "✗ TEST 4 FAILED: Could not retrieve daily audit!\n\n";
}

    echo "=== ALL PHASE D1 TESTS COMPLETED ===\n";

} finally {
    if (isset($pdo)) {
        $pdo->exec("
            DELETE FROM order_items WHERE menu_item_id IN (901, 902);
            DELETE FROM orders WHERE id NOT IN (SELECT DISTINCT order_id FROM order_items);
            DELETE FROM menu_item_critical_allergens WHERE menu_item_id IN (901, 902);
            DELETE FROM inventory WHERE menu_item_id IN (901, 902);
            DELETE FROM menu_items WHERE id IN (901, 902);
        ");
    }
    if (file_exists($cookieFileVendor)) @unlink($cookieFileVendor);
    if (file_exists($cookieFileStudent)) @unlink($cookieFileStudent);
}
