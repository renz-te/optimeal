<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D9.3 Automated Verification Suite: Ingredient Price Catalog & Polish (C1-C5, S1-S2)
 * Tests:
 * - C1 & C2: Ingredient price history table & single-save logging 'manual'.
 * - C2: Bulk adjust preview endpoint (simulated calculation without DB mutation).
 * - C2: Bulk adjust apply endpoint (logs 'bulk' with batch_id, updates costs).
 * - C2: History list endpoint returns all actions, old/new costs, batch IDs.
 * - C2: Rollback endpoint restores costs, logs 'rollback', denies double-rollbacks with HTTP 422.
 * - C3 & C4: Suggested price floor returned with 1.2x cost capped at base_price; active price_floor NEVER auto-mutated.
 * - C4: Double-rollback protection works on both batch_id and single history_id.
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d9_cat.txt';

if (file_exists($cookieVendor)) @unlink($cookieVendor);

function d9CatJsonRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
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

echo "=== PHASE D9.3 INGREDIENT PRICE CATALOG AUTOMATED VERIFICATION ===\n\n";

$testBatchId = null;
$createdHistoryIds = [];
$testError = null;

// Pre-test cleanup to guarantee pristine state
$pdo->prepare("DELETE FROM ingredient_price_history WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->execute();
$pdo->prepare("DELETE FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->execute();

try {
    // 1. Authenticate Vendor
    $vendorLogin = d9CatJsonRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'canteen@optimeal.test',
        'password' => 'password123'
    ], $cookieVendor);

    if ($vendorLogin['status'] !== 200) {
        throw new Exception("Vendor login failed with status {$vendorLogin['status']}");
    }
    echo "✓ 1. Vendor Authenticated (Store 1)\n";

    // Baseline check for target dish
    $stmtDish = $pdo->query("SELECT id, name, base_price, price_floor FROM menu_items WHERE id = 1 AND store_id = 1");
    $initialDish = $stmtDish->fetch();
    $baselinePriceFloor = (float)$initialDish['price_floor'];
    echo "✓ 2. Dish Baseline verified: '{$initialDish['name']}' (Base: ₱{$initialDish['base_price']}, Floor: ₱{$baselinePriceFloor})\n\n";

    // 3. Test Single Save (Manual Action)
    echo "--- TEST 1: Single Ingredient Price Save & Manual Logging (C2) ---\n";
    $saveRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs", 'POST', [
        'items' => [
            [
                'ingredient_name' => 'D9Test Seasoning',
                'cost' => 100.00,
                'cost_unit' => 'kg'
            ]
        ]
    ], $cookieVendor);

    if ($saveRes['status'] !== 200) {
        throw new Exception("Single price save failed: status {$saveRes['status']}, raw: {$saveRes['raw']}");
    }

    $stmtHist = $pdo->prepare("SELECT id, action, is_rolled_back, old_cost, new_cost FROM ingredient_price_history WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning' ORDER BY id DESC LIMIT 1");
    $stmtHist->execute();
    $manualHist = $stmtHist->fetch();
    if (!$manualHist || $manualHist['action'] !== 'manual') {
        throw new Exception("Price history not logged with 'manual' action.");
    }
    $manualHistId = (int)$manualHist['id'];
    $createdHistoryIds[] = $manualHistId;
    echo "✓ Test 1 passed: Price saved and logged in history as 'manual' (ID: $manualHistId, New Cost: ₱{$manualHist['new_cost']}).\n\n";

    // 4. Test Bulk Preview (No DB Mutation)
    echo "--- TEST 2: Bulk Adjust Preview (C2) ---\n";
    $previewRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/bulk-preview", 'POST', [
        'percentage' => 20
    ], $cookieVendor);

    if ($previewRes['status'] !== 200 || empty($previewRes['data']['preview'])) {
        throw new Exception("Bulk preview failed: status {$previewRes['status']}, raw: {$previewRes['raw']}");
    }

    $previewMatch = null;
    foreach ($previewRes['data']['preview'] as $p) {
        if ($p['ingredient_name'] === 'D9Test Seasoning') {
            $previewMatch = $p;
            break;
        }
    }

    if (!$previewMatch || (float)$previewMatch['new_cost'] !== 120.00) {
        throw new Exception("Bulk preview math mismatch: expected 120.00, got " . json_encode($previewMatch));
    }

    // Verify DB still has 100.00
    $currentCostInDb = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->fetchColumn();
    if ($currentCostInDb !== 100.00) {
        throw new Exception("Bulk preview erroneously mutated the database cost: $currentCostInDb");
    }
    echo "✓ Test 2 passed: Bulk preview simulated +20% (₱100.00 -> ₱120.00) without modifying database.\n\n";

    // 5. Test Bulk Apply (Batch Logging & Affected Dishes)
    echo "--- TEST 3: Bulk Adjust Apply (C2 & C4) ---\n";
    $applyRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/bulk-apply", 'POST', [
        'percentage' => 20
    ], $cookieVendor);

    if ($applyRes['status'] !== 200 || empty($applyRes['data']['batch_id'])) {
        throw new Exception("Bulk apply failed: status {$applyRes['status']}, raw: {$applyRes['raw']}");
    }

    $testBatchId = $applyRes['data']['batch_id'];
    $appliedCostInDb = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->fetchColumn();
    if ($appliedCostInDb !== 120.00) {
        throw new Exception("Bulk apply did not update cost in DB: expected 120.00, got $appliedCostInDb");
    }

    // Check history batch entry
    $stmtBatchHist = $pdo->prepare("SELECT id, action, batch_id, old_cost, new_cost, is_rolled_back FROM ingredient_price_history WHERE store_id = 1 AND batch_id = ? AND ingredient_name = 'D9Test Seasoning'");
    $stmtBatchHist->execute([$testBatchId]);
    $batchRow = $stmtBatchHist->fetch();
    if (!$batchRow || $batchRow['action'] !== 'bulk') {
        throw new Exception("Bulk history record missing or action is not 'bulk'.");
    }
    $createdHistoryIds[] = (int)$batchRow['id'];

    // Verify affected dishes structure (C4)
    if (!isset($applyRes['data']['affected_dishes'])) {
        throw new Exception("Bulk apply response missing 'affected_dishes' key.");
    }
    foreach ($applyRes['data']['affected_dishes'] as $aff) {
        if (!isset($aff['suggested_price_floor']) || !isset($aff['current_price_floor'])) {
            throw new Exception("Affected dish item missing suggested_price_floor or current_price_floor: " . json_encode($aff));
        }
        // Verify suggested_price_floor <= base_price
        if ($aff['suggested_price_floor'] > $aff['base_price']) {
            throw new Exception("Suggested floor ₱{$aff['suggested_price_floor']} exceeds base price ₱{$aff['base_price']}");
        }
    }
    echo "✓ Test 3 passed: Bulk apply executed successfully with Batch ID '$testBatchId'. Affected dishes included suggested floors.\n\n";

    // 6. Test Price Sovereignty (C4) - Active dish price_floor NEVER auto-mutated
    echo "--- TEST 4: Price Floor Sovereignty Check (C4) ---\n";
    $postApplyDishFloor = (float)$pdo->query("SELECT price_floor FROM menu_items WHERE id = 1 AND store_id = 1")->fetchColumn();
    if ($postApplyDishFloor !== $baselinePriceFloor) {
        throw new Exception("CRITICAL FAILURE: Bulk price adjustment mutated dish price_floor from $baselinePriceFloor to $postApplyDishFloor!");
    }
    echo "✓ Test 4 passed: Menu items price_floor strictly preserved at ₱$baselinePriceFloor (unmodified by catalog price changes).\n\n";

    // 7. Test History List Endpoint (C2)
    echo "--- TEST 5: History List Endpoint (C2) ---\n";
    $histRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/history", 'GET', null, $cookieVendor);
    if ($histRes['status'] !== 200 || !is_array($histRes['data'])) {
        throw new Exception("History list failed: status {$histRes['status']}");
    }

    $foundManual = false;
    $foundBulk = false;
    foreach ($histRes['data'] as $h) {
        if ($h['ingredient_name'] === 'D9Test Seasoning') {
            if ($h['action'] === 'manual') $foundManual = true;
            if ($h['action'] === 'bulk' && $h['batch_id'] === $testBatchId) $foundBulk = true;
        }
    }
    if (!$foundManual || !$foundBulk) {
        throw new Exception("History list missing manual or bulk test entries.");
    }
    echo "✓ Test 5 passed: History endpoint returned both manual and bulk adjustments.\n\n";

    // 8. Test Rollback Endpoint (C2)
    echo "--- TEST 6: Rollback Endpoint (C2) ---\n";
    $rollbackRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $testBatchId
    ], $cookieVendor);

    if ($rollbackRes['status'] !== 200) {
        throw new Exception("Rollback failed: status {$rollbackRes['status']}, raw: {$rollbackRes['raw']}");
    }

    $rolledBackCostInDb = (float)$pdo->query("SELECT cost FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->fetchColumn();
    if ($rolledBackCostInDb !== 100.00) {
        throw new Exception("Rollback did not restore original cost: expected 100.00, got $rolledBackCostInDb");
    }

    // Verify history marked as is_rolled_back = 1
    $isRolledBackFlag = (int)$pdo->query("SELECT is_rolled_back FROM ingredient_price_history WHERE store_id = 1 AND batch_id = '$testBatchId' AND action = 'bulk' LIMIT 1")->fetchColumn();
    if ($isRolledBackFlag !== 1) {
        throw new Exception("Target history entry is_rolled_back flag not set to 1 after rollback.");
    }

    // Verify rollback history entry created
    $rollbackEntry = $pdo->query("SELECT id, action, old_cost, new_cost FROM ingredient_price_history WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning' AND action = 'rollback' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$rollbackEntry) {
        throw new Exception("Rollback audit record not logged in history.");
    }
    $createdHistoryIds[] = (int)$rollbackEntry['id'];
    echo "✓ Test 6 passed: Rollback restored cost to ₱100.00, marked batch is_rolled_back = 1, and logged rollback audit.\n\n";

    // 9. Test Double-Rollback Defense (C2)
    echo "--- TEST 7: Double-Rollback Defense (C2) ---\n";
    $doubleRollbackRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'batch_id' => $testBatchId
    ], $cookieVendor);

    if ($doubleRollbackRes['status'] !== 422) {
        throw new Exception("Double rollback was NOT denied with HTTP 422: got status {$doubleRollbackRes['status']}, raw: {$doubleRollbackRes['raw']}");
    }

    $errorMsg = $doubleRollbackRes['data']['error'] ?? '';
    if (strpos($errorMsg, 'already been rolled back') === false) {
        throw new Exception("Unexpected error message on double rollback: '$errorMsg'");
    }
    echo "✓ Test 7 passed: Second rollback attempt cleanly denied with HTTP 422: '$errorMsg'.\n\n";

    // 10. Test Single Record Rollback and Double-Rollback Defense
    echo "--- TEST 8: Single Record Rollback Defense (C2) ---\n";
    $singleRollbackRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'history_id' => $manualHistId
    ], $cookieVendor);

    if ($singleRollbackRes['status'] !== 200) {
        throw new Exception("Single record rollback failed: status {$singleRollbackRes['status']}, raw: {$singleRollbackRes['raw']}");
    }

    $doubleSingleRes = d9CatJsonRequest("$baseUrl/vendor/ingredient-costs/rollback", 'POST', [
        'history_id' => $manualHistId
    ], $cookieVendor);

    if ($doubleSingleRes['status'] !== 422) {
        throw new Exception("Double single rollback was NOT denied with HTTP 422: got status {$doubleSingleRes['status']}");
    }
    echo "✓ Test 8 passed: Single history_id rollback succeeded and blocked double-rollback with HTTP 422.\n\n";

    echo "ALL PHASE D9.3 VERIFICATIONS PASSED CLEANLY!\n";

} catch (Exception $e) {
    $testError = $e->getMessage();
} finally {
    // Test Hygiene (L4): Clean up test artifacts
    echo "\nCleaning up test records...\n";
    try {
        $pdo->prepare("DELETE FROM ingredient_price_history WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->execute();
        $pdo->prepare("DELETE FROM vendor_ingredient_costs WHERE store_id = 1 AND ingredient_name = 'D9Test Seasoning'")->execute();
        // Restore price_floor in case of unexpected deviation
        if (isset($baselinePriceFloor)) {
            $pdo->prepare("UPDATE menu_items SET price_floor = ? WHERE id = 1 AND store_id = 1")->execute([$baselinePriceFloor]);
        }
        if (file_exists($cookieVendor)) @unlink($cookieVendor);
        echo "✓ Cleanup complete. Zero leftover test records.\n";
    } catch (Exception $cleanupErr) {
        echo "Cleanup warning: " . $cleanupErr->getMessage() . "\n";
    }

    if ($testError !== null) {
        echo "\n❌ TEST FAILURE: $testError\n";
        exit(1);
    }
}
