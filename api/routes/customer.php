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
               m.category, m.dietary, m.ai_confirmed, m.calories, m.protein_g, m.carbs_g, m.fat_g, m.sodium_mg,
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
        
        // 3. Fetch ingredients
        $istmt = $pdo->prepare("
            SELECT i.name 
            FROM menu_item_ingredients mii
            JOIN ingredients i ON mii.ingredient_id = i.id
            WHERE mii.menu_item_id = ?
        ");
        $istmt->execute([$item['id']]);
        $ingredients = $istmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Parse dietary JSON
        $dietary = [];
        if (!empty($item['dietary'])) {
            $parsed = json_decode($item['dietary'], true);
            if (is_array($parsed)) {
                $dietary = $parsed;
            }
        }
        
        // Conditionally include macros
        $macros = null;
        if (!empty($item['ai_confirmed'])) {
            $macros = [
                'calories' => $item['calories'],
                'protein_g' => $item['protein_g'],
                'carbs_g' => $item['carbs_g'],
                'fat_g' => $item['fat_g'],
                'sodium_mg' => $item['sodium_mg']
            ];
        }

        $result[] = [
            'id' => $item['id'],
            'name' => $item['name'],
            'description' => $item['description'],
            'category' => $item['category'] ?? 'Uncategorized',
            'dietary' => $dietary,
            'current_price' => round($current_price, 2),
            'base_price' => $item['base_price'],
            'stock' => $item['online_pool_qty'], // For frontend UI
            'online_pool_qty' => $item['online_pool_qty'], // Backwards compatibility
            'ingredients' => $ingredients,
            'allergens' => $critical_allergens,
            'critical_allergens' => $critical_allergens,
            'sensitivities' => $digestive_sensitivities,
            'digestive_sensitivities' => $digestive_sensitivities,
            'macros' => $macros,
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
        
        $fulfillment_type = !empty($input['fulfillment_type']) && in_array($input['fulfillment_type'], ['take_out', 'dine_in']) 
            ? $input['fulfillment_type'] 
            : 'dine_in';
        $insertOrder = $pdo->prepare("INSERT INTO orders (user_id, store_id, status, ttl_expires_at, claim_token, fulfillment_type) VALUES (?, ?, 'reserved', DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?, ?)");
        $insertOrder->execute([$user_id, $store_id, $claimToken, $fulfillment_type]);
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
    
    $stmt = $pdo->prepare("SELECT id, store_id, status, reserved_at, ttl_expires_at, claim_token, total_amount, fulfillment_type FROM orders WHERE user_id = ? ORDER BY reserved_at DESC");
    $stmt->execute([$user_id]);
    jsonResponse($stmt->fetchAll());
}

function handleUpdateFulfillment($pdo) {
    $user_id = requireCustomer($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['token']) || empty($input['fulfillment_type'])) {
        jsonResponse(['error' => 'Missing token or fulfillment_type'], 400);
    }

    $fulfillment_type = in_array($input['fulfillment_type'], ['take_out', 'dine_in']) 
        ? $input['fulfillment_type'] 
        : 'dine_in';

    $stmt = $pdo->prepare("UPDATE orders SET fulfillment_type = ? WHERE claim_token = ? AND user_id = ?");
    $stmt->execute([$fulfillment_type, $input['token'], $user_id]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => 'Order not found or unauthorized'], 404);
    }

    jsonResponse(['message' => 'Fulfillment type updated successfully']);
}

function handleGetProfile($pdo) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $stmtUser = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM student_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        jsonResponse([
            'user_id' => (int)$user_id,
            'name' => $user['name'] ?? '',
            'email' => $user['email'] ?? '',
            'target_calories' => 2000,
            'allergens' => [],
            'dietary_boundaries' => [],
            'sensitivities' => [],
            'custom_watchlist' => []
        ]);
    }

    jsonResponse([
        'user_id' => (int)$user_id,
        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'target_calories' => (int)($profile['target_calories'] ?? 2000),
        'allergens' => json_decode($profile['allergens'] ?? '[]', true) ?: [],
        'dietary_boundaries' => json_decode($profile['dietary_boundaries'] ?? '[]', true) ?: [],
        'sensitivities' => json_decode($profile['sensitivities'] ?? '[]', true) ?: [],
        'custom_watchlist' => json_decode($profile['custom_watchlist'] ?? '[]', true) ?: []
    ]);
}

function handleUpdateProfile($pdo) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        jsonResponse(['error' => 'Invalid JSON input'], 400);
    }

    $targetCalories = isset($input['target_calories']) ? (int)$input['target_calories'] : 2000;
    $allergens = json_encode($input['allergens'] ?? []);
    $dietaryBoundaries = json_encode($input['dietary_boundaries'] ?? ($input['dietary'] ?? []));
    $sensitivities = json_encode($input['sensitivities'] ?? []);
    $customWatchlist = json_encode($input['custom_watchlist'] ?? []);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO student_profiles (user_id, target_calories, allergens, dietary_boundaries, sensitivities, custom_watchlist)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                target_calories = VALUES(target_calories),
                allergens = VALUES(allergens),
                dietary_boundaries = VALUES(dietary_boundaries),
                sensitivities = VALUES(sensitivities),
                custom_watchlist = VALUES(custom_watchlist),
                updated_at = NOW()
        ");
        $stmt->execute([
            $user_id,
            $targetCalories,
            $allergens,
            $dietaryBoundaries,
            $sensitivities,
            $customWatchlist
        ]);

        jsonResponse([
            'message' => 'Profile updated successfully',
            'profile' => [
                'user_id' => (int)$user_id,
                'target_calories' => $targetCalories,
                'allergens' => json_decode($allergens, true),
                'dietary_boundaries' => json_decode($dietaryBoundaries, true),
                'sensitivities' => json_decode($sensitivities, true),
                'custom_watchlist' => json_decode($customWatchlist, true)
            ]
        ]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }
}

