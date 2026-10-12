<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D8.1 Automated Verification Suite
 * Tests:
 * - A2: syncDishIngredientLinks establishes single source of truth; ingredients_text saved on menu_items.
 * - A2: Saving a recipe does NOT wipe out dish-level allergens.
 * - A1: Checkout defense: Unmapped recipe ingredients and ingredients_text are merged into allergen scan.
 * - A1: Unmapped ingredients force "Needs Review" safety restriction (HTTP 400) when student has declared allergens.
 * - A1: Passing allergen_override: true allows proceeding and records allergen_override = 1 on the order.
 * - A1: Students with no declared allergens are not blocked by unmapped ingredients.
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

// Ensure D8 migration is applied
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM menu_items LIKE 'ingredients_text'");
    if (!$colCheck->fetch()) {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN ingredients_text TEXT NULL");
        echo "✓ D8 Migration applied: added ingredients_text column to menu_items\n";
    }
} catch (Exception $e) {
    echo "Notice: Migration check - " . $e->getMessage() . "\n";
}

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d8_1.txt';
$cookieStudent = $tempDir . '/cookie_student_d8_1.txt';
if (file_exists($cookieVendor)) @unlink($cookieVendor);
if (file_exists($cookieStudent)) @unlink($cookieStudent);

function d8ApiRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
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
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

echo "=== PHASE D8.1 ALLERGEN SAFETY AUTOMATED VERIFICATION ===\n\n";

// 1. Authenticate Vendor & Student
$vLogin = d8ApiRequest("$baseUrl/auth/login", 'POST', ['email' => 'canteen@optimeal.test', 'password' => 'password123'], $cookieVendor);
if ($vLogin['status'] !== 200) {
    echo "✗ Vendor login failed: HTTP {$vLogin['status']}\n";
    exit(1);
}
echo "✓ 1. Vendor Authenticated (Store 1)\n";

$sLogin = d8ApiRequest("$baseUrl/auth/login", 'POST', ['email' => 'student@optimeal.test', 'password' => 'password123'], $cookieStudent);
if ($sLogin['status'] !== 200) {
    echo "✗ Student login failed: HTTP {$sLogin['status']}\n";
    exit(1);
}
echo "✓ 2. Student Authenticated\n\n";

$testDishIds = [];
$testOrderIds = [];
$origSettings = null;

$stmtSettings = $pdo->query("SELECT closing_time, opening_time FROM settings WHERE id = 1");
$origSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE settings SET closing_time = '23:45', opening_time = '06:00' WHERE id = 1");

