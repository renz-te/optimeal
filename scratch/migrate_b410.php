<?php
require_once __DIR__ . '/../api/db.php';

try {
    echo "Running migration for admin_audit_log...\n";

    // 1. Check if is_active column exists in users table
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_active'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role");
        echo "Added 'is_active' column to users table.\n";
    } else {
        echo "'is_active' column already exists in users table.\n";
    }

    // 2. Create admin_audit_log table
    $sql = "CREATE TABLE IF NOT EXISTS admin_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        target_user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        details TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_id (admin_id),
        INDEX idx_target_user_id (target_user_id),
        INDEX idx_created_at (created_at),
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "Created 'admin_audit_log' table successfully.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
