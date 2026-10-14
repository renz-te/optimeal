<?php
require_once __DIR__ . '/../api/db.php';

echo "=== MIGRATION: Phase B4.12 Discard Workflow Columns ===\n";

try {
    // 1. Add discarded_qty column to batches if not exists
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'batches' 
          AND COLUMN_NAME = 'discarded_qty'
    ");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN discarded_qty INT NOT NULL DEFAULT 0 AFTER remaining_stock");
        echo "   ✓ Added column 'discarded_qty' to batches table.\n";
    } else {
        echo "   - Column 'discarded_qty' already exists on batches table.\n";
    }

    // 2. Add status column to batches if not exists (to mirror/support state)
    $stmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = 'batches' 
          AND COLUMN_NAME = 'status'
    ");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN status VARCHAR(50) NULL DEFAULT 'active' AFTER state");
        $pdo->exec("UPDATE batches SET status = state WHERE state IS NOT NULL");
        echo "   ✓ Added column 'status' to batches table and populated from state.\n";
    } else {
        echo "   - Column 'status' already exists on batches table.\n";
    }

    echo "\n=== Migration B4.12 completed successfully! ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
