<?php
require_once __DIR__ . '/db.php';

$users = [
    [
        'name' => 'Juan Dela Cruz',
        'email' => 'student@optimeal.test',
        'password' => password_hash('password123', PASSWORD_BCRYPT),
        'role' => 'student',
        'phone_number' => '09123456789'
    ],
    [
        'name' => 'Main Cafeteria Stall',
        'email' => 'canteen@optimeal.test',
        'password' => password_hash('password123', PASSWORD_BCRYPT),
        'role' => 'vendor',
        'phone_number' => '09123456780'
    ],
    [
        'name' => 'System Administrator',
        'email' => 'admin@optimeal.test',
        'password' => password_hash('password123', PASSWORD_BCRYPT),
        'role' => 'admin',
        'phone_number' => '09123456781'
    ]
];

foreach ($users as $user) {
    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$user['email']]);
    if ($stmt->fetch()) {
        // Update
        $stmt = $pdo->prepare("UPDATE users SET name = ?, password_hash = ?, role = ? WHERE email = ?");
        $stmt->execute([$user['name'], $user['password'], $user['role'], $user['email']]);
        echo "Updated {$user['email']}\n";
    } else {
        // Insert
        // Assuming table 'users' has name, email, password_hash, role
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['name'], $user['email'], $user['password'], $user['role']]);
        $userId = $pdo->lastInsertId();
        
        if ($user['role'] === 'vendor') {
            $stmtStore = $pdo->prepare("INSERT IGNORE INTO stores (vendor_user_id, name) VALUES (?, ?)");
            $stmtStore->execute([$userId, $user['name'] . ' Store']);
        }
        echo "Inserted {$user['email']}\n";
    }
}
echo "Done.\n";
