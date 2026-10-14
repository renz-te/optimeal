<?php
require_once __DIR__ . '/../api/db.php';

try {
    echo "Running migration for Phase B4.15 (Batch-Safe Stock Model)...\n";

    // 1. Check held_stock column in batches
    $stmt1 = $pdo->query("SHOW COLUMNS FROM batches LIKE 'held_stock'");
    if (!$stmt1->fetch()) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN held_stock INT NOT NULL DEFAULT 0 AFTER remaining_stock");
        echo "Added 'held_stock' column to batches table.\n";
    } else {
        echo "'held_stock' column already exists in batches table.\n";
    }

    // 2. Check reconciliation_offset column in batches
    $stmt2 = $pdo->query("SHOW COLUMNS FROM batches LIKE 'reconciliation_offset'");
    if (!$stmt2->fetch()) {
        $pdo->exec("ALTER TABLE batches ADD COLUMN reconciliation_offset INT NOT NULL DEFAULT 0 AFTER held_stock");
        echo "Added 'reconciliation_offset' column to batches table.\n";
    } else {
        echo "'reconciliation_offset' column already exists in batches table.\n";
    }

    // 3. Check photo_path column in menu_items
    $stmt3 = $pdo->query("SHOW COLUMNS FROM menu_items LIKE 'photo_path'");
    if (!$stmt3->fetch()) {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN photo_path VARCHAR(255) NULL AFTER image_url");
        $pdo->exec("UPDATE menu_items SET photo_path = image_url WHERE image_url IS NOT NULL");
        echo "Added 'photo_path' column to menu_items table.\n";
    } else {
        echo "'photo_path' column already exists in menu_items table.\n";
    }

    // 4. Check batch_id column in order_items
    $stmt4 = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'batch_id'");
    if (!$stmt4->fetch()) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN batch_id INT NULL AFTER menu_item_id");
        echo "Added 'batch_id' column to order_items table.\n";
    } else {
        echo "'batch_id' column already exists in order_items table.\n";
    }

    echo "Migration B4.15 completed successfully.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
