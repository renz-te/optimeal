<?php
function requireVendor($pdo) {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
        jsonResponse(['error' => 'Unauthorized. Vendor access required.'], 403);
    }
    
    // Resolve vendor's store
    $stmt = $pdo->prepare("SELECT id FROM stores WHERE vendor_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $store = $stmt->fetch();
    if (!$store) {
        jsonResponse(['error' => 'Vendor does not have a store configured'], 400);
    }
    return $store['id'];
}

function handleGetPricingRules($pdo) {
    $store_id = requireVendor($pdo);
    
    $stmt = $pdo->prepare("
        SELECT pr.*, m.name as item_name 
        FROM pricing_rules pr 
        JOIN menu_items m ON pr.menu_item_id = m.id 
        WHERE m.store_id = ?
    ");
    $stmt->execute([$store_id]);
    jsonResponse($stmt->fetchAll());
}

function handlePostPricingRules($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['menu_item_id'])) {
        jsonResponse(['error' => 'Missing menu_item_id'], 400);
    }
    
    // Authz boundary check
    $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE id = ? AND store_id = ?");
    $stmt->execute([$input['menu_item_id'], $store_id]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Item not found in your store'], 403);
    }

    $update = $pdo->prepare("
        INSERT INTO pricing_rules (menu_item_id, rush_hour_end_time, decay_start_offset_minutes, decay_rate_percent, decay_interval_minutes, min_stock_to_trigger_decay)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            rush_hour_end_time = VALUES(rush_hour_end_time),
            decay_start_offset_minutes = VALUES(decay_start_offset_minutes),
            decay_rate_percent = VALUES(decay_rate_percent),
            decay_interval_minutes = VALUES(decay_interval_minutes),
            min_stock_to_trigger_decay = VALUES(min_stock_to_trigger_decay)
    ");
    $update->execute([
        $input['menu_item_id'],
        $input['rush_hour_end_time'],
        $input['decay_start_offset_minutes'],
        $input['decay_rate_percent'],
        $input['decay_interval_minutes'],
        $input['min_stock_to_trigger_decay']
    ]);
    
    jsonResponse(['message' => 'Pricing rule updated']);
}

function handleGetInventory($pdo) {
    $store_id = requireVendor($pdo);
    
    $stmt = $pdo->prepare("
        SELECT i.*, m.name as item_name 
        FROM inventory i 
        JOIN menu_items m ON i.menu_item_id = m.id 
        WHERE m.store_id = ?
    ");
    $stmt->execute([$store_id]);
    jsonResponse($stmt->fetchAll());
}

function handlePostInventory($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['menu_item_id'])) {
        jsonResponse(['error' => 'Missing menu_item_id'], 400);
    }

    // Authz boundary check
    $stmt = $pdo->prepare("SELECT id FROM menu_items WHERE id = ? AND store_id = ?");
    $stmt->execute([$input['menu_item_id'], $store_id]);
    if (!$stmt->fetch()) {
        jsonResponse(['error' => 'Item not found in your store'], 403);
    }

    $update = $pdo->prepare("
        UPDATE inventory 
        SET walkin_pool_qty = ?, online_pool_qty = ?, 
            delisted_from_app = CASE WHEN ? <= critical_stock_threshold THEN 1 ELSE 0 END
        WHERE menu_item_id = ?
    ");
    $update->execute([
        $input['walkin_pool_qty'], 
        $input['online_pool_qty'], 
        $input['online_pool_qty'],
        $input['menu_item_id']
    ]);
    
    jsonResponse(['message' => 'Inventory updated']);
}

function handleGetVendorOrders($pdo) {
    $store_id = requireVendor($pdo);
    
    $stmt = $pdo->prepare("
        SELECT id, user_id, status, total_amount, claim_token, 
        CASE WHEN status = 'claimed' THEN 1 ELSE 0 END as express_pickup_shelf
        FROM orders 
        WHERE store_id = ? AND status IN ('paid', 'claimed')
        ORDER BY id ASC
    ");
    $stmt->execute([$store_id]);
    jsonResponse($stmt->fetchAll());
}
