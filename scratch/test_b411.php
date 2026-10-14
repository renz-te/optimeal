<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/routes/user.php';
require_once __DIR__ . '/../api/routes/customer.php';

echo "=== TEST: Phase B4.11 Customer Settings & Impact ===\n";

try {
    // 1. Verify schema of user_preferences
    echo "1. Testing schema of user_preferences...\n";
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'user_preferences' 
          AND COLUMN_NAME = 'default_portion'
    ");
    if ($stmt->fetch()) {
        throw new Exception("Column 'default_portion' still exists in user_preferences!");
    }
    echo "   ✓ Verified: 'default_portion' column successfully removed from user_preferences.\n";

    // 2. Verify schema of orders
    echo "2. Testing schema of orders...\n";
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'orders' 
          AND COLUMN_NAME = 'created_at'
    ");
    if (!$stmt->fetch()) {
        throw new Exception("Column 'created_at' missing from orders table!");
    }
    echo "   ✓ Verified: 'created_at' column exists in orders table.\n";

    // 3. Test impact calculation query for all three timeframes
    echo "3. Testing impact query calculations...\n";
    $testUserId = 4; // Existing user with orders

    foreach (['week', 'month', 'all_time'] as $tf) {
        $dateCondition = "";
        if ($tf === 'week') {
            $dateCondition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($tf === 'month') {
            $dateCondition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        }

        $sql = "
            SELECT 
                COALESCE(SUM(oi.quantity), 0) AS meals_rescued,
                COALESCE(SUM(GREATEST(0, (m.base_price - oi.unit_price_at_order) * oi.quantity)), 0.00) AS money_saved
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN menu_items m ON oi.menu_item_id = m.id
            WHERE o.user_id = ?
              AND o.status IN ('reserved', 'paid', 'claimed')
              $dateCondition
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$testUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $meals = (int)($row['meals_rescued'] ?? 0);
        $money = (float)($row['money_saved'] ?? 0.00);
        $co2 = round($meals * 0.6, 1);

        echo "   - Timeframe [$tf]: Meals Rescued = $meals, Money Saved = ₱" . number_format($money, 2) . ", CO2 Diverted = {$co2}kg\n";
    }
    echo "   ✓ Verified: All 3 timeframes calculated cleanly.\n";

    // 4. Test user preferences update logic
    echo "4. Testing preferences update logic...\n";
    // Ensure test user has a preferences record
    $check = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = ?");
    $check->execute([$testUserId]);
    if (!$check->fetchColumn()) {
        $pdo->prepare("INSERT INTO user_preferences (user_id) VALUES (?)")->execute([$testUserId]);
    }

    $allowedFields = [
        'notify_quota_hit',
        'notify_order_status',
        'notify_decay',
        'notify_menu_published',
        'notify_low_stock',
        'notify_daily_audit'
    ];
    $testPayload = [
        'notify_quota_hit' => true,
        'notify_order_status' => false,
        'notify_decay' => true,
        'default_portion' => 'extra_sauce' // Legacy key should be silently ignored/filtered
    ];

    $updates = [];
    $params = [];
    foreach ($testPayload as $key => $value) {
        if (in_array($key, $allowedFields, true)) {
            $updates[] = "`$key` = ?";
            $params[] = is_bool($value) ? ($value ? 1 : 0) : $value;
        }
    }

    $params[] = $testUserId;
    $sql = "UPDATE user_preferences SET " . implode(', ', $updates) . " WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $verifyStmt = $pdo->prepare("SELECT notify_quota_hit, notify_order_status, notify_decay FROM user_preferences WHERE user_id = ?");
    $verifyStmt->execute([$testUserId]);
    $updatedPrefs = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if ((int)$updatedPrefs['notify_quota_hit'] !== 1 || (int)$updatedPrefs['notify_order_status'] !== 0) {
        throw new Exception("Preferences failed to update as expected!");
    }
    echo "   ✓ Verified: Preferences update executed without portion errors.\n";

    echo "\n=== All B4.11 verification checks passed successfully! ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
