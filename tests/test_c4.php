<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
// test_c4.php

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileVendor = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cookie_vendor_c4.txt';
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

echo "=== PHASE C4: AI QUERY CACHE VERIFICATION ===\n\n";

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

// Test dish name
$testDishName = "Cache Test Burger";
$sanitizedTestName = strtolower(trim($testDishName));

// Clear test dish from cache if previously present
$pdo->prepare("DELETE FROM ai_query_cache WHERE dish_name = ?")->execute([$sanitizedTestName]);

// 2. Call enrich-dish for the first time (Cache Miss -> LLM Call)
echo "\n2. Calling /api/vendor/enrich-dish for '{$testDishName}' (Cache Miss)...\n";
$t1_res = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => $testDishName,
    'description' => 'A juicy grilled burger with cheddar cheese and crispy lettuce'
], $cookieFileVendor);

$t1_time = $t1_res['duration_ms'];
echo "   HTTP Code: {$t1_res['code']}\n";
echo "   Response Time (T1): {$t1_time} ms\n";
echo "   Returned Ingredients: " . implode(', ', $t1_res['body']['ingredients'] ?? []) . "\n";
echo "   Returned Macros: " . json_encode($t1_res['body']['macros'] ?? []) . "\n";
assert($t1_res['code'] === 200, "Expected HTTP 200 on cache miss");
assert(!empty($t1_res['body']['ingredients']), "Expected non-empty ingredients");
assert(!empty($t1_res['body']['macros']), "Expected non-empty macros");

// 3. Verify row was inserted into ai_query_cache
echo "\n3. Verifying insertion into ai_query_cache table...\n";
$stmt = $pdo->prepare("SELECT * FROM ai_query_cache WHERE dish_name = ?");
$stmt->execute([$sanitizedTestName]);
$cachedRow = $stmt->fetch(PDO::FETCH_ASSOC);
assert($cachedRow !== false, "Row should be found in ai_query_cache");
echo "   Found cached row ID: {$cachedRow['id']}\n";
echo "   Cached dish_name: '{$cachedRow['dish_name']}'\n";
echo "   Cached response_payload: {$cachedRow['response_payload']}\n";

// 4. Call enrich-dish again with the exact same name (Cache Hit)
echo "\n4. Calling /api/vendor/enrich-dish again with '{$testDishName}' (Cache Hit)...\n";
$t2_res = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => $testDishName,
    'description' => 'Different description to ensure it uses dish_name key'
], $cookieFileVendor);

$t2_time = $t2_res['duration_ms'];
echo "   HTTP Code: {$t2_res['code']}\n";
echo "   Response Time (T2): {$t2_time} ms\n";
echo "   Returned Ingredients: " . implode(', ', $t2_res['body']['ingredients'] ?? []) . "\n";
echo "   Returned Macros: " . json_encode($t2_res['body']['macros'] ?? []) . "\n";
assert($t2_res['code'] === 200, "Expected HTTP 200 on cache hit");
assert($t2_res['body'] == $t1_res['body'], "Cache hit response must be identical to cache miss response");

// 5. Test with mixed case / whitespace (e.g. "  cache test BURGER  ")
echo "\n5. Calling /api/vendor/enrich-dish with case variation '  cache test BURGER  '...\n";
$t3_res = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => "  cache test BURGER  "
], $cookieFileVendor);
$t3_time = $t3_res['duration_ms'];
echo "   HTTP Code: {$t3_res['code']}\n";
echo "   Response Time (T3): {$t3_time} ms\n";
assert($t3_res['code'] === 200, "Expected HTTP 200 on case-insensitive cache hit");
assert($t3_res['body'] == $t1_res['body'], "Case-insensitive response must match cached response");

// Summary
echo "\n=== PERFORMANCE COMPARISON ===\n";
echo "   Cache Miss (T1 - LLM Call): {$t1_time} ms\n";
echo "   Cache Hit  (T2 - DB Lookup): {$t2_time} ms\n";
echo "   Cache Hit  (T3 - Case/Trim): {$t3_time} ms\n";
$speedup = round($t1_time / max(1, $t2_time), 1);
echo "   Speedup: ~{$speedup}x faster!\n\n";
echo "=== ALL VERIFICATIONS PASSED SUCCESSFULLY! ===\n";

if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
