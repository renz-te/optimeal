<?php
// test_c7.php

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileVendor = __DIR__ . '/cookie_vendor_c7.txt';
$cookieFileStudent = __DIR__ . '/cookie_student_c7.txt';

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
if (file_exists($cookieFileStudent)) unlink($cookieFileStudent);

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

echo "=== PHASE C7: VENDOR DAILY AUDIT SUMMARY VERIFICATION ===\n\n";

// 1. Vendor Login
echo "1. Vendor Login...\n";
$vendorLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'testvendor@optimeal.test',
    'password' => 'password123'
], $cookieFileVendor);
echo "   Status: {$vendorLogin['code']}, Role: " . ($vendorLogin['body']['role'] ?? 'Failed') . "\n";
assert($vendorLogin['code'] === 200, "Vendor login failed");

// 2. Student Login
echo "\n2. Student Login...\n";
$studentLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieFileStudent);
echo "   Status: {$studentLogin['code']}, Role: " . ($studentLogin['body']['role'] ?? 'Failed') . "\n";
assert($studentLogin['code'] === 200, "Student login failed");

// 3. Fetch Initial Daily Audit Baseline
echo "\n3. Fetching baseline daily audit via GET /api/vendor/audit...\n";
$baselineAudit = apiRequest("$baseUrl/vendor/audit", 'GET', null, $cookieFileVendor);
echo "   HTTP Code: {$baselineAudit['code']}\n";
assert($baselineAudit['code'] === 200, "Failed to fetch vendor audit");
$baseSummary = $baselineAudit['body']['summary'];
echo "   Baseline Summary:\n";
echo "     - Total Revenue: ₱" . number_format($baseSummary['total_revenue'], 2) . "\n";
echo "     - Claimed Orders: " . $baseSummary['claimed_orders'] . "\n";
echo "     - Total Orders: " . $baseSummary['total_orders'] . "\n";
echo "     - Unsold Stock Units: " . $baseSummary['total_unsold_units'] . "\n";
echo "     - Est. Waste Value: ₱" . number_format($baseSummary['total_waste_value'], 2) . "\n";

// 4. Place a Test Order as Student
echo "\n4. Placing test reservation as student...\n";
// Ensure dish 1 is active, fresh, and has stock
$pdo->query("UPDATE inventory SET online_pool_qty = 20, sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR) WHERE menu_item_id = 1");
$pdo->query("UPDATE menu_items SET is_active = 1 WHERE id = 1");

$orderRes = apiRequest("$baseUrl/reservations", 'POST', [
    'items' => [
        [
            'menu_item_id' => 1,
            'quantity' => 1,
            'portion_preference' => 'no_preference',
            'ack' => true
        ]
    ],
    'allergen_override' => true
], $cookieFileStudent);

echo "   HTTP Code: {$orderRes['code']}, Msg: " . ($orderRes['body']['message'] ?? $orderRes['raw']) . "\n";
assert($orderRes['code'] === 200, "Failed to place test reservation");
$claimToken = $orderRes['body']['claim_token'];
echo "   Claim Token Generated: #$claimToken\n";

// Fetch the order row to get ID and total_amount
$stmt = $pdo->prepare("SELECT id, total_amount, status FROM orders WHERE claim_token = ?");
$stmt->execute([$claimToken]);
$orderRow = $stmt->fetch(PDO::FETCH_ASSOC);
$testOrderId = (int)$orderRow['id'];
$orderAmount = (float)$orderRow['total_amount'];
echo "   Order ID: $testOrderId, Amount: ₱$orderAmount, Status: {$orderRow['status']}\n";

// 5. Vendor Marks Order as Claimed
echo "\n5. Vendor marks order #$testOrderId as 'claimed'...\n";
$claimRes = apiRequest("$baseUrl/vendor/orders/update", 'POST', [
    'order_id' => $testOrderId,
    'status' => 'claimed'
], $cookieFileVendor);

echo "   HTTP Code: {$claimRes['code']}, Msg: " . ($claimRes['body']['message'] ?? $claimRes['raw']) . "\n";
assert($claimRes['code'] === 200, "Failed to update order status to claimed");

// 6. Fetch Updated Daily Audit & Assert Live Impact
echo "\n6. Re-fetching daily audit to verify updated revenue and claimed count...\n";
$updatedAudit = apiRequest("$baseUrl/vendor/audit", 'GET', null, $cookieFileVendor);
echo "   HTTP Code: {$updatedAudit['code']}\n";
assert($updatedAudit['code'] === 200, "Failed to re-fetch vendor audit");
$newSummary = $updatedAudit['body']['summary'];

echo "   Updated Summary:\n";
echo "     - Total Revenue: ₱" . number_format($newSummary['total_revenue'], 2) . " (Expected: ₱" . number_format($baseSummary['total_revenue'] + $orderAmount, 2) . ")\n";
echo "     - Claimed Orders: " . $newSummary['claimed_orders'] . " (Expected: " . ($baseSummary['claimed_orders'] + 1) . ")\n";
echo "     - Total Orders: " . $newSummary['total_orders'] . "\n";

assert(
    $newSummary['claimed_orders'] === $baseSummary['claimed_orders'] + 1,
    "Claimed orders count must increase by 1"
);
assert(
    abs($newSummary['total_revenue'] - ($baseSummary['total_revenue'] + $orderAmount)) < 0.01,
    "Total revenue must increase exactly by order amount"
);

// 7. Verify Unsold Inventory & Order Ledger Content
echo "\n7. Verifying unsold inventory and ledger entries in audit response...\n";
assert(!empty($updatedAudit['body']['unsold_inventory']), "Unsold inventory array should not be empty");
echo "   Unsold dishes in audit report: " . count($updatedAudit['body']['unsold_inventory']) . "\n";

$foundInLedger = false;
foreach ($updatedAudit['body']['orders'] as $ord) {
    if ((int)$ord['id'] === $testOrderId && $ord['status'] === 'claimed') {
        $foundInLedger = true;
        echo "   Found order in audit ledger: #{$ord['claim_token']}, Customer: {$ord['customer_name']}, Status: {$ord['status']}, Amount: ₱{$ord['total_amount']}\n";
        break;
    }
}
assert($foundInLedger, "Claimed order must appear in today's audit ledger");

echo "\n=== ALL DAILY AUDIT VERIFICATIONS PASSED SUCCESSFULLY! ===\n";

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
if (file_exists($cookieFileStudent)) unlink($cookieFileStudent);
