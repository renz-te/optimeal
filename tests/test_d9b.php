<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D9b Automated Verification Suite
 * Tests:
 * 1. Bulk Pricing:
 *    - Range rejection (e.g., +150%, -60% rejected with HTTP 422)
 *    - <=0 price rejection (items dropping to <= 0 rejected with HTTP 422)
 *    - Successful scoped apply (scope = 'selected', applies to target item only)
 * 2. Rollback:
 *    - Rollback warning / non-contiguous defense when changed since (HTTP 422)
 *    - Explicit confirm flag / contiguous rollback success (HTTP 200)
 *    - Block rollback-of-a-rollback (is_rolled_back guard rejected with HTTP 422)
 * 3. Prep Plan Cost Calculation:
 *    - handleGetPrepPlan partial total (missing prices labeled partial with null est. cost)
 *    - handleGetPrepPlan complete total (all ingredients costed, is_partial = false)
 * 4. Closing Time Override:
 *    - Override closing time evaluated by getEffectiveClosingTime
 *    - Past override closing time enforces closed state on customer endpoints
 *    - Future override closing time propagates effective time
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/PricingEngine.php';

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d9b_' . bin2hex(random_bytes(4)) . '.txt';

if (file_exists($cookieVendor)) @unlink($cookieVendor);

function d9bApiRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
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

    return [
        'status' => $status,
        'data' => json_decode($response, true) ?: [],
        'raw' => $response
    ];
}

echo "=== PHASE D9b AUTOMATED REGRESSION & FEATURE VERIFICATION ===\n\n";

$testError = null;
$createdDishIds = [];
$createdCostIds = [];
$createdHistoryIds = [];
$originalSettings = null;

