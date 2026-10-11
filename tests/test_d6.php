<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D6 Automated Verification Suite
 * Tests: Fulfillment Logistics (Pickup Slots & Notifications)
 * - 15-Minute pickup time slot generation math & boundary rounding
 * - Late-night / past closing defensive check (returns empty array)
 * - GET /api/customer/pickup-slots endpoint
 * - Student take-out checkout validation (rejects missing/invalid slot, accepts valid slot)
 * - Vendor live queue visibility of pickup_time
 * - POST /api/vendor/orders/notify-ready state update to 'ready_sent'
 * - Direct web access block via Apache .htaccess & CLI guard
 */

echo "=== PHASE D6 AUTOMATED VERIFICATION SUITE ===\n\n";

$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . DIRECTORY_SEPARATOR . 'cookie_vendor_d6.txt';
$cookieStudent = $tempDir . DIRECTORY_SEPARATOR . 'cookie_student_d6.txt';
if (file_exists($cookieVendor)) @unlink($cookieVendor);
if (file_exists($cookieStudent)) @unlink($cookieStudent);

$baseUrl = 'http://127.0.0.1/optimeal/api';

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/routes/customer.php';
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

// 1. Authenticate Vendor (Store 1) & Student
$vendorLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'canteen@optimeal.test',
    'password' => 'password123'
], $cookieVendor);

if ($vendorLogin['status'] !== 200) {
    echo "✗ Failed vendor login: HTTP {$vendorLogin['status']}\n";
    exit(1);
}
echo "✓ 1. Vendor Authenticated (Store 1)\n";

$studentLogin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'student@optimeal.test',
    'password' => 'password123'
], $cookieStudent);

if ($studentLogin['status'] !== 200) {
    echo "✗ Failed student login: HTTP {$studentLogin['status']}\n";
    exit(1);
}
echo "✓ 2. Student Authenticated\n";

// 2. Unit Test: 15-Minute Slot Generation Math & Edge Cases
echo "\n--- TEST STEP 1: Unit Test 15-Minute Slot Generation Math ---\n";
// Scenario A: 14:02 with closing at 20:00 -> should round to 14:15 as first slot
$slotsA = generatePickupSlots('20:00', '2026-10-12 14:02:00');
echo "Scenario A (Time 14:02, Close 20:00): Generated " . count($slotsA) . " slots. First: {$slotsA[0]}, Last: " . end($slotsA) . "\n";
if ($slotsA[0] === '14:15' && end($slotsA) === '20:00' && in_array('14:30', $slotsA) && in_array('14:45', $slotsA)) {
    echo "✓ Scenario A PASSED: Properly rounded up to next 15-min boundary and ended at closing time.\n";
} else {
    echo "✗ Scenario A FAILED: " . json_encode($slotsA) . "\n";
    exit(1);
}

// Scenario B: Exactly on 14:15:00 -> should provide next prep window (14:30)
$slotsB = generatePickupSlots('20:00', '2026-10-12 14:15:00');
echo "Scenario B (Time 14:15:00, Close 20:00): First slot: {$slotsB[0]}\n";
if ($slotsB[0] === '14:30') {
    echo "✓ Scenario B PASSED: Minimum 15-minute preparation window maintained.\n";
} else {
    echo "✗ Scenario B FAILED: First slot was {$slotsB[0]}, expected 14:30\n";
    exit(1);
}

// Scenario C: Already past closing time (e.g. 21:00 when close is 20:00)
$slotsC = generatePickupSlots('20:00', '2026-10-12 21:00:00');
echo "Scenario C (Past Closing 21:00): Generated " . count($slotsC) . " slots.\n";
if (empty($slotsC)) {
    echo "✓ Scenario C PASSED: Gracefully defended against past closing time (returned empty array).\n";
} else {
    echo "✗ Scenario C FAILED: Expected empty array, got " . json_encode($slotsC) . "\n";
    exit(1);
}

// 3. API Test: GET /api/customer/pickup-slots
echo "\n--- TEST STEP 2: Endpoint GET /api/customer/pickup-slots ---\n";
$slotEndpointRes = apiRequest("$baseUrl/customer/pickup-slots", 'GET', null, $cookieStudent);
if ($slotEndpointRes['status'] !== 200) {
    echo "✗ Failed to fetch pickup slots endpoint: HTTP {$slotEndpointRes['status']}\n";
    exit(1);
}
echo "✓ GET /api/customer/pickup-slots responded HTTP 200. Closing: {$slotEndpointRes['data']['closing_time']}, Total Slots: " . count($slotEndpointRes['data']['slots']) . "\n";

// 4. Student Checkout Validation: Missing & Invalid Pickup Slots
echo "\n--- TEST STEP 3: Student Take-Out Checkout Validation ---\n";
// Ensure test dish has stock and future sell-by
$dishStmt = $pdo->query("SELECT id FROM menu_items WHERE store_id = 1 AND is_active = 1 LIMIT 1");
$dishId = (int)$dishStmt->fetchColumn();
$pdo->prepare("UPDATE inventory SET online_pool_qty = 20, sell_by = DATE_ADD(NOW(), INTERVAL 4 HOUR), cooked_at = NOW(), delisted_from_app = 0 WHERE menu_item_id = ?")->execute([$dishId]);

// Attempt 1: Take-out WITHOUT pickup_time
$noSlotRes = apiRequest("$baseUrl/reservations", 'POST', [
    'fulfillment_type' => 'take_out',
    'items' => [
        ['menu_item_id' => $dishId, 'quantity' => 1]
    ]
], $cookieStudent);

