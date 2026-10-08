<?php
function requireCustomer($pdo) {
    $session = requireRole($pdo, ['student', 'customer']);
    return $session['user_id'];
}

function handleGetMenu($pdo) {
    $store_id = $_GET['store_id'] ?? null;
    if (!$store_id) {
        jsonResponse(['error' => 'Missing store_id'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.base_price, m.price_floor, m.description, m.image_url,
               i.walkin_pool_qty, i.online_pool_qty, i.critical_stock_threshold, i.delisted_from_app,
               pr.rush_hour_end_time, pr.decay_start_offset_minutes, pr.decay_rate_percent, 
               pr.decay_interval_minutes, pr.min_stock_to_trigger_decay
        FROM menu_items m
        LEFT JOIN inventory i ON m.id = i.menu_item_id
        LEFT JOIN pricing_rules pr ON m.id = pr.menu_item_id
        WHERE m.store_id = ? AND m.is_active = 1 AND i.delisted_from_app = 0
    ");
    $stmt->execute([$store_id]);
    $items = $stmt->fetchAll();

    $result = [];
    foreach ($items as $item) {
        $current_price = $item['base_price'];
        
        // 1. Dynamic pricing resolution using PricingEngine
        if ($item['rush_hour_end_time']) {
            $current_price = PricingEngine::calculateCurrentPrice(
                $item['base_price'], $item['price_floor'], $item['rush_hour_end_time'],
                $item['decay_start_offset_minutes'], $item['decay_rate_percent'],
                $item['decay_interval_minutes'], $item['min_stock_to_trigger_decay'],
                $item['online_pool_qty']
            );
        }

        // 2. Fetch allergens & sensitivities
        $astmt = $pdo->prepare("
            SELECT ca.name 
            FROM menu_item_critical_allergens mca
            JOIN critical_allergens ca ON mca.allergen_id = ca.id
            WHERE mca.menu_item_id = ?
        ");
        $astmt->execute([$item['id']]);
        $critical_allergens = $astmt->fetchAll(PDO::FETCH_COLUMN);

        $sstmt = $pdo->prepare("
            SELECT ds.name 
            FROM menu_item_digestive_sensitivities mds
            JOIN digestive_sensitivities ds ON mds.sensitivity_id = ds.id
            WHERE mds.menu_item_id = ?
        ");
        $sstmt->execute([$item['id']]);
        $digestive_sensitivities = $sstmt->fetchAll(PDO::FETCH_COLUMN);

        $result[] = [
            'id' => $item['id'],
            'name' => $item['name'],
            'description' => $item['description'],
            'current_price' => round($current_price, 2),
            'base_price' => $item['base_price'],
            'online_pool_qty' => $item['online_pool_qty'],
            'critical_allergens' => $critical_allergens,
            'digestive_sensitivities' => $digestive_sensitivities,
            'has_critical' => count($critical_allergens) > 0 ? 1 : 0
        ];
    }

    // 3. Sort items: critical allergens to the bottom
    usort($result, function($a, $b) {
        if ($a['has_critical'] == $b['has_critical']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['has_critical'] <=> $b['has_critical']; // 0 goes first, 1 goes last
    });

    jsonResponse($result);
}

function handleCreateReservation($pdo) {
    $user_id = requireCustomer($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Expects: { "store_id": 1, "items": [ {"menu_item_id": 1, "quantity": 1, "portion_preference": "lean_meat", "ack": true} ] }
    if (!isset($input['store_id'], $input['items'])) {
        jsonResponse(['error' => 'Missing store_id or items'], 400);
    }

    $pdo->beginTransaction();
    try {
        $total_amount = 0;
        $store_id = $input['store_id'];
        
        do {
            $claimToken = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $checkToken = $pdo->prepare("SELECT 1 FROM orders WHERE store_id = ? AND claim_token = ? AND status IN ('pending', 'ready', 'reserved', 'paid')");
            $checkToken->execute([$store_id, $claimToken]);
        } while ($checkToken->fetchColumn());
        
        $insertOrder = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, ttl_expires_at, claim_token) VALUES (?, ?, 'reserved', DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?)");
        $insertOrder->execute([$user_id, $store_id, $claimToken]);
        $order_id = $pdo->lastInsertId();

        $insertItem = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order, portion_preference, best_effort_disclaimer_ack) VALUES (?, ?, ?, ?, ?, ?)");
        $updateInventory = $pdo->prepare("UPDATE inventory SET online_pool_qty = online_pool_qty - ?, delisted_from_app = CASE WHEN online_pool_qty - ? <= critical_stock_threshold THEN 1 ELSE 0 END WHERE menu_item_id = ? AND online_pool_qty >= ?");
        
        $checkClaims = $pdo->prepare("SELECT quantity_claimed FROM daily_discount_claims WHERE user_id = ? AND menu_item_id = ? AND claim_date = CURDATE()");
        $upsertClaims = $pdo->prepare("INSERT INTO daily_discount_claims (user_id, menu_item_id, claim_date, quantity_claimed) VALUES (?, ?, CURDATE(), ?) ON DUPLICATE KEY UPDATE quantity_claimed = quantity_claimed + ?");

        foreach ($input['items'] as $item) {
            $item_id = $item['menu_item_id'];
            $qty = (int)$item['quantity'];

            // Validate inventory & calculate price for anti-hoarding limit enforcement
            $stmt = $pdo->prepare("SELECT m.base_price, m.price_floor, pr.rush_hour_end_time, pr.decay_start_offset_minutes, pr.decay_rate_percent, pr.decay_interval_minutes, pr.min_stock_to_trigger_decay, i.online_pool_qty FROM menu_items m JOIN inventory i ON m.id = i.menu_item_id LEFT JOIN pricing_rules pr ON m.id = pr.menu_item_id WHERE m.id = ?");
            $stmt->execute([$item_id]);
            $itemData = $stmt->fetch();
            
            if (!$itemData || $itemData['online_pool_qty'] < $qty) {
                throw new Exception("Not enough inventory for item $item_id");
            }

            $current_price = $itemData['base_price'];
            $is_discounted = false;

            if ($itemData['rush_hour_end_time']) {
                $current_price = PricingEngine::calculateCurrentPrice(
                    $itemData['base_price'], $itemData['price_floor'], $itemData['rush_hour_end_time'],
                    $itemData['decay_start_offset_minutes'], $itemData['decay_rate_percent'],
                    $itemData['decay_interval_minutes'], $itemData['min_stock_to_trigger_decay'],
                    $itemData['online_pool_qty']
                );
                if ($current_price < $itemData['base_price']) {
                    $is_discounted = true;
                }
            }

            // Anti-hoarding check
            if ($is_discounted) {
                $checkClaims->execute([$user_id, $item_id]);
                $claimed = $checkClaims->fetchColumn() ?: 0;
                $MAX_DISCOUNT_QTY = 3;
                if ($claimed + $qty > $MAX_DISCOUNT_QTY) {
                    throw new Exception("Daily discount claim limit exceeded for item $item_id");
                }
                $upsertClaims->execute([$user_id, $item_id, $qty, $qty]);
            }

            $insertItem->execute([
                $order_id, 
                $item_id, 
                $qty, 
                $current_price, 
                $item['portion_preference'] ?? 'no_preference', 
                $item['ack'] ?? 0
            ]);

            $total_amount += ($current_price * $qty);

            $updateInventory->execute([$qty, $qty, $item_id, $qty]);
            if ($updateInventory->rowCount() === 0) {
                throw new Exception("Failed to update inventory for item $item_id (possibly out of stock)");
            }
        }

        $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $order_id]);
        
        $pdo->commit();
        jsonResponse(['message' => 'Reservation created', 'order_id' => $order_id, 'total_amount' => $total_amount, 'claim_token' => $claimToken]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

function handlePayReservation($pdo, $order_id) {
    $user_id = requireCustomer($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    $method = $input['method'] ?? 'e-wallet';

    $pdo->beginTransaction();
    try {
        // Lock row for safe status transition
        $stmt = $pdo->prepare("SELECT total_amount, status, ttl_expires_at, claim_token FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
        $stmt->execute([$order_id, $user_id]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new Exception("Order not found");
        }
        if ($order['status'] !== 'reserved') {
            throw new Exception("Order is not in reserved status");
        }
        if (new DateTime() > new DateTime($order['ttl_expires_at'])) {
            throw new Exception("Reservation expired");
        }

        $updateOrder = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?");
        $updateOrder->execute([$order_id]);

        $insertPayment = $pdo->prepare("INSERT INTO payments (order_id, amount, method, status, paid_at) VALUES (?, ?, ?, 'completed', NOW())");
        $insertPayment->execute([$order_id, $order['total_amount'], $method]);

        $pdo->commit();
        jsonResponse(['message' => 'Payment successful', 'claim_token' => $order['claim_token']]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

function handleGetCustomerOrders($pdo) {
    $user_id = requireCustomer($pdo);
    
    $stmt = $pdo->prepare("SELECT id, store_id, status, reserved_at, ttl_expires_at, claim_token, total_amount FROM orders WHERE user_id = ? ORDER BY reserved_at DESC");
    $stmt->execute([$user_id]);
    jsonResponse($stmt->fetchAll());
}
