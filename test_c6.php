<?php
// test_c6.php

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileVendor = __DIR__ . '/cookie_vendor_c6.txt';
$cookieFileCustomer = __DIR__ . '/cookie_customer_c6.txt';

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
if (file_exists($cookieFileCustomer)) unlink($cookieFileCustomer);

require_once __DIR__ . '/api/db.php';
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
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = microtime(true) - $start;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'code' => $httpCode,
        'body' => json_decode($response, true),
        'raw' => $response,
        'duration_ms' => round($duration * 1000, 2)
    ];
}

echo "=== PHASE C6: FOOD SAFETY & SELL-BY TRACKING VERIFICATION ===\n\n";

// 1. Vendor Login
echo "1. Vendor Login...\n";
$vendorLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'testvendor@optimeal.test',
    'password' => 'password123'
], $cookieFileVendor);
echo "   Status: {$vendorLogin['code']}, Role: " . ($vendorLogin['body']['role'] ?? 'Failed') . "\n";
assert($vendorLogin['code'] === 200, "Vendor login failed");

// 2. Customer Login
echo "\n2. Customer (Student) Login...\n";
$custLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieFileCustomer);
echo "   Status: {$custLogin['code']}, Role: " . ($custLogin['body']['role'] ?? 'Failed') . "\n";
assert($custLogin['code'] === 200, "Customer login failed");

// Ensure store is open for testing
$pdo->query("UPDATE settings SET closing_time = '23:59' WHERE id = 1");

// Find a menu item to test
$testDish = $pdo->query("SELECT id, name FROM menu_items WHERE store_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testDishId = (int)$testDish['id'];
$testDishName = $testDish['name'];
echo "\n   Test Dish Selected: ID #{$testDishId} ('{$testDishName}')\n";

// 3. Launch Menu with Food Safety Batch Timestamps
$nowManila = date('Y-m-d H:i:s');
$sellByManila = date('Y-m-d H:i:s', strtotime('+4 hours'));

echo "\n3. Launching menu with cooked_at = '$nowManila' and sell_by = '$sellByManila'...\n";
$launchRes = apiRequest("$baseUrl/vendor/menu/launch", 'POST', [
    'store_id' => 1,
    'cooked_at' => $nowManila,
    'sell_by' => $sellByManila,
    'items' => [
        [
            'menu_item_id' => $testDishId,
            'stock' => 15,
            'cooked_at' => $nowManila,
            'sell_by' => $sellByManila
        ]
    ]
], $cookieFileVendor);

echo "   Launch Status: {$launchRes['code']}, Msg: " . ($launchRes['body']['message'] ?? $launchRes['raw']) . "\n";
assert($launchRes['code'] === 200, "Menu launch failed");

// Verify in DB
$invStmt = $pdo->prepare("SELECT cooked_at, sell_by, online_pool_qty FROM inventory WHERE menu_item_id = ?");
$invStmt->execute([$testDishId]);
$invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
echo "   DB Inventory row: cooked_at='{$invRow['cooked_at']}', sell_by='{$invRow['sell_by']}', stock={$invRow['online_pool_qty']}\n";
assert(!empty($invRow['cooked_at']), "cooked_at must not be null");
assert(!empty($invRow['sell_by']), "sell_by must not be null");

// 4. Verify Dish Appears on Customer Menu when Fresh
echo "\n4. Verifying dish appears on customer menu while fresh...\n";
$menuRes = apiRequest("$baseUrl/menu", 'GET', null, $cookieFileCustomer);
$dishInMenu = false;
foreach ($menuRes['body'] as $mItem) {
    if ((int)$mItem['id'] === $testDishId) {
        $dishInMenu = true;
        echo "   Dish '{$mItem['name']}' found in menu! Cooked: {$mItem['cooked_at']}, Sell-By: {$mItem['sell_by']}\n";
        break;
    }
}
assert($dishInMenu, "Fresh dish should be visible in customer menu");

// 5. Simulate Food Expiration (Set sell_by to 1 minute in the past)
echo "\n5. Simulating food expiration: Setting sell_by to 1 minute in the past in DB...\n";
$pdo->prepare("UPDATE inventory SET sell_by = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE menu_item_id = ?")->execute([$testDishId]);

$invStmt->execute([$testDishId]);
$expiredInv = $invStmt->fetch(PDO::FETCH_ASSOC);
echo "   Updated sell_by in DB: '{$expiredInv['sell_by']}'\n";

// 6. Verify Expired Dish is Excluded from Customer Menu
echo "\n6. Checking customer menu for expired dish...\n";
$menuResAfter = apiRequest("$baseUrl/menu", 'GET', null, $cookieFileCustomer);
$expiredDishInMenu = false;
foreach ($menuResAfter['body'] as $mItem) {
    if ((int)$mItem['id'] === $testDishId) {
        $expiredDishInMenu = true;
        break;
    }
}
echo "   Is expired dish visible on customer menu? " . ($expiredDishInMenu ? "YES (FAILED)" : "NO (PASSED - Omitted)") . "\n";
assert(!$expiredDishInMenu, "Expired dish MUST be omitted from customer menu");
echo "   ==> PASS: Expired dish was omitted from live customer menu!\n";

// 7. Verify Attempting to Reserve the Expired Dish Fails with HTTP 400
echo "\n7. Attempting to reserve the expired dish via POST /api/reservations...\n";
$orderAttempt = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        [
            'menu_item_id' => $testDishId,
            'quantity' => 1,
            'portion_preference' => 'no_preference',
            'ack' => true
        ]
    ]
], $cookieFileCustomer);

echo "   HTTP Code: {$orderAttempt['code']}\n";
echo "   Response: {$orderAttempt['raw']}\n";
assert($orderAttempt['code'] === 400, "Expected HTTP 400 when ordering expired food");
assert(
    strpos($orderAttempt['body']['error'] ?? '', 'Food safety violation') !== false,
    "Expected food safety violation error message"
);
echo "   ==> PASS: Server-side enforcement successfully rejected reservation with HTTP 400!\n";

// 8. Restore dish sell_by to +4 hours and verify ordering works
echo "\n8. Restoring dish to fresh state (+4 hours)...\n";
$pdo->prepare("UPDATE inventory SET sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE menu_item_id = ?")->execute([$testDishId]);

$validOrder = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        [
            'menu_item_id' => $testDishId,
            'quantity' => 1,
            'portion_preference' => 'no_preference',
            'ack' => true
        ]
    ],
    'allergen_override' => true
], $cookieFileCustomer);

echo "   HTTP Code: {$validOrder['code']}\n";
echo "   Order response: " . ($validOrder['body']['message'] ?? $validOrder['raw']) . "\n";
assert($validOrder['code'] === 200, "Fresh dish should be reservable");
echo "   ==> PASS: Fresh dish successfully reserved!\n";

echo "\n=== ALL FOOD SAFETY VERIFICATIONS PASSED SUCCESSFULLY! ===\n";

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
if (file_exists($cookieFileCustomer)) unlink($cookieFileCustomer);
