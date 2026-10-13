<?php

/**
 * OptiMeal User Preferences Route Handlers
 */

function handleGetPreferences($pdo, $userId)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        jsonResponse(['error' => 'Unauthorized user'], 401);
    }

    $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $stmt->execute([$userId]);
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prefs) {
        // Automatically insert default preferences if none exist
        $insert = $pdo->prepare("
            INSERT INTO user_preferences (user_id, default_portion)
            VALUES (?, 'no_preference')
        ");
        $insert->execute([$userId]);

        $stmt->execute([$userId]);
        $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($prefs) {
        $prefs['id'] = (int)$prefs['id'];
        $prefs['user_id'] = (int)$prefs['user_id'];
        $prefs['notify_quota_hit'] = (bool)$prefs['notify_quota_hit'];
        $prefs['notify_order_status'] = (bool)$prefs['notify_order_status'];
        $prefs['notify_decay'] = (bool)$prefs['notify_decay'];
        $prefs['notify_menu_published'] = (bool)$prefs['notify_menu_published'];
        $prefs['notify_low_stock'] = (bool)$prefs['notify_low_stock'];
        $prefs['notify_daily_audit'] = (bool)$prefs['notify_daily_audit'];
    }

    jsonResponse($prefs ?: new stdClass());
}

function handlePatchPreferences($pdo, $userId, $input = null)
{
    $userId = (int)$userId;
    if ($userId <= 0) {
        jsonResponse(['error' => 'Unauthorized user'], 401);
    }

    if ($input === null) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }

    $allowedFields = [
        'default_portion',
        'notify_quota_hit',
        'notify_order_status',
        'notify_decay',
        'notify_menu_published',
        'notify_low_stock',
        'notify_daily_audit'
    ];

    $updates = [];
    $params = [];

    foreach ($input as $key => $value) {
        if (in_array($key, $allowedFields, true)) {
            $updates[] = "`$key` = ?";
            if (is_bool($value)) {
                $params[] = $value ? 1 : 0;
            } else {
                $params[] = $value;
            }
        }
    }

    if (empty($updates)) {
        jsonResponse(['status' => 'success', 'message' => 'No fields updated']);
    }

    // Ensure the record exists prior to update
    $check = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = ?");
    $check->execute([$userId]);
    if (!$check->fetchColumn()) {
        $pdo->prepare("INSERT INTO user_preferences (user_id) VALUES (?)")->execute([$userId]);
    }

    $params[] = $userId;
    $sql = "UPDATE user_preferences SET " . implode(', ', $updates) . " WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    jsonResponse(['status' => 'success']);
}