try {
    // -------------------------------------------------------------
    // TEST 1: Single Source of Truth - Save dish with dish-level ingredients
    // -------------------------------------------------------------
    echo "--- TEST 1: Dish Save & Mapped Allergens (A2) ---\n";
    $dishPayload1 = [
        'name' => 'D8 Adobo Master Viand',
        'category' => 'Ulam / Stews',
        'base_price' => 75.00,
        'ingredientsString' => 'Pork, Soy Sauce, Garlic',
        'macros' => ['calories' => 380, 'protein_g' => 28, 'carbs_g' => 5, 'fat_g' => 20, 'sodium_mg' => 450],
        'ai_confirmed' => true
    ];
    $dishRes1 = d8ApiRequest("$baseUrl/vendor/dish", 'POST', $dishPayload1, $cookieVendor);
    if ($dishRes1['status'] !== 200 || empty($dishRes1['data']['id'])) {
        throw new Exception("Failed to save dish: " . json_encode($dishRes1));
    }
    $dishId1 = (int)$dishRes1['data']['id'];
    $testDishIds[] = $dishId1;

    // Verify ingredients_text is stored in DB
    $stmtCheck = $pdo->prepare("SELECT ingredients_text FROM menu_items WHERE id = ?");
    $stmtCheck->execute([$dishId1]);
    $savedText = $stmtCheck->fetchColumn();
    if (strpos($savedText, 'Soy Sauce') === false) {
        throw new Exception("ingredients_text not saved correctly: '$savedText'");
    }
    echo "✓ ingredients_text successfully stored in menu_items: '$savedText'\n";

    // Verify critical allergen Soy is mapped
    $stmtAllergens = $pdo->prepare("
        SELECT ca.name 
        FROM menu_item_critical_allergens mica 
        JOIN critical_allergens ca ON mica.allergen_id = ca.id 
        WHERE mica.menu_item_id = ?
    ");
    $stmtAllergens->execute([$dishId1]);
    $allergens1 = $stmtAllergens->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('Soy', $allergens1)) {
        throw new Exception("Expected allergen 'Soy' from 'Soy Sauce', got: " . json_encode($allergens1));
    }
    echo "✓ Mapped allergens correctly linked: " . implode(', ', $allergens1) . "\n\n";

    // -------------------------------------------------------------
    // TEST 2: Saving Recipe Does NOT Wipe Out Dish-Level Allergens (A2)
    // -------------------------------------------------------------
    echo "--- TEST 2: Recipe Save Preserves Dish Allergens (A2) ---\n";
    // Recipe contains Pork, Water, Salt (NO Soy Sauce)
    $recipePayload = [
        'menu_item_id' => $dishId1,
        'yield_servings' => 10,
        'instructions' => 'Simmer pork with spices until tender.',
        'confirmed' => true,
        'ingredients' => [
            ['name' => 'Pork', 'quantity' => 1, 'unit' => 'kg', 'unit_cost' => 280],
            ['name' => 'Water', 'quantity' => 500, 'unit' => 'ml', 'unit_cost' => 0]
        ]
    ];
    $recipeRes = d8ApiRequest("$baseUrl/vendor/dish/recipe", 'POST', $recipePayload, $cookieVendor);
    if ($recipeRes['status'] !== 200) {
        throw new Exception("Failed to save recipe: " . json_encode($recipeRes));
    }
    echo "✓ Recipe saved for dish #$dishId1\n";

    // Re-check critical allergens for dishId1: MUST STILL CONTAIN Soy!
    $stmtAllergens->execute([$dishId1]);
    $allergensAfterRecipe = $stmtAllergens->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('Soy', $allergensAfterRecipe)) {
        throw new Exception("CRITICAL FAILURE: Saving recipe wiped out dish-level 'Soy' allergen! Got: " . json_encode($allergensAfterRecipe));
    }
    echo "✓ SUCCESS: Dish-level 'Soy' allergen was PRESERVED after recipe save! Mapped allergens: " . implode(', ', $allergensAfterRecipe) . "\n\n";

    // -------------------------------------------------------------
    // TEST 3: Unmapped Ingredient Dish & Tracking (A1)
    // -------------------------------------------------------------
    echo "--- TEST 3: Dish with Unmapped Ingredient (A1) ---\n";
    $dishPayload2 = [
        'name' => 'D8 Mystery Viand',
        'category' => 'Sabaw / Soup',
        'base_price' => 80.00,
        'ingredientsString' => 'Pork, Special Mystery Alamang Paste',
        'macros' => ['calories' => 300, 'protein_g' => 20, 'carbs_g' => 2, 'fat_g' => 15, 'sodium_mg' => 400],
        'ai_confirmed' => true
    ];
    $dishRes2 = d8ApiRequest("$baseUrl/vendor/dish", 'POST', $dishPayload2, $cookieVendor);
    if ($dishRes2['status'] !== 200 || empty($dishRes2['data']['id'])) {
        throw new Exception("Failed to save mystery dish: " . json_encode($dishRes2));
    }
    $dishId2 = (int)$dishRes2['data']['id'];
    $testDishIds[] = $dishId2;

    // Put stock in inventory for checkout tests
    $pdo->prepare("UPDATE inventory SET online_pool_qty = 10, walkin_pool_qty = 10, delisted_from_app = 0 WHERE menu_item_id = ?")->execute([$dishId2]);

    // Check ingredient_submissions table has recorded the unmapped item
    $subStmt = $pdo->prepare("SELECT COUNT(*) FROM ingredient_submissions WHERE raw_name = ?");
    $subStmt->execute(['Special Mystery Alamang Paste']);
    if ((int)$subStmt->fetchColumn() === 0) {
        throw new Exception("Unmapped ingredient was not added to ingredient_submissions queue");
    }
    echo "✓ Unmapped ingredient 'Special Mystery Alamang Paste' queued into ingredient_submissions\n\n";

    // -------------------------------------------------------------
    // TEST 4: Checkout Defense for Student with Declared Allergens (A1)
    // -------------------------------------------------------------
    echo "--- TEST 4: Student Checkout Defense Block (A1) ---\n";
    // Student user ID for student@optimeal.test
    $stmtStudentId = $pdo->query("SELECT id FROM users WHERE email = 'student@optimeal.test'");
    $studentId = (int)$stmtStudentId->fetchColumn();

    // Set student profile allergens to ["Shellfish / Crustaceans"]
    $pdo->prepare("
        INSERT INTO student_profiles (user_id, allergens) 
        VALUES (?, '[\"Shellfish / Crustaceans\"]') 
        ON DUPLICATE KEY UPDATE allergens = VALUES(allergens)
    ")->execute([$studentId]);

    // Attempt reservation without override
    $resPayloadBlocked = [
        'items' => [
            ['menu_item_id' => $dishId2, 'quantity' => 1]
        ]
    ];
    $resBlocked = d8ApiRequest("$baseUrl/reservations", 'POST', $resPayloadBlocked, $cookieStudent);
    if ($resBlocked['status'] !== 400) {
        throw new Exception("Expected HTTP 400 Safety Restriction block, got HTTP {$resBlocked['status']}: " . json_encode($resBlocked));
    }
    $errorMsg = $resBlocked['data']['error'] ?? '';
    if (strpos($errorMsg, 'Safety Restriction') === false) {
        throw new Exception("Expected error to contain 'Safety Restriction', got: '$errorMsg'");
    }
    echo "✓ Checkout correctly BLOCKED with HTTP 400:\n  $errorMsg\n\n";

    // -------------------------------------------------------------
    // TEST 5: Student Acknowledges Risk via allergen_override (A1)
    // -------------------------------------------------------------
    echo "--- TEST 5: Override Risk & Safety Tagging on Order (A1) ---\n";
    $resPayloadOverride = [
        'items' => [
            ['menu_item_id' => $dishId2, 'quantity' => 1]
        ],
        'allergen_override' => true
    ];
    $resOverride = d8ApiRequest("$baseUrl/reservations", 'POST', $resPayloadOverride, $cookieStudent);
    if ($resOverride['status'] !== 200 || empty($resOverride['data']['order_id'])) {
        throw new Exception("Reservation with override failed: " . json_encode($resOverride));
    }
    $orderId = (int)$resOverride['data']['order_id'];
    $testOrderIds[] = $orderId;

    // Check order in database has allergen_override = 1 and conflicts recorded
    $stmtOrder = $pdo->prepare("SELECT allergen_override, allergen_conflicts FROM orders WHERE id = ?");
    $stmtOrder->execute([$orderId]);
    $orderRow = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if ((int)$orderRow['allergen_override'] !== 1) {
        throw new Exception("Expected order.allergen_override = 1, got: " . var_export($orderRow['allergen_override'], true));
    }
    if (empty($orderRow['allergen_conflicts'])) {
        throw new Exception("Expected order.allergen_conflicts to contain conflict JSON, got empty");
    }
    echo "✓ Order #$orderId created with allergen_override = 1\n";
    echo "✓ Recorded conflicts on order: {$orderRow['allergen_conflicts']}\n\n";

    // -------------------------------------------------------------
    // TEST 6: Student with NO Declared Allergens Proceeds Without Block (A1)
    // -------------------------------------------------------------
    echo "--- TEST 6: Student with NO Allergens Proceeds Smoothly (A1) ---\n";
    // Set student profile allergens to []
    $pdo->prepare("UPDATE student_profiles SET allergens = '[]' WHERE user_id = ?")->execute([$studentId]);

    $resPayloadClear = [
        'items' => [
            ['menu_item_id' => $dishId2, 'quantity' => 1]
        ]
    ];
    $resClear = d8ApiRequest("$baseUrl/reservations", 'POST', $resPayloadClear, $cookieStudent);
    if ($resClear['status'] !== 200 || empty($resClear['data']['order_id'])) {
        throw new Exception("Expected student with no allergens to proceed, got: " . json_encode($resClear));
    }
    $orderId2 = (int)$resClear['data']['order_id'];
    $testOrderIds[] = $orderId2;

    $stmtOrder2 = $pdo->prepare("SELECT allergen_override FROM orders WHERE id = ?");
    $stmtOrder2->execute([$orderId2]);
    $overrideVal2 = (int)$stmtOrder2->fetchColumn();
    if ($overrideVal2 !== 0) {
        throw new Exception("Expected allergen_override = 0 for diner without allergens, got: $overrideVal2");
    }
    echo "✓ Order #$orderId2 created without block and allergen_override = 0\n\n";

    echo "========================================================\n";
    echo "✓ ALL PHASE D8.1 AUTOMATED TESTS PASSED SUCCESSFULLY!\n";
    echo "========================================================\n";

} finally {
    // Cleanup test artifacts
    echo "\nCleaning up test artifacts...\n";
    if (!empty($testOrderIds)) {
        $inO = implode(',', $testOrderIds);
        $pdo->exec("DELETE FROM payments WHERE order_id IN ($inO)");
        $pdo->exec("DELETE FROM order_items WHERE order_id IN ($inO)");
        $pdo->exec("DELETE FROM orders WHERE id IN ($inO)");
        echo "✓ Cleaned test orders\n";
    }
    if (!empty($testDishIds)) {
        $inD = implode(',', $testDishIds);
        $pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id IN (SELECT id FROM recipes WHERE menu_item_id IN ($inD))");
        $pdo->exec("DELETE FROM recipes WHERE menu_item_id IN ($inD)");
        $pdo->exec("DELETE FROM pricing_rules WHERE menu_item_id IN ($inD)");
        $pdo->exec("DELETE FROM inventory WHERE menu_item_id IN ($inD)");
        $pdo->exec("DELETE FROM menu_item_critical_allergens WHERE menu_item_id IN ($inD)");
        $pdo->exec("DELETE FROM menu_item_ingredients WHERE menu_item_id IN ($inD)");
        $pdo->exec("DELETE FROM menu_items WHERE id IN ($inD)");
        echo "✓ Cleaned test menu items\n";
    }
    $pdo->exec("DELETE FROM ingredient_submissions WHERE raw_name = 'Special Mystery Alamang Paste'");
    if (file_exists($cookieVendor)) @unlink($cookieVendor);
    if (file_exists($cookieStudent)) @unlink($cookieStudent);
    if ($origSettings) {
        $restoreClosing = ($origSettings['closing_time'] === '15:00') ? '20:00' : $origSettings['closing_time'];
        $pdo->prepare("UPDATE settings SET closing_time = ?, opening_time = ? WHERE id = 1")->execute([$restoreClosing, $origSettings['opening_time']]);
    }
    echo "✓ Cleanup complete.\n";
}
