<?php
require_once __DIR__ . '/db.php';

echo "Running TTL cleanup script...\n";

$pdo->beginTransaction();
try {
    // 1. Identify expired reservations
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'reserved' AND ttl_expires_at < NOW()");
    $stmt->execute();
    $expired_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($expired_orders) > 0) {
        $placeholders = str_repeat('?,', count($expired_orders) - 1) . '?';
        
        // 2. Mark orders as expired
        $updateStatus = $pdo->prepare("UPDATE orders SET status = 'expired' WHERE id IN ($placeholders)");
        $updateStatus->execute($expired_orders);

        // 3. Fetch line items to restore inventory
        $itemsStmt = $pdo->prepare("SELECT menu_item_id, quantity, order_id FROM order_items WHERE order_id IN ($placeholders)");
        $itemsStmt->execute($expired_orders);
        $order_items = $itemsStmt->fetchAll();

        // Query setup to replenish inventory and potentially re-list items
        $restoreInv = $pdo->prepare("
            UPDATE inventory 
            SET online_pool_qty = online_pool_qty + ?, 
                delisted_from_app = CASE WHEN online_pool_qty + ? <= critical_stock_threshold THEN 1 ELSE 0 END 
            WHERE menu_item_id = ?
        ");
        
        // Query setup to refund the user's daily anti-hoarding discount claim
        $refundClaim = $pdo->prepare("
            UPDATE daily_discount_claims d
            JOIN orders o ON o.user_id = d.user_id
            SET d.quantity_claimed = GREATEST(0, d.quantity_claimed - ?)
            WHERE d.menu_item_id = ? AND o.id = ? AND d.claim_date = DATE(o.reserved_at)
        ");

        // 4. Iterate and restore
        foreach ($order_items as $item) {
            $restoreInv->execute([$item['quantity'], $item['quantity'], $item['menu_item_id']]);
            $refundClaim->execute([$item['quantity'], $item['menu_item_id'], $item['order_id']]);
        }

        echo "Cancelled " . count($expired_orders) . " expired orders and restored inventory.\n";
    } else {
        echo "No expired orders found.\n";
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
