<?php
// test_c5.php

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileVendor = __DIR__ . '/cookie_vendor_c5.txt';
if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);

require_once __DIR__ . '/../api/db.php';

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

echo "=== PHASE C5: AI PROMPT HARDENING & ERROR STATE VERIFICATION ===\n\n";

// 1. Vendor Login
echo "1. Logging in as Vendor...\n";
$login = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'testvendor@optimeal.test',
    'password' => 'password123'
], $cookieFileVendor);
echo "   Status: {$login['code']}, Role: " . ($login['body']['role'] ?? 'Failed') . "\n";
assert($login['code'] === 200, "Vendor login failed");

// Ensure ai_enabled is 1
$pdo->query("UPDATE settings SET ai_enabled = 1 WHERE id = 1");

// 2. Test Gibberish Dish ("asdf")
echo "\n2. Testing Gibberish Dish ('asdf')...\n";
$pdo->prepare("DELETE FROM ai_query_cache WHERE dish_name = 'asdf'")->execute();

$gibberishRes = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => 'asdf',
    'description' => 'Random typing test'
], $cookieFileVendor);

echo "   HTTP Code: {$gibberishRes['code']}\n";
echo "   Raw Response Body: {$gibberishRes['raw']}\n";
assert($gibberishRes['code'] === 400, "Expected HTTP 400 for gibberish dish");
assert(!empty($gibberishRes['body']['error']), "Expected error key in response");
echo "   ==> PASS: Received HTTP 400 with AI error: \"{$gibberishRes['body']['error']}\"\n";

// 3. Verify "asdf" is NOT in ai_query_cache
echo "\n3. Checking ai_query_cache for 'asdf'...\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_query_cache WHERE dish_name = 'asdf'");
$stmt->execute();
$cachedGibberish = (int)$stmt->fetchColumn();
echo "   Cached 'asdf' count: $cachedGibberish (must be 0)\n";
assert($cachedGibberish === 0, "Failed generations must NOT be cached");
echo "   ==> PASS: No garbage cached in ai_query_cache!\n";

// 4. Test Legitimate Composite Dish ("Chicken Sandwich")
echo "\n4. Testing Legitimate Composite Dish ('Chicken Sandwich')...\n";
sleep(3);
$pdo->prepare("DELETE FROM ai_query_cache WHERE dish_name = 'chicken sandwich'")->execute();

$foodRes = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => 'Chicken Sandwich',
    'description' => 'Fried chicken patty with mayonnaise and pickles on a bun'
], $cookieFileVendor);

echo "   HTTP Code: {$foodRes['code']}\n";
echo "   Raw Response Body: {$foodRes['raw']}\n";
assert($foodRes['code'] === 200, "Expected HTTP 200 for valid food");

$ingredients = $foodRes['body']['ingredients'] ?? [];
echo "   Decomposed Ingredients: " . json_encode($ingredients) . "\n";
echo "   Macros: " . json_encode($foodRes['body']['macros'] ?? []) . "\n";

assert(is_array($ingredients) && count($ingredients) >= 2, "Expected multiple decomposed ingredients");
assert(isset($foodRes['body']['macros']['calories']), "Expected calories in macros");
echo "   ==> PASS: Composite ingredients properly broken down!\n";

// 5. Verify "chicken sandwich" IS in ai_query_cache
echo "\n5. Checking ai_query_cache for 'chicken sandwich'...\n";
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ai_query_cache WHERE dish_name = 'chicken sandwich'");
$stmt->execute();
$cachedFood = (int)$stmt->fetchColumn();
echo "   Cached 'chicken sandwich' count: $cachedFood (must be 1)\n";
assert($cachedFood === 1, "Successful generations must be cached");
echo "   ==> PASS: Valid generation cached successfully!\n";

echo "\n=== ALL VERIFICATIONS PASSED SUCCESSFULLY! ===\n";

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
