<?php
require_once __DIR__ . '/../api/db.php';

echo "=== TIMEZONE & DATA AUDIT CAPTURE ===\n";

// PHP Timezone
echo "PHP Default Timezone: " . date_default_timezone_get() . "\n";
echo "PHP date('Y-m-d H:i:s'): " . date('Y-m-d H:i:s') . "\n";

// MySQL Timezone
$tzStmt = $pdo->query("SELECT @@session.time_zone AS sess_tz, @@global.time_zone AS glob_tz, NOW() AS db_now, CURDATE() AS db_curdate");
$tzRow = $tzStmt->fetch();
echo "MySQL Session TZ: " . $tzRow['sess_tz'] . "\n";
echo "MySQL Global TZ:  " . $tzRow['glob_tz'] . "\n";
echo "MySQL NOW():      " . $tzRow['db_now'] . "\n";
echo "MySQL CURDATE():  " . $tzRow['db_curdate'] . "\n\n";

// Sample rows from orders
echo "--- Sample Orders Timestamps ---\n";
$orderStmt = $pdo->query("SELECT id, status, reserved_at, ttl_expires_at, total_amount FROM orders ORDER BY id DESC LIMIT 5");
$orders = $orderStmt->fetchAll();
foreach ($orders as $o) {
    echo "Order #{$o['id']} [{$o['status']}]: reserved_at={$o['reserved_at']}, ttl_expires_at={$o['ttl_expires_at']}, total_amount={$o['total_amount']}\n";
}
echo "\n";

// Sample rows from inventory
echo "--- Sample Inventory Timestamps ---\n";
$invStmt = $pdo->query("SELECT id, menu_item_id, cooked_at, sell_by, updated_at, online_pool_qty, walkin_pool_qty FROM inventory ORDER BY id DESC LIMIT 5");
$inventories = $invStmt->fetchAll();
foreach ($inventories as $i) {
    echo "Inventory #{$i['id']} (menu_item_id={$i['menu_item_id']}): cooked_at={$i['cooked_at']}, sell_by={$i['sell_by']}, updated_at={$i['updated_at']}, online_pool={$i['online_pool_qty']}, walkin_pool={$i['walkin_pool_qty']}\n";
}
echo "\n";

// Daily Audit check (vendor 1 / store 1 for today)
echo "--- Daily Audit Baseline (Store 1, CURDATE()) ---\n";
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status = 'claimed' THEN total_amount ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END), 0) AS claimed_orders,
        COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
        COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_orders,
        COALESCE(SUM(CASE WHEN status IN ('reserved', 'paid') THEN 1 ELSE 0 END), 0) AS active_orders
    FROM orders
    WHERE store_id = 1 
      AND DATE(COALESCE(reserved_at, NOW())) = CURDATE()
");
$stmt->execute();
$auditMetrics = $stmt->fetch();
echo "Orders Audit: Total={$auditMetrics['total_orders']}, Claimed={$auditMetrics['claimed_orders']}, Cancelled={$auditMetrics['cancelled_orders']}, Expired={$auditMetrics['expired_orders']}, Active={$auditMetrics['active_orders']}, Revenue=PHP {$auditMetrics['total_revenue']}\n\n";

// Inventory remaining stock calculation check
$invAuditStmt = $pdo->prepare("
    SELECT 
        m.name, 
        i.online_pool_qty,
        i.walkin_pool_qty,
        m.base_price,
        -- Old calculation (online only)
        i.online_pool_qty AS old_remaining_stock,
        ROUND(m.base_price * i.online_pool_qty, 2) AS old_waste_value,
        -- New calculation (online + walkin)
        (i.online_pool_qty + i.walkin_pool_qty) AS new_remaining_stock,
        ROUND(m.base_price * (i.online_pool_qty + i.walkin_pool_qty), 2) AS new_waste_value
    FROM menu_items m
    JOIN inventory i ON m.id = i.menu_item_id
    WHERE m.store_id = 1 AND m.is_active = 1
    ORDER BY m.name ASC
");
$invAuditStmt->execute();
$invItems = $invAuditStmt->fetchAll();
$oldTotalWaste = 0;
$oldTotalUnits = 0;
$newTotalWaste = 0;
$newTotalUnits = 0;
foreach ($invItems as $item) {
    $oldTotalWaste += (float)$item['old_waste_value'];
    $oldTotalUnits += (int)$item['old_remaining_stock'];
    $newTotalWaste += (float)$item['new_waste_value'];
    $newTotalUnits += (int)$item['new_remaining_stock'];
    echo "Item: {$item['name']} | Online: {$item['online_pool_qty']}, Walk-in: {$item['walkin_pool_qty']}, Price: {$item['base_price']} | Old Units: {$item['old_remaining_stock']} (Val: PHP {$item['old_waste_value']}) | New Units: {$item['new_remaining_stock']} (Val: PHP {$item['new_waste_value']})\n";
}
echo "\nTotals - Old Unsold Units: $oldTotalUnits, Old Waste Value: PHP $oldTotalWaste\n";
echo "Totals - New Unsold Units: $newTotalUnits, New Waste Value: PHP $newTotalWaste\n";
