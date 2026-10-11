<?php
// test_c3.php

$baseUrl = 'http://127.0.0.1/optimeal/api';
$cookieFileAdmin = __DIR__ . '/cookie_admin.txt';
$cookieFileVendor = __DIR__ . '/cookie_vendor.txt';

if (file_exists($cookieFileAdmin)) unlink($cookieFileAdmin);
if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);

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
    return ['code' => $httpCode, 'body' => json_decode($response, true), 'raw' => $response];
}

echo "=== PHASE C3 TEST SUITE ===\n\n";

// 1. Admin Login
echo "1. Admin Login...\n";
$loginAdmin = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'admin@optimeal.test',
    'password' => 'password123'
], $cookieFileAdmin);
echo "   Status: {$loginAdmin['code']}, Role: " . ($loginAdmin['body']['role'] ?? 'Failed') . "\n";
assert($loginAdmin['code'] === 200, "Admin login failed");

// 2. Set ai_enabled = 0
echo "\n2. Setting AI Kill Switch to OFF (ai_enabled = 0)...\n";
$updateSettings = apiRequest("$baseUrl/admin/settings", 'POST', [
    'reservation_ttl' => 45,
    'opening_time' => '07:00',
    'closing_time' => '23:30',
    'max_discount_qty' => 5,
    'default_decay_rate' => 10.00,
    'ai_enabled' => 0
], $cookieFileAdmin);
echo "   Status: {$updateSettings['code']}, Msg: " . ($updateSettings['body']['message'] ?? $updateSettings['raw']) . "\n";
assert($updateSettings['code'] === 200, "Failed to update settings");

// 3. Vendor Login
echo "\n3. Vendor Login...\n";
$loginVendor = apiRequest("$baseUrl/auth/login", 'POST', [
    'email' => 'testvendor@optimeal.test',
    'password' => 'password123'
], $cookieFileVendor);
echo "   Status: {$loginVendor['code']}, Role: " . ($loginVendor['body']['role'] ?? 'Failed') . "\n";
assert($loginVendor['code'] === 200, "Vendor login failed");

// 4. Vendor attempts to enrich dish with AI while ai_enabled == 0
echo "\n4. Vendor calls /api/vendor/enrich-dish while AI switch is OFF...\n";
$enrichAttempt = apiRequest("$baseUrl/vendor/enrich-dish", 'POST', [
    'name' => 'Cybernetic Test Stew',
    'description' => 'A futuristic test dish'
], $cookieFileVendor);
echo "   HTTP Code: {$enrichAttempt['code']}\n";
echo "   Response: {$enrichAttempt['raw']}\n";
assert($enrichAttempt['code'] === 403, "Expected HTTP 403, got {$enrichAttempt['code']}");
assert(
    isset($enrichAttempt['body']['error']) && $enrichAttempt['body']['error'] === 'AI Generation is currently disabled by the Administrator.',
    "Expected exact error message"
);
echo "   ==> PASS: AI Kill Switch blocked vendor generation with HTTP 403!\n";

// 5. Admin turns AI back ON
echo "\n5. Setting AI Kill Switch back to ON (ai_enabled = 1)...\n";
$enableAI = apiRequest("$baseUrl/admin/settings", 'POST', [
    'reservation_ttl' => 45,
    'opening_time' => '07:00',
    'closing_time' => '23:30',
    'max_discount_qty' => 5,
    'default_decay_rate' => 10.00,
    'ai_enabled' => 1
], $cookieFileAdmin);
echo "   Status: {$enableAI['code']}, Msg: " . ($enableAI['body']['message'] ?? $enableAI['raw']) . "\n";
assert($enableAI['code'] === 200, "Failed to re-enable AI");

require_once __DIR__ . '/../api/db.php';
$pdo->exec("DELETE FROM ingredient_submissions WHERE raw_name IN ('Cybernetic Tomatoes', 'Quantum Pepper')");

// 6. Vendor saves a dish with recognized + unrecognized ingredients (e.g. 'Cybernetic Tomatoes')
echo "\n6. Vendor saving dish with unrecognized ingredient 'Cybernetic Tomatoes'...\n";
$saveDish = apiRequest("$baseUrl/vendor/dish", 'POST', [
    'name' => 'Futuristic Pinakbet with Cybernetic Tomatoes',
    'description' => 'A test viand with simulated cyber vegetables',
    'category' => 'Ulam / Stews',
    'base_price' => 75.00,
    'ingredientsString' => 'Soy Sauce, Cybernetic Tomatoes, Egg Noodles, Quantum Pepper',
    'macros' => [
        'calories' => 420,
        'protein_g' => 20,
        'carbs_g' => 40,
        'fat_g' => 10,
        'sodium_mg' => 550
    ],
    'ai_confirmed' => true
], $cookieFileVendor);

echo "   Status: {$saveDish['code']}\n";
echo "   Response: {$saveDish['raw']}\n";
assert($saveDish['code'] === 200, "Failed to save dish: {$saveDish['raw']}");
$savedDishId = $saveDish['body']['id'] ?? null;
echo "   Saved Dish ID: $savedDishId\n";

// 7. Inspect database directly using PDO
echo "\n7. Verifying Taxonomy Interception in Database...\n";

// Check ingredient_submissions
$stmt = $pdo->prepare("SELECT * FROM ingredient_submissions WHERE raw_name IN ('Cybernetic Tomatoes', 'Quantum Pepper')");
$stmt->execute();
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "   Submissions found in ingredient_submissions: " . count($submissions) . "\n";
foreach ($submissions as $sub) {
    echo "     - ID: {$sub['id']}, Raw Name: '{$sub['raw_name']}', Status: '{$sub['status']}'\n";
    assert($sub['status'] === 'pending', "Status must be pending");
}
assert(count($submissions) === 2, "Both Cybernetic Tomatoes and Quantum Pepper should be in ingredient_submissions");

// Verify that 'Cybernetic Tomatoes' was NOT added to the ingredients table
$stmt = $pdo->prepare("SELECT COUNT(*) FROM ingredients WHERE name IN ('Cybernetic Tomatoes', 'Quantum Pepper')");
$stmt->execute();
$fakeCount = (int)$stmt->fetchColumn();
echo "   Unrecognized ingredients in official 'ingredients' table: $fakeCount (must be 0)\n";
assert($fakeCount === 0, "Unrecognized ingredients should NOT be added to ingredients table");

// Verify menu_item_ingredients for this dish only mapped recognized ingredients
$stmt = $pdo->prepare("
    SELECT i.name 
    FROM menu_item_ingredients mii
    JOIN ingredients i ON mii.ingredient_id = i.id
    WHERE mii.menu_item_id = ?
");
$stmt->execute([$savedDishId]);
$mappedIngredients = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "   Recognized ingredients mapped to dish #$savedDishId: " . implode(', ', $mappedIngredients) . "\n";
assert(!in_array('Cybernetic Tomatoes', $mappedIngredients), "Cybernetic Tomatoes must NOT be mapped to dish");
assert(!in_array('Quantum Pepper', $mappedIngredients), "Quantum Pepper must NOT be mapped to dish");
assert(in_array('Soy Sauce', $mappedIngredients) || in_array('Egg Noodles', $mappedIngredients), "At least recognized ingredients should be mapped");

echo "\n=== ALL VERIFICATIONS PASSED SUCCESSFULLY! ===\n";

if (file_exists($cookieFileAdmin)) unlink($cookieFileAdmin);
if (file_exists($cookieFileVendor)) unlink($cookieFileVendor);
