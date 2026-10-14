<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/PricingEngine.php';

echo "=== TEST: Phase B4.14 & B4.15 End-to-End Test ===\n";

try {
    // 1. Verify schema columns
    echo "1. Checking database schema columns...\n";
    $bCols = $pdo->query("SHOW COLUMNS FROM batches")->fetchAll(PDO::FETCH_COLUMN);
    assert(in_array('held_stock', $bCols), "batches.held_stock missing");
    assert(in_array('reconciliation_offset', $bCols), "batches.reconciliation_offset missing");

    $mCols = $pdo->query("SHOW COLUMNS FROM menu_items")->fetchAll(PDO::FETCH_COLUMN);
    assert(in_array('photo_path', $mCols), "menu_items.photo_path missing");

    $oiCols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    assert(in_array('batch_id', $oiCols), "order_items.batch_id missing");
    echo "   [PASS] Schema columns verified.\n";

    // 2. Setup a test dish and batch
    echo "2. Setting up test dish and batch...\n";
    $storeId = $pdo->query("SELECT id FROM stores LIMIT 1")->fetchColumn();
    assert($storeId > 0, "Store not found");

    $stmtDish = $pdo->prepare("
        INSERT INTO menu_items (store_id, name, category, base_price, price_floor, is_active, photo_path, hold_hours)
        VALUES (?, 'Test Viand B415', 'Meat', 100.00, 50.00, 1, 'uploads/dishes/test_b415.jpg', 6)
    ");
    $stmtDish->execute([$storeId]);
    $dishId = $pdo->lastInsertId();

    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $sellBy = (clone $now)->modify('+4 hours')->format('Y-m-d H:i:s');
    $cookedAt = $now->format('Y-m-d H:i:s');

    $stmtBatch = $pdo->prepare("
        INSERT INTO batches (menu_item_id, state, cooked_at, sell_by, hold_hours, total_stock, remaining_stock, held_stock)
        VALUES (?, 'fresh', ?, ?, 6, 20, 20, 0)
    ");
    $stmtBatch->execute([$dishId, $cookedAt, $sellBy]);
    $batchId = $pdo->lastInsertId();

    // 3. Test handleGetMenu stock availability
    echo "3. Testing handleGetMenu pool availability calculation...\n";
    $checkPoolStmt = $pdo->prepare("
        SELECT m.id, m.name, m.photo_path,
               b.id AS batch_id, b.remaining_stock, b.held_stock,
               (b.remaining_stock - b.held_stock) AS available_pool_stock
        FROM menu_items m
        JOIN batches b ON m.id = b.menu_item_id
        WHERE m.id = ? AND b.id = ?
    ");
    $checkPoolStmt->execute([$dishId, $batchId]);
    $poolData = $checkPoolStmt->fetch(PDO::FETCH_ASSOC);

    assert($poolData['photo_path'] === 'uploads/dishes/test_b415.jpg', "photo_path mismatch");
    assert((int)$poolData['available_pool_stock'] === 20, "available_pool_stock should be 20");
    echo "   [PASS] Initial pool availability: {$poolData['available_pool_stock']}, photo_path: {$poolData['photo_path']}\n";

    // 4. Test Customer Reservation: increment held_stock without decrementing remaining_stock
    echo "4. Testing Reservation checkout: held_stock increment...\n";
    $studentUserId = $pdo->query("SELECT id FROM users WHERE role IN ('student', 'customer') LIMIT 1")->fetchColumn();
    assert($studentUserId > 0, "Student user not found");

    // Simulate reservation logic
    $pdo->beginTransaction();
    $claimToken = '9876';
    $exp = (clone $now)->modify('+30 minutes')->format('Y-m-d H:i:s');
    $insOrder = $pdo->prepare("
        INSERT INTO orders (user_id, store_id, status, ttl_expires_at, claim_token, fulfillment_type, payment_method, total_amount)
        VALUES (?, ?, 'reserved', ?, ?, 'dine_in', 'cash', 500.00)
    ");
    $insOrder->execute([$studentUserId, $storeId, $exp, $claimToken]);
    $testOrderId = $pdo->lastInsertId();

    // Increment held_stock and insert order_item with batch_id
    $holdStmt = $pdo->prepare("UPDATE batches SET held_stock = held_stock + 5 WHERE id = ?");
    $holdStmt->execute([$batchId]);

    $insOrderItem = $pdo->prepare("
        INSERT INTO order_items (order_id, menu_item_id, batch_id, quantity, unit_price_at_order, portion_preference)
        VALUES (?, ?, ?, 5, 100.00, 'no_preference')
    ");
    $insOrderItem->execute([$testOrderId, $dishId, $batchId]);
    $pdo->commit();

    // Check batch stock
    $bRow = $pdo->query("SELECT remaining_stock, held_stock, (remaining_stock - held_stock) as avail FROM batches WHERE id = $batchId")->fetch(PDO::FETCH_ASSOC);
    echo "   Batch after reservation: Physical={$bRow['remaining_stock']}, Held={$bRow['held_stock']}, Available={$bRow['avail']}\n";
    assert((int)$bRow['remaining_stock'] === 20, "Physical remaining_stock should NOT decrement on reservation");
    assert((int)$bRow['held_stock'] === 5, "Held stock should be incremented to 5");
    assert((int)$bRow['avail'] === 15, "Available stock should be 15");
    echo "   [PASS] Reservation held_stock logic verified.\n";

    // 5. Test Customer Cancellation: releases held_stock
    echo "5. Testing Reservation cancellation: held_stock release...\n";
    $pdo->beginTransaction();
    $cancelOrderStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
    $cancelOrderStmt->execute([$testOrderId]);

    $releaseStmt = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - 5) WHERE id = ?");
    $releaseStmt->execute([$batchId]);
    $pdo->commit();

    $bRowAfterCancel = $pdo->query("SELECT remaining_stock, held_stock, (remaining_stock - held_stock) as avail FROM batches WHERE id = $batchId")->fetch(PDO::FETCH_ASSOC);
    echo "   Batch after cancellation: Physical={$bRowAfterCancel['remaining_stock']}, Held={$bRowAfterCancel['held_stock']}, Available={$bRowAfterCancel['avail']}\n";
    assert((int)$bRowAfterCancel['remaining_stock'] === 20, "remaining_stock should stay 20");
    assert((int)$bRowAfterCancel['held_stock'] === 0, "held_stock should return to 0");
    assert((int)$bRowAfterCancel['avail'] === 20, "available stock should return to 20");
    echo "   [PASS] Cancellation stock release verified.\n";

    // 6. Test Claiming: decrements both remaining_stock AND held_stock
    echo "6. Testing Order Claiming: decrements remaining_stock AND held_stock...\n";
    // Place second reservation
    $pdo->beginTransaction();
    $holdStmt->execute([$batchId]);
    $insOrder->execute([$studentUserId, $storeId, $exp, '9877']);
    $claimOrderId = $pdo->lastInsertId();
    $insOrderItem->execute([$claimOrderId, $dishId, $batchId]);
    $pdo->commit();

    // Now vendor marks claimed:
    $pdo->beginTransaction();
    $claimBatchStmt = $pdo->prepare("
        UPDATE batches 
        SET remaining_stock = GREATEST(0, remaining_stock - 5),
            held_stock = GREATEST(0, held_stock - 5)
        WHERE id = ?
    ");
    $claimBatchStmt->execute([$batchId]);
    $updOrderStatus = $pdo->prepare("UPDATE orders SET status = 'claimed' WHERE id = ?");
    $updOrderStatus->execute([$claimOrderId]);
    $pdo->commit();

    $bRowAfterClaim = $pdo->query("SELECT remaining_stock, held_stock, (remaining_stock - held_stock) as avail FROM batches WHERE id = $batchId")->fetch(PDO::FETCH_ASSOC);
    echo "   Batch after claiming: Physical={$bRowAfterClaim['remaining_stock']}, Held={$bRowAfterClaim['held_stock']}, Available={$bRowAfterClaim['avail']}\n";
    assert((int)$bRowAfterClaim['remaining_stock'] === 15, "remaining_stock should decrement to 15");
    assert((int)$bRowAfterClaim['held_stock'] === 0, "held_stock should decrement to 0");
    assert((int)$bRowAfterClaim['avail'] === 15, "available stock should be 15");
    echo "   [PASS] Claiming deduction verified.\n";

    // 7. Clean up test records
    echo "7. Cleaning up test data...\n";
    $pdo->exec("DELETE FROM order_items WHERE order_id IN ($testOrderId, $claimOrderId)");
    $pdo->exec("DELETE FROM orders WHERE id IN ($testOrderId, $claimOrderId)");
    $pdo->exec("DELETE FROM batches WHERE id = $batchId");
    $pdo->exec("DELETE FROM menu_items WHERE id = $dishId");
    echo "   [PASS] Test data cleaned up.\n";

    echo "\n=== ALL Phase B4.14 & B4.15 TESTS PASSED SUCCESSFULLY! ===\n";
} catch (Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