try {
    // 0. Backup original settings
    $stmtOrig = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $originalSettings = $stmtOrig->fetch(PDO::FETCH_ASSOC);

    // 1. Authenticate Vendor
    echo "--- 1. Authenticating Vendor Session ---\n";
    $vLogin = d9bApiRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'canteen@optimeal.test',
        'password' => 'password123'
    ], $cookieVendor);

    if ($vLogin['status'] !== 200) {
        throw new Exception("Vendor authentication failed with status {$vLogin['status']}: {$vLogin['raw']}");
    }
    echo "✓ Vendor successfully authenticated (Store 1).\n\n";

    // =========================================================================
    // SECTION 1: Bulk Pricing Validation & Scoped Apply
    // =========================================================================
    echo "--- SECTION 1: Bulk Pricing Validation ---\n";

    // 1a. Range rejection (+150%)
    echo "1a. Testing upper range rejection (+150%):\n";
    $highRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => 150
    ], $cookieVendor);
    if ($highRes['status'] !== 422) {
        throw new Exception("Expected HTTP 422 for +150% adjustment, got {$highRes['status']}: {$highRes['raw']}");
    }
    echo "    ✓ Upper range rejection (+150%) rejected with HTTP 422: {$highRes['data']['error']}\n";

    // 1b. Range rejection (-60%)
    echo "1b. Testing lower range rejection (-60%):\n";
    $lowRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => -60
    ], $cookieVendor);
    if ($lowRes['status'] !== 422) {
        throw new Exception("Expected HTTP 422 for -60% adjustment, got {$lowRes['status']}: {$lowRes['raw']}");
    }
    echo "    ✓ Lower range rejection (-60%) rejected with HTTP 422: {$lowRes['data']['error']}\n";

    // 1c. <= 0 Price Rejection
    echo "1c. Testing <= 0 price rejection on zero/negative outcome:\n";
    $pdo->prepare("INSERT INTO vendor_ingredient_costs (store_id, ingredient_name, cost, cost_unit) VALUES (1, 'D9b Free Spice', 0.00, 'g')")->execute();
    $zeroCostId = (int)$pdo->lastInsertId();
    $createdCostIds[] = $zeroCostId;

    $zeroApplyRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => 10,
        'scope' => 'selected',
        'ingredient_ids' => [$zeroCostId]
    ], $cookieVendor);

    if ($zeroApplyRes['status'] !== 422) {
        throw new Exception("Expected HTTP 422 for <= 0 price calculation, got {$zeroApplyRes['status']}: {$zeroApplyRes['raw']}");
    }
    if (strpos($zeroApplyRes['data']['error'] ?? '', 'zero or negative') === false) {
        throw new Exception("Error message did not mention zero or negative: " . json_encode($zeroApplyRes['data']));
    }
    echo "    ✓ <= 0 price rejection correctly blocked with HTTP 422: {$zeroApplyRes['data']['error']}\n";

    // 1d. Successful Scoped Apply
    echo "1d. Testing successful scoped bulk apply (+20%):\n";
    $pdo->prepare("INSERT INTO vendor_ingredient_costs (store_id, ingredient_name, cost, cost_unit) VALUES (1, 'D9b Scoped Herb', 50.00, 'kg')")->execute();
    $scopedHerbId = (int)$pdo->lastInsertId();
    $createdCostIds[] = $scopedHerbId;

    $scopedRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => 20,
        'scope' => 'selected',
        'ingredient_ids' => [$scopedHerbId]
    ], $cookieVendor);

    if ($scopedRes['status'] !== 200 || empty($scopedRes['data']['batch_id'])) {
        throw new Exception("Scoped bulk apply failed: status {$scopedRes['status']}, raw: {$scopedRes['raw']}");
    }
    $batchAId = $scopedRes['data']['batch_id'];

    $checkCostA = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE id = $scopedHerbId")->fetchColumn();
    if (abs($checkCostA - 60.00) > 0.01) {
        throw new Exception("Expected scoped herb cost to be 60.00, got $checkCostA");
    }
    echo "    ✓ Scoped bulk apply succeeded (+20% on ID $scopedHerbId: ₱50.00 -> ₱60.00, Batch: $batchAId).\n\n";

    // =========================================================================
    // SECTION 2: Rollback Defense & Explicit Confirmation
    // =========================================================================
    echo "--- SECTION 2: Rollback Defense & Confirmation ---\n";

    // Apply Batch B to the same item to make Batch A "changed since"
    $batchBRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => 10,
        'scope' => 'selected',
        'ingredient_ids' => [$scopedHerbId]
    ], $cookieVendor);

    if ($batchBRes['status'] !== 200 || empty($batchBRes['data']['batch_id'])) {
        throw new Exception("Batch B apply failed: status {$batchBRes['status']}, raw: {$batchBRes['raw']}");
    }
    $batchBId = $batchBRes['data']['batch_id'];
    $checkCostB = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE id = $scopedHerbId")->fetchColumn();
    echo "    ✓ Subsequent adjustment Batch B applied (+10%: ₱60.00 -> ₱$checkCostB, Batch: $batchBId).\n";

    // 2a. Rollback warning / non-contiguous rejection (Changed Since)
    echo "2a. Testing non-contiguous rollback rejection on older Batch A:\n";
    $nonContiguousRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $batchAId,
        'confirm' => true
    ], $cookieVendor);

    if ($nonContiguousRes['status'] !== 422) {
        throw new Exception("Expected HTTP 422 when rolling back older batch with active subsequent change, got {$nonContiguousRes['status']}: {$nonContiguousRes['raw']}");
    }
    if (strpos($nonContiguousRes['data']['error'] ?? '', 'subsequent active price adjustment') === false) {
        throw new Exception("Unexpected error message for changed-since rollback: " . json_encode($nonContiguousRes['data']));
    }
    echo "    ✓ Older rollback attempt rejected with HTTP 422: {$nonContiguousRes['data']['error']}\n";

    // 2b. Contiguous Rollback with explicit confirmation flag
    echo "2b. Testing contiguous rollback of Batch B and Batch A with confirm flag:\n";
    $rollB = d9bApiRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $batchBId,
        'confirm' => true
    ], $cookieVendor);

    if ($rollB['status'] !== 200) {
        throw new Exception("Contiguous rollback of Batch B failed: status {$rollB['status']}, raw: {$rollB['raw']}");
    }

    $rollA = d9bApiRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $batchAId,
        'confirm' => true
    ], $cookieVendor);

    if ($rollA['status'] !== 200) {
        throw new Exception("Rollback of Batch A failed: status {$rollA['status']}, raw: {$rollA['raw']}");
    }

    $restoredCost = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE id = $scopedHerbId")->fetchColumn();
    if (abs($restoredCost - 50.00) > 0.01) {
        throw new Exception("Expected restored cost 50.00, got $restoredCost");
    }
    echo "    ✓ Sequential rollbacks succeeded. Cost cleanly restored to baseline ₱50.00.\n";

    // 2c. Block Rollback-of-a-Rollback (is_rolled_back Guard)
    echo "2c. Testing block on rollback-of-a-rollback (is_rolled_back = 1):\n";
    $doubleRollRes = d9bApiRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $batchAId,
        'confirm' => true
    ], $cookieVendor);

    if ($doubleRollRes['status'] !== 422) {
        throw new Exception("Expected HTTP 422 for double rollback attempt, got {$doubleRollRes['status']}: {$doubleRollRes['raw']}");
    }
    if (strpos($doubleRollRes['data']['error'] ?? '', 'already been rolled back') === false) {
        throw new Exception("Unexpected error message on double rollback: " . json_encode($doubleRollRes['data']));
    }
    echo "    ✓ Double-rollback attempt cleanly blocked with HTTP 422: {$doubleRollRes['data']['error']}\n\n";

    // =========================================================================
    // SECTION 3: Prep Plan Cost Total (Complete & Partial Coverage)
    // =========================================================================
    echo "--- SECTION 3: Prep Plan Cost Calculation ---\n";

    // Create test viand
    $pdo->prepare("
        INSERT INTO menu_items (store_id, name, description, category, base_price, price_floor, is_active)
        VALUES (1, 'D9b Test Sinigang', 'Verification Dish', 'Ulam / Stews', 85.00, 60.00, 1)
    ")->execute();
    $testDishId = (int)$pdo->lastInsertId();
    $createdDishIds[] = $testDishId;

    // Create recipe (yield 10 servings)
    $pdo->prepare("
        INSERT INTO recipes (menu_item_id, yield_servings, instructions)
        VALUES (?, 10, 'Boil ingredients until tender.')
    ")->execute([$testDishId]);
    $testRecipeId = (int)$pdo->lastInsertId();

    // Ingredient 1: Costed ingredient (500g)
    $pdo->prepare("
        INSERT INTO recipe_ingredients (recipe_id, ingredient_name, quantity, unit)
        VALUES (?, 'D9b Pork Belly', 500, 'g')
    ")->execute([$testRecipeId]);

    // Ingredient 2: Uncosted ingredient (2 pcs)
    $pdo->prepare("
        INSERT INTO recipe_ingredients (recipe_id, ingredient_name, quantity, unit)
        VALUES (?, 'D9b Radish', 2, 'pc')
    ")->execute([$testRecipeId]);

    // Set cost only for Pork Belly: ₱200/kg (500g = ₱100.00)
    $pdo->prepare("
        INSERT INTO vendor_ingredient_costs (store_id, ingredient_name, cost, cost_unit)
        VALUES (1, 'D9b Pork Belly', 200.00, 'kg')
    ")->execute();
    $porkCostId = (int)$pdo->lastInsertId();
    $createdCostIds[] = $porkCostId;

    // 3a. Test Partial Total
    echo "3a. Testing Prep Plan Partial Total (missing price on D9b Radish):\n";
    $partialPrepRes = d9bApiRequest("$baseUrl/vendor/prep-plan", 'POST', [
        'items' => [
            ['menu_item_id' => $testDishId, 'servings' => 10]
        ]
    ], $cookieVendor);

    if ($partialPrepRes['status'] !== 200) {
        throw new Exception("Prep plan request failed with status {$partialPrepRes['status']}: {$partialPrepRes['raw']}");
    }
    $pData = $partialPrepRes['data'];

    if ($pData['is_partial'] !== true) {
        throw new Exception("Expected is_partial === true for missing ingredient cost, got " . json_encode($pData['is_partial']));
    }
    if ((int)$pData['costed_count'] !== 1 || (int)$pData['total_ingredients'] !== 2) {
        throw new Exception("Expected 1 of 2 ingredients costed, got costed: {$pData['costed_count']}, total: {$pData['total_ingredients']}");
    }
    if (abs((float)$pData['grand_total'] - 100.00) > 0.01) {
        throw new Exception("Expected partial grand total 100.00, got {$pData['grand_total']}");
    }
    if ($pData['coverage_label'] !== '1 of 2 ingredients costed') {
        throw new Exception("Unexpected coverage label: '{$pData['coverage_label']}'");
    }

    // Verify row-level flags
    $foundPork = false;
    $foundRadish = false;
    foreach ($pData['prep_list'] as $pItem) {
        if ($pItem['name'] === 'D9b Pork Belly') {
            $foundPork = true;
            if (!$pItem['has_price'] || abs((float)$pItem['estimated_cost'] - 100.00) > 0.01) {
                throw new Exception("D9b Pork Belly cost incorrect: " . json_encode($pItem));
            }
        }
        if ($pItem['name'] === 'D9b Radish') {
            $foundRadish = true;
            if ($pItem['has_price'] !== false || $pItem['estimated_cost'] !== null) {
                throw new Exception("D9b Radish should have has_price=false and estimated_cost=null: " . json_encode($pItem));
            }
        }
    }
    if (!$foundPork || !$foundRadish) {
        throw new Exception("Missing prep items in breakdown.");
    }
    echo "    ✓ Partial total correctly computed: ₱{$pData['grand_total']} ({$pData['coverage_label']}, is_partial=true).\n";

    // 3b. Test Complete Total
    echo "3b. Testing Prep Plan Complete Total after costing D9b Radish:\n";
    $pdo->prepare("
        INSERT INTO vendor_ingredient_costs (store_id, ingredient_name, cost, cost_unit)
        VALUES (1, 'D9b Radish', 25.00, 'pc')
    ")->execute();
    $radishCostId = (int)$pdo->lastInsertId();
    $createdCostIds[] = $radishCostId;

    $completePrepRes = d9bApiRequest("$baseUrl/vendor/prep-plan", 'POST', [
        'items' => [
            ['menu_item_id' => $testDishId, 'servings' => 10]
        ]
    ], $cookieVendor);

    if ($completePrepRes['status'] !== 200) {
        throw new Exception("Complete prep plan request failed: {$completePrepRes['status']}");
    }
    $cData = $completePrepRes['data'];

    if ($cData['is_partial'] !== false) {
        throw new Exception("Expected is_partial === false when all ingredients are costed, got true");
    }
    if ((int)$cData['costed_count'] !== 2 || (int)$cData['total_ingredients'] !== 2) {
        throw new Exception("Expected 2 of 2 ingredients costed, got {$cData['costed_count']} of {$cData['total_ingredients']}");
    }
    // 500g @ 200/kg = 100.00; 2 pcs @ 25/pc = 50.00. Total = 150.00
    if (abs((float)$cData['grand_total'] - 150.00) > 0.01) {
        throw new Exception("Expected complete grand total 150.00, got {$cData['grand_total']}");
    }
    if ($cData['coverage_label'] !== '2 of 2 ingredients costed') {
        throw new Exception("Unexpected coverage label: '{$cData['coverage_label']}'");
    }
    echo "    ✓ Complete total correctly computed: ₱{$cData['grand_total']} ({$cData['coverage_label']}, is_partial=false).\n\n";

    // =========================================================================
    // SECTION 4: Closing Time Override & Integration Check
    // =========================================================================
    echo "--- SECTION 4: Closing Time Override Verification ---\n";

    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $todayDate = $now->format('Y-m-d');

    // 4a. Override closing time set in past (1 minute ago) -> cafeteria evaluates as closed
    $pastTime = (clone $now)->modify('-1 minute')->format('H:i');
    $pdo->prepare("
        UPDATE settings 
        SET override_closing_time = ?, override_closing_date = ? 
        WHERE id = 1
    ")->execute([$pastTime . ':00', $todayDate]);

    $stmtCheckSettings = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $activeSettings = $stmtCheckSettings->fetch(PDO::FETCH_ASSOC);

    $effectivePast = PricingEngine::getEffectiveClosingTime($activeSettings);
    if ($effectivePast !== $pastTime) {
        throw new Exception("Expected effective closing time to be '$pastTime', got '$effectivePast'");
    }
    if (!PricingEngine::isPastClosing($activeSettings)) {
        throw new Exception("isPastClosing should be TRUE for past override closing time");
    }
    echo "    ✓ getEffectiveClosingTime correctly resolved override time '$pastTime' (isPastClosing = true).\n";

    // Check customer endpoint reading effective closing time
    $slotsPastRes = d9bApiRequest("$baseUrl/customer/pickup-slots", 'GET');
    if ($slotsPastRes['status'] !== 200) {
        throw new Exception("GET /customer/pickup-slots failed: status {$slotsPastRes['status']}");
    }
    if ($slotsPastRes['data']['closing_time'] !== $pastTime) {
        throw new Exception("Expected customer pickup-slots closing_time to be '$pastTime', got '{$slotsPastRes['data']['closing_time']}'");
    }
    if (!empty($slotsPastRes['data']['slots'])) {
        throw new Exception("Pickup slots should be empty after closing time, got " . count($slotsPastRes['data']['slots']) . " slots.");
    }
    echo "    ✓ Customer /pickup-slots endpoint respects past override: returns closing_time '$pastTime' and 0 slots.\n";

    // 4b. Override closing time set in future (+30 minutes) -> cafeteria evaluates as open
    $futureTime = (clone $now)->modify('+30 minutes')->format('H:i');
    $pdo->prepare("
        UPDATE settings 
        SET override_closing_time = ?, override_closing_date = ? 
        WHERE id = 1
    ")->execute([$futureTime . ':00', $todayDate]);

    $activeSettingsFuture = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $effectiveFuture = PricingEngine::getEffectiveClosingTime($activeSettingsFuture);
    if ($effectiveFuture !== $futureTime) {
        throw new Exception("Expected effective closing time to be '$futureTime', got '$effectiveFuture'");
    }
    if (PricingEngine::isPastClosing($activeSettingsFuture)) {
        throw new Exception("isPastClosing should be FALSE for future override closing time");
    }

    $slotsFutureRes = d9bApiRequest("$baseUrl/customer/pickup-slots", 'GET');
    if ($slotsFutureRes['status'] !== 200) {
        throw new Exception("GET /customer/pickup-slots failed with future override: status {$slotsFutureRes['status']}");
    }
    if ($slotsFutureRes['data']['closing_time'] !== $futureTime) {
        throw new Exception("Expected customer pickup-slots closing_time to be '$futureTime', got '{$slotsFutureRes['data']['closing_time']}'");
    }
    echo "    ✓ Future override ($futureTime) correctly resolved and propagated across customer endpoints.\n\n";

    echo "ALL PHASE D9b VERIFICATION TESTS PASSED CLEANLY!\n";

} catch (Exception $e) {
    $testError = $e->getMessage();
} finally {
    echo "\nCleaning up test artifacts...\n";
    try {
        // Clean up recipe ingredients and recipes
        if (!empty($createdDishIds)) {
            $dishIn = implode(',', array_map('intval', $createdDishIds));
            $pdo->exec("DELETE FROM recipe_ingredients WHERE recipe_id IN (SELECT id FROM recipes WHERE menu_item_id IN ($dishIn))");
            $pdo->exec("DELETE FROM recipes WHERE menu_item_id IN ($dishIn)");
            $pdo->exec("DELETE FROM inventory WHERE menu_item_id IN ($dishIn)");
            $pdo->exec("DELETE FROM menu_items WHERE id IN ($dishIn)");
        }
        // Clean up vendor ingredient costs
        if (!empty($createdCostIds)) {
            $costIn = implode(',', array_map('intval', $createdCostIds));
            $pdo->exec("DELETE FROM vendor_ingredient_costs WHERE id IN ($costIn)");
        }
        // Clean up test ingredient history
        $pdo->exec("DELETE FROM ingredient_price_history WHERE store_id = 1 AND ingredient_name IN ('D9b Free Spice', 'D9b Scoped Herb', 'D9b Pork Belly', 'D9b Radish')");

        // Clean up any other test items
        $pdo->exec("DELETE FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name IN ('D9b Free Spice', 'D9b Scoped Herb', 'D9b Pork Belly', 'D9b Radish')");

        // Restore original settings
        if (!empty($originalSettings)) {
            $stmtRestore = $pdo->prepare("
                UPDATE settings 
                SET closing_time = ?, override_closing_time = ?, override_closing_date = ? 
                WHERE id = 1
            ");
            $stmtRestore->execute([
                $originalSettings['closing_time'] ?? '20:00:00',
                $originalSettings['override_closing_time'] ?? null,
                $originalSettings['override_closing_date'] ?? null
            ]);
        }

        // Remove temporary cookie file
        if (file_exists($cookieVendor)) {
            @unlink($cookieVendor);
        }

        echo "✓ Cleanup complete: All temporary test users, dishes, recipes, ingredient costs, price history, settings, and cookies cleanly deleted.\n";
    } catch (Exception $cleanupEx) {
        echo "Cleanup error: " . $cleanupEx->getMessage() . "\n";
    }

    if ($testError !== null) {
        echo "\n❌ TEST FAILURE: $testError\n";
        exit(1);
    }
}
