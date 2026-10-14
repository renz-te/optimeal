<?php
require_once __DIR__ . '/../api/db.php';

echo "=== MIGRATION: Phase B4.11 Customer Settings & Impact Cleanup ===\n";

try {
    // 1. Check and drop `default_portion` from `user_preferences`
    echo "1. Checking user_preferences for legacy portion columns...\n";
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'user_preferences' 
          AND COLUMN_NAME = 'default_portion'
    ");
    if ($stmt->fetch()) {
        $pdo->exec("ALTER TABLE user_preferences DROP COLUMN default_portion");
        echo "   ✓ Dropped legacy column 'default_portion' from user_preferences.\n";
    } else {
        echo "   - Column 'default_portion' already absent from user_preferences.\n";
    }

    // 2. Check and drop any portion columns from `users`
    echo "2. Checking users table for legacy portion columns...\n";
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'users' 
          AND COLUMN_NAME LIKE '%portion%'
    ");
    $userCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($userCols)) {
        foreach ($userCols as $uc) {
            $col = $uc['COLUMN_NAME'];
            $pdo->exec("ALTER TABLE users DROP COLUMN `$col`");
            echo "   ✓ Dropped legacy column '$col' from users table.\n";
        }
    } else {
        echo "   - No legacy portion columns found in users table.\n";
    }

    // 3. Ensure `orders.created_at` exists for impact sum filtering
    echo "3. Ensuring orders.created_at exists...\n";
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'orders' 
          AND COLUMN_NAME = 'created_at'
    ");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER reserved_at");
        $pdo->exec("UPDATE orders SET created_at = reserved_at WHERE reserved_at IS NOT NULL");
        echo "   ✓ Added column 'created_at' to orders table and backfilled from reserved_at.\n";
    } else {
        echo "   - Column 'created_at' already exists on orders table.\n";
    }

    echo "\n=== Migration B4.11 completed successfully! ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