echo "Missing Pickup Slot Attempt: HTTP {$noSlotRes['status']}, Error: '{$noSlotRes['data']['error']}'\n";
if ($noSlotRes['status'] === 400 && $noSlotRes['data']['error'] === 'Please select a pickup time slot for take-out orders.') {
    echo "✓ Rejected take-out without pickup slot with exact error.\n";
} else {
    echo "✗ Missing pickup slot validation failed: " . json_encode($noSlotRes) . "\n";
    exit(1);
}

// Attempt 2: Take-out with INVALID format pickup_time
$invalidSlotRes = apiRequest("$baseUrl/reservations", 'POST', [
    'fulfillment_type' => 'take_out',
    'pickup_time' => 'not-a-time',
    'items' => [
        ['menu_item_id' => $dishId, 'quantity' => 1]
    ]
], $cookieStudent);

echo "Invalid Pickup Slot Format Attempt: HTTP {$invalidSlotRes['status']}, Error: '{$invalidSlotRes['data']['error']}'\n";
if ($invalidSlotRes['status'] === 400 && $invalidSlotRes['data']['error'] === 'Invalid pickup time format. Must be HH:MM.') {
    echo "✓ Rejected invalid format with exact error.\n";
} else {
    echo "✗ Invalid pickup slot validation failed: " . json_encode($invalidSlotRes) . "\n";
    exit(1);
}

// Attempt 3: Valid Take-Out with pickup_time = '14:15'
$targetPickupTime = '14:15';
$validOrderRes = apiRequest("$baseUrl/reservations", 'POST', [
    'fulfillment_type' => 'take_out',
    'pickup_time' => $targetPickupTime,
    'items' => [
        ['menu_item_id' => $dishId, 'quantity' => 1]
    ]
], $cookieStudent);

if ($validOrderRes['status'] !== 200 && $validOrderRes['status'] !== 201) {
    echo "✗ Failed to create valid take-out order: HTTP {$validOrderRes['status']} - " . json_encode($validOrderRes['data']) . "\n";
    exit(1);
}
$orderId = (int)($validOrderRes['data']['order_id'] ?? 0);
echo "✓ Created Take-Out Order #$orderId with scheduled pickup: $targetPickupTime\n";

// DB Verification of pickup_time and default notification_status
$stmtCheck = $pdo->prepare("SELECT fulfillment_type, pickup_time, notification_status FROM orders WHERE id = ?");
$stmtCheck->execute([$orderId]);
$dbOrder = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ($dbOrder && $dbOrder['pickup_time'] === $targetPickupTime && $dbOrder['notification_status'] === 'none') {
    echo "✓ DB Verification PASSED: orders.pickup_time = '{$dbOrder['pickup_time']}', notification_status = '{$dbOrder['notification_status']}'\n";
} else {
    echo "✗ DB Verification FAILED: " . json_encode($dbOrder) . "\n";
    exit(1);
}

// 5. Vendor Live Queue & Ready Notification
echo "\n--- TEST STEP 4: Vendor Live Queue & Notify Ready Flow ---\n";
// Fetch vendor orders
$vendorOrdersRes = apiRequest("$baseUrl/vendor/orders", 'GET', null, $cookieVendor);
if ($vendorOrdersRes['status'] !== 200) {
    echo "✗ Failed to fetch vendor orders: HTTP {$vendorOrdersRes['status']}\n";
    exit(1);
}
$foundOrder = null;
foreach ($vendorOrdersRes['data'] as $vo) {
    if ((int)$vo['id'] === $orderId) {
        $foundOrder = $vo;
        break;
    }
}

if ($foundOrder && $foundOrder['pickup_time'] === $targetPickupTime && $foundOrder['notification_status'] === 'none') {
    echo "✓ Vendor Live Queue verified: Order #$orderId displays pickup_time '{$foundOrder['pickup_time']}' and status '{$foundOrder['notification_status']}'\n";
} else {
    echo "✗ Vendor Live Queue verification failed: " . json_encode($foundOrder) . "\n";
    exit(1);
}

// Vendor clicks "Notify Ready"
$notifyRes = apiRequest("$baseUrl/vendor/orders/notify-ready", 'POST', [
    'order_id' => $orderId
], $cookieVendor);

if ($notifyRes['status'] !== 200) {
    echo "✗ Failed to send notify ready: HTTP {$notifyRes['status']} - " . json_encode($notifyRes['data']) . "\n";
    exit(1);
}
echo "✓ Vendor POST /api/vendor/orders/notify-ready responded HTTP 200: '{$notifyRes['data']['message']}'\n";

// Verify DB updated to ready_sent
$stmtCheck->execute([$orderId]);
$dbOrderUpdated = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ($dbOrderUpdated && $dbOrderUpdated['notification_status'] === 'ready_sent') {
    echo "✓ DB State Verification PASSED: orders.notification_status successfully changed to 'ready_sent'!\n";
} else {
    echo "✗ DB State Verification FAILED: " . json_encode($dbOrderUpdated) . "\n";
    exit(1);
}

// 6. Security Check: Block direct web access to test script
echo "\n--- TEST STEP 5: Direct Web Access Block on tests/ ---\n";
$directWebRes = apiRequest("http://127.0.0.1/optimeal/tests/test_d6.php", 'GET');
echo "Direct web request HTTP Status: {$directWebRes['status']} (Expected: 403)\n";

if ($directWebRes['status'] === 403 || $directWebRes['status'] === 404) {
    echo "✓ Web access to test script denied ({$directWebRes['status']}).\n";
} else {
    echo "✗ Direct web access check failed: HTTP {$directWebRes['status']}\n";
    exit(1);
}

echo "\n============================================\n";
echo "✓ ALL PHASE D6 VERIFICATIONS PASSED CLEANLY!\n";
echo "============================================\n";
