<?php

/**
 * OptiMeal Notifications Route Handlers
 */

function handleGetNotifications($param1, $param2 = null) {
    global $pdo;
    if ($param1 instanceof PDO) {
        $db = $param1;
        $userId = (int)($param2 ?? ($_SESSION['user_id'] ?? 0));
    } else {
        $userId = (int)$param1;
        $db = ($param2 instanceof PDO) ? $param2 : $pdo;
    }

    if ($userId <= 0) {
        jsonResponse(['error' => 'Unauthorized. Invalid user ID.'], 401);
    }

    $stmtList = $db->prepare("
        SELECT id, user_id, type, message, reference_id, is_read, created_at 
        FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC, id DESC 
        LIMIT 50
    ");
    $stmtList->execute([$userId]);
    $notifications = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notifications as &$n) {
        $n['id'] = (int)$n['id'];
        $n['user_id'] = (int)$n['user_id'];
        $n['reference_id'] = $n['reference_id'] !== null ? (int)$n['reference_id'] : null;
        $n['is_read'] = (bool)$n['is_read'];
    }
    unset($n);

    $stmtUnread = $db->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE user_id = ? AND is_read = 0
    ");
    $stmtUnread->execute([$userId]);
    $unreadCount = (int)$stmtUnread->fetchColumn();

    jsonResponse([
        'notifications' => $notifications,
        'unread_count' => $unreadCount
    ]);
}

function handleMarkAsRead($param1, $param2 = null, $param3 = null) {
    global $pdo;
    if ($param1 instanceof PDO) {
        $db = $param1;
        $userId = (int)($param2 ?? ($_SESSION['user_id'] ?? 0));
        $input = is_array($param3) ? $param3 : (json_decode(file_get_contents('php://input'), true) ?? []);
    } else {
        $userId = (int)$param1;
        if ($param2 instanceof PDO) {
            $db = $param2;
            $input = is_array($param3) ? $param3 : (json_decode(file_get_contents('php://input'), true) ?? []);
        } else {
            $db = ($param3 instanceof PDO) ? $param3 : $pdo;
            $input = is_array($param2) ? $param2 : (json_decode(file_get_contents('php://input'), true) ?? []);
        }
    }

    if ($userId <= 0) {
        jsonResponse(['error' => 'Unauthorized. Invalid user ID.'], 401);
    }

    $notificationId = $input['notification_id'] ?? ($input['id'] ?? null);

    if ($notificationId !== null && $notificationId !== '' && $notificationId !== 'all') {
        $notifIdInt = (int)$notificationId;
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notifIdInt, $userId]);
    } else {
        $stmt = $db->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
    }

    jsonResponse([
        'success' => true
    ]);
}
