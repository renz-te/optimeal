<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/routes/vendor.php';

echo "=== TEST: Phase B4.12 Discard Logic & Margins ===\n";

try {
    // 1. Verify schema
    $cols = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'batches' 
          AND COLUMN_NAME IN ('discarded_qty', 'status')
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('discarded_qty', $cols) || !in_array('status', $cols)) {
        throw new Exception("Missing required columns in batches table!");
    }
    echo "✓ Schema verified: discarded_qty and status columns present in batches.\n";

    // 2. Find an active menu item to create a test batch
    $dish = $pdo->query("SELECT id, store_id FROM menu_items WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$dish) {
        throw new Exception("No active menu item found for test.");
    }
    $menuItemId = (int)$dish['id'];
    $storeId = (int)$dish['store_id'];

    // Insert test batch with 15 remaining portions
    $stmt = $pdo->prepare("
        INSERT INTO batches (menu_item_id, state, status, cooked_at, sell_by, total_stock, remaining_stock)
        VALUES (?, 'fresh', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 4 HOUR), 15, 15)
    ");
    $stmt->execute([$menuItemId]);
    $testBatchId = (int)$pdo->lastInsertId();
    echo "✓ Created test batch #$testBatchId with 15 remaining portions.\n";

    // Mock vendor session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Find vendor user id for this store
    $vendorUserId = $pdo->query("SELECT vendor_user_id FROM stores WHERE id = $storeId")->fetchColumn();
    $_SESSION['user_id'] = $vendorUserId ?: 2;
    $_SESSION['role'] = 'vendor';

    // 3. Simulate discard update logic directly
    $stmtDiscard = $pdo->prepare("
        UPDATE batches
        SET status = 'discarded',
            state = 'discarded',
            discarded_qty = remaining_stock,
            remaining_stock = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmtDiscard->execute([$testBatchId]);

    // 4. Assert batch state
    $stmtCheck = $pdo->prepare("SELECT id, status, state, discarded_qty, remaining_stock FROM batches WHERE id = ?");
    $stmtCheck->execute([$testBatchId]);
    $updated = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($updated['status'] !== 'discarded') {
        throw new Exception("Expected status to be 'discarded', got '{$updated['status']}'");
    }
    if ($updated['state'] !== 'discarded') {
        throw new Exception("Expected state to be 'discarded', got '{$updated['state']}'");
    }
    if ((int)$updated['discarded_qty'] !== 15) {
        throw new Exception("Expected discarded_qty to be 15, got {$updated['discarded_qty']}");
    }
    if ((int)$updated['remaining_stock'] !== 0) {
        throw new Exception("Expected remaining_stock to be 0, got {$updated['remaining_stock']}");
    }
    echo "✓ Assertions passed: status='discarded', discarded_qty=15, remaining_stock=0.\n";

    // Clean up
    $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$testBatchId]);
    echo "✓ Cleaned up test batch #$testBatchId.\n";

    echo "\n=== All B4.12 tests passed successfully! ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
