<?php
function requireCustomer($pdo) {
    $session = requireRole($pdo, ['student', 'customer']);
    return $session['user_id'];
}

function getCustomerSettings($pdo) {
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        $pdo->exec("INSERT IGNORE INTO settings (id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, ai_enabled, tier1_minutes_before_close, tier1_discount_percent, tier2_minutes_before_close, tier2_discount_percent, emergency_clearance_active) VALUES (1, 30, '07:00', '20:00', 5, 10.00, 1, 120, 25.00, 60, 40.00, 0)");
        $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $settings;
}

function expireUnclaimedOrdersAfterClosing($pdo) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'reserved'");
        $stmt->execute();
        $expired_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($expired_orders) > 0) {
            $placeholders = str_repeat('?,', count($expired_orders) - 1) . '?';
            $updateStatus = $pdo->prepare("UPDATE orders SET status = 'expired' WHERE id IN ($placeholders)");
            $updateStatus->execute($expired_orders);

            $itemsStmt = $pdo->prepare("SELECT menu_item_id, quantity, order_id FROM order_items WHERE order_id IN ($placeholders)");
            $itemsStmt->execute($expired_orders);
            $order_items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreInv = $pdo->prepare("
                UPDATE inventory 
                SET online_pool_qty = online_pool_qty + ?, 
                    delisted_from_app = CASE WHEN online_pool_qty + ? <= critical_stock_threshold THEN 1 ELSE 0 END 
                WHERE menu_item_id = ?
            ");
            $refundClaim = $pdo->prepare("
                UPDATE daily_discount_claims d
                JOIN orders o ON o.user_id = d.user_id
                SET d.quantity_claimed = GREATEST(0, d.quantity_claimed - ?)
                WHERE d.menu_item_id = ? AND o.id = ? AND d.claim_date = DATE(o.reserved_at)
            ");

            foreach ($order_items as $item) {
                $restoreInv->execute([$item['quantity'], $item['quantity'], $item['menu_item_id']]);
                $refundClaim->execute([$item['quantity'], $item['menu_item_id'], $item['order_id']]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("expireUnclaimedOrdersAfterClosing error: " . $e->getMessage());
    }
}

function generatePickupSlots($closingTimeStr, $currentTimeStr = 'now', $openingTimeStr = null) {
    date_default_timezone_set('Asia/Manila');
    $tz = new DateTimeZone('Asia/Manila');
    
    try {
        $now = new DateTime($currentTimeStr, $tz);
    } catch (Exception $e) {
        $now = new DateTime('now', $tz);
    }

    $closingStr = !empty($closingTimeStr) ? trim($closingTimeStr) : '20:00';
    $closing = new DateTime($now->format('Y-m-d') . ' ' . $closingStr, $tz);

    $openingStr = !empty($openingTimeStr) ? trim($openingTimeStr) : '06:00';
    $opening = new DateTime($now->format('Y-m-d') . ' ' . $openingStr, $tz);

    // If closing_time crosses midnight (e.g., closes at 02:00 but opens at 08:00),
    // overnight hours across midnight are unsupported. Return empty array.
    if ($closing < $opening) {
        return [];
    }

    // If current time is already at or past closing time today, return empty array
    if ($now >= $closing) {
        return [];
    }

    // Generate slots starting from max($now, $opening_time)
    if ($now < $opening) {
        $slotTime = clone $opening;
        $slotMinutes = (int)$slotTime->format('i');
        $rem = $slotMinutes % 15;
        if ($rem !== 0) {
            $slotTime->modify('+' . (15 - $rem) . ' minutes');
        }
        $slotTime->setTime((int)$slotTime->format('H'), (int)$slotTime->format('i'), 0);
    } else {
        // Round up to the next 15-minute boundary
        $currentMinutes = (int)$now->format('i');
        $currentSeconds = (int)$now->format('s');
        $remainder = $currentMinutes % 15;
        
        $minutesToAdd = (15 - $remainder);
        if ($remainder === 0 && $currentSeconds === 0) {
            $minutesToAdd = 15;
        }

        $slotTime = (clone $now)->modify("+{$minutesToAdd} minutes");
        $slotTime->setTime((int)$slotTime->format('H'), (int)$slotTime->format('i'), 0);
    }

    $slots = [];
    while ($slotTime <= $closing) {
        $slots[] = $slotTime->format('H:i');
        $slotTime->modify('+15 minutes');
    }

    return $slots;
}

function handleGetPickupSlots($pdo) {
    date_default_timezone_set('Asia/Manila');
    $settings = getCustomerSettings($pdo);
    $closingTime = $settings['closing_time'] ?? '20:00';
    $openingTime = $settings['opening_time'] ?? '06:00';
    
    $slots = generatePickupSlots($closingTime, 'now', $openingTime);
    
    jsonResponse([
        'closing_time' => $closingTime,
        'opening_time' => $openingTime,
        'current_time' => date('H:i'),
        'slots' => $slots
    ]);
}

function handleGetMenu($pdo) {
    date_default_timezone_set('Asia/Manila');
    $settings = getCustomerSettings($pdo);

    $openingTime = !empty($settings['opening_time']) ? trim($settings['opening_time']) : '07:00';
    $closingTime = !empty($settings['closing_time']) ? trim($settings['closing_time']) : '20:00';

    // Closing behavior: after closing_time, menu endpoint returns no purchasable items
    if (PricingEngine::isPastClosing($settings)) {
        jsonResponse([
            'opening_time' => $openingTime,
            'closing_time' => $closingTime,
            'is_open' => false,
            'global_announcement' => $settings['global_announcement'] ?? null,
            'items' => []
        ]);
    }

    $store_id = $_GET['store_id'] ?? null;
    if (!$store_id) {
        $stmtStore = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
        $store_id = $stmtStore->fetchColumn();
        if (!$store_id) {
            $stmtStore = $pdo->query("SELECT id FROM stores LIMIT 1");
            $store_id = $stmtStore->fetchColumn();
        }
    }
    if (!$store_id) {
        jsonResponse(['error' => 'Missing store_id and no cafeteria configured'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT m.id, m.name, m.base_price, m.price_floor, m.description, m.image_url, m.ingredients_text,
               m.category, m.dietary, m.ai_confirmed, m.calories, m.protein_g, m.carbs_g, m.fat_g, m.sodium_mg,
               i.walkin_pool_qty, i.online_pool_qty, i.critical_stock_threshold, i.delisted_from_app,
               i.cooked_at, i.sell_by
        FROM menu_items m
        LEFT JOIN inventory i ON m.id = i.menu_item_id
        WHERE m.store_id = ? AND m.is_active = 1 AND i.delisted_from_app = 0 AND i.online_pool_qty > 0
    ");
    $stmt->execute([$store_id]);
    $items = $stmt->fetchAll();

    $now = time();
    $result = [];
    foreach ($items as $item) {
        // Explicitly filter out any dishes where online_pool_qty <= 0
        if ((int)($item['online_pool_qty'] ?? 0) <= 0) {
            continue;
        }

        // Food Safety Check: If current time in Asia/Manila >= sell_by, item is spoiled
        if (!empty($item['sell_by'])) {
            $sellByTimestamp = strtotime($item['sell_by']);
            if ($now >= $sellByTimestamp) {
                // Item has expired its safe holding threshold: omit from menu
                continue;
            }
        }
        // 1. Unified dynamic pricing resolution using global settings tiers
        $current_price = PricingEngine::calculateCurrentPrice(
            $item['base_price'],
            $item['price_floor'],
            $settings
        );

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

        // H1: Fetch unmapped ingredients from recipe_ingredients (where ingredient_id IS NULL)
        $unmappedStmt = $pdo->prepare("
            SELECT ri.ingredient_name
            FROM recipe_ingredients ri
            JOIN recipes r ON ri.recipe_id = r.id
            WHERE r.menu_item_id = ? AND ri.ingredient_id IS NULL
        ");
        $unmappedStmt->execute([$item['id']]);
        $unmappedNames = $unmappedStmt->fetchAll(PDO::FETCH_COLUMN);

        // Also check if ingredients_text has unmapped ingredients
        if (!empty($item['ingredients_text'])) {
            $textParts = array_filter(array_map('trim', explode(',', $item['ingredients_text'])));
            $checkIngStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
            foreach ($textParts as $tName) {
                $checkIngStmt->execute([$tName]);
                if (!$checkIngStmt->fetchColumn() && !in_array($tName, $unmappedNames, true)) {
                    $unmappedNames[] = $tName;
                }
            }
        }

        foreach ($unmappedNames as $uName) {
            $formatted = trim($uName) . " (Not in allergen list - review)";
            if (!in_array($formatted, $ingredients, true)) {
                $ingredients[] = $formatted;
            }
        }
        
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
            'cooked_at' => $item['cooked_at'] ?? null,
            'sell_by' => $item['sell_by'] ?? null,
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

    jsonResponse([
        'opening_time' => $openingTime,
        'closing_time' => $closingTime,
        'is_open' => true,
        'global_announcement' => $settings['global_announcement'] ?? null,
        'items' => $result
    ]);
}

function handleCreateReservation($pdo) {
    $user_id = requireCustomer($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Expects: { "items": [ {"menu_item_id": 1, "quantity": 1, "portion_preference": "lean_meat", "ack": true} ] }
    if (!isset($input['items']) || !is_array($input['items'])) {
        jsonResponse(['error' => 'Missing items'], 400);
    }

    $settings = getCustomerSettings($pdo);

    // Closing behavior: reject reservations after closing_time and expire unclaimed orders
    if (PricingEngine::isPastClosing($settings)) {
        expireUnclaimedOrdersAfterClosing($pdo);
        $closingTime = $settings['closing_time'] ?? '20:00';
        jsonResponse(['error' => "Cafeteria is closed. Reservations cannot be placed after closing time ($closingTime)."], 400);
    }

    // Server-resolved single cafeteria store_id
    $stmtStore = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
    $store_id = $stmtStore->fetchColumn();
    if (!$store_id) {
        $stmtStore = $pdo->query("SELECT id FROM stores LIMIT 1");
        $store_id = $stmtStore->fetchColumn();
    }
    if (!$store_id) {
        jsonResponse(['error' => 'No active cafeteria store found.'], 500);
    }

    // Server-Side Dietary Enforcement: Check for critical allergen conflicts against student profile
    $stmtProfile = $pdo->prepare("SELECT allergens FROM student_profiles WHERE user_id = ?");
    $stmtProfile->execute([$user_id]);
    $profileRow = $stmtProfile->fetch(PDO::FETCH_ASSOC);
    $userAllergens = $profileRow ? (json_decode($profileRow['allergens'] ?? '[]', true) ?: []) : [];

    $isAllergenOverride = 0;
    $allergenConflictsJson = null;

    if (!empty($userAllergens) && count($input['items']) > 0) {
        $itemIds = array_values(array_filter(array_map(function($i) {
            return isset($i['menu_item_id']) ? (int)$i['menu_item_id'] : 0;
        }, $input['items'])));

        if (count($itemIds) > 0) {
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            
            // 1. Fetch mapped critical allergens for ordered dishes
            $stmtAllergens = $pdo->prepare("
                SELECT DISTINCT ca.name 
                FROM menu_item_critical_allergens mica
                JOIN critical_allergens ca ON mica.allergen_id = ca.id
                WHERE mica.menu_item_id IN ($placeholders)
            ");
            $stmtAllergens->execute($itemIds);
            $dishAllergens = $stmtAllergens->fetchAll(PDO::FETCH_COLUMN);

            // 2. Fetch ingredients for ordered dishes for keyword cross-referencing
            $stmtIngredients = $pdo->prepare("
                SELECT DISTINCT i.name 
                FROM menu_item_ingredients mii
                JOIN ingredients i ON mii.ingredient_id = i.id
                WHERE mii.menu_item_id IN ($placeholders)
            ");
            $stmtIngredients->execute($itemIds);
            $dishIngredients = $stmtIngredients->fetchAll(PDO::FETCH_COLUMN) ?: [];

            // A1 Defense: Fetch dish-level ingredients_text
            $stmtDishText = $pdo->prepare("
                SELECT ingredients_text 
                FROM menu_items 
                WHERE id IN ($placeholders) AND ingredients_text IS NOT NULL AND ingredients_text != ''
            ");
            $stmtDishText->execute($itemIds);
            $dishTextRows = $stmtDishText->fetchAll(PDO::FETCH_COLUMN);
            $dishTextIngredients = [];
            foreach ($dishTextRows as $txt) {
                foreach (explode(',', $txt) as $part) {
                    $clean = trim($part);
                    if ($clean !== '') {
                        $dishTextIngredients[] = $clean;
                        if (!in_array($clean, $dishIngredients, true)) {
                            $dishIngredients[] = $clean;
                        }
                    }
                }
            }

            // A1 Defense: Fetch unmapped recipe ingredients (where ingredient_id IS NULL)
            $stmtUnmappedRecipe = $pdo->prepare("
                SELECT DISTINCT ri.ingredient_name
                FROM recipe_ingredients ri
                JOIN recipes r ON ri.recipe_id = r.id
                WHERE r.menu_item_id IN ($placeholders) AND ri.ingredient_id IS NULL
            ");
            $stmtUnmappedRecipe->execute($itemIds);
            $unmappedRecipeIngredients = $stmtUnmappedRecipe->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($unmappedRecipeIngredients as $uri) {
                $clean = trim($uri);
                if ($clean !== '' && !in_array($clean, $dishIngredients, true)) {
                    $dishIngredients[] = $clean;
                }
            }

            // Check which ingredients in ingredients_text are unmapped
            $unmappedFromText = [];
            if (!empty($dishTextIngredients)) {
                $checkStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
                foreach ($dishTextIngredients as $dti) {
                    $checkStmt->execute([$dti]);
                    if (!$checkStmt->fetchColumn()) {
                        $unmappedFromText[] = $dti;
                    }
                }
            }

            $allUnmappedIngredients = array_values(array_unique(array_filter(array_merge($unmappedRecipeIngredients, $unmappedFromText))));

            $allergenKeywords = [
                'dairy' => ['dairy', 'milk', 'cheese', 'butter', 'cream', 'condensed milk', 'evaporated milk', 'curd', 'whey', 'yogurt'],
                'peanuts' => ['peanut', 'peanuts', 'peanut butter'],
                'eggs' => ['egg', 'eggs', 'egg noodles', 'mayo', 'mayonnaise'],
                'fish' => ['fish', 'fish sauce', 'bangus', 'tilapia', 'tuna', 'salmon'],
                'soy' => ['soy', 'soy sauce', 'tofu', 'edamame', 'miso'],
                'shellfish / crustaceans' => ['shellfish', 'crustaceans', 'shrimp', 'crab', 'squid', 'clams', 'prawn', 'lobster', 'oyster'],
                'shrimp paste (bagoong)' => ['shrimp paste', 'bagoong', 'alamang'],
                'wheat/gluten' => ['wheat', 'gluten', 'flour', 'bread', 'pasta', 'noodles', 'bun', 'buns'],
                'sesame' => ['sesame', 'sesame oil', 'sesame seeds']
            ];

            $conflictingAllergens = [];
            foreach ($userAllergens as $ua) {
                $uaLower = strtolower(trim($ua));
                if (!$uaLower) continue;

                // Direct check against mapped allergens
                foreach ($dishAllergens as $da) {
                    $daLower = strtolower(trim($da));
                    if ($daLower === $uaLower || str_contains($daLower, $uaLower) || str_contains($uaLower, $daLower)) {
                        $conflictingAllergens[] = $ua;
                        break;
                    }
                }

                // Ingredient keyword check (scans mapped, text, and unmapped ingredients)
                $keywords = $allergenKeywords[$uaLower] ?? [$uaLower];
                foreach ($dishIngredients as $di) {
                    $diLower = strtolower(trim($di));
                    foreach ($keywords as $kw) {
                        if (str_contains($diLower, $kw)) {
                            $conflictingAllergens[] = $ua;
                            break 2;
                        }
                    }
                }
            }

            // A1 Defense: If student has declared allergens and an unmapped ingredient is present, force "Needs Review"
            if (!empty($allUnmappedIngredients)) {
                foreach ($allUnmappedIngredients as $unmappedItem) {
                    $conflictingAllergens[] = "$unmappedItem (Unmapped - Needs Review)";
                }
            }

            $conflictingAllergens = array_values(array_unique($conflictingAllergens));

            if (!empty($conflictingAllergens)) {
                $hasOverride = !empty($input['allergen_override']) && $input['allergen_override'] === true;
                if (!$hasOverride) {
                    $conflictList = implode(', ', $conflictingAllergens);
                    jsonResponse([
                        'error' => "Safety Restriction: Cart contains $conflictList. You must acknowledge the risk to proceed.",
                        'conflicting_allergens' => $conflictingAllergens
                    ], 400);
                }
                // Only store allergen_override = 1 if conflicts were actually found by the server
                $isAllergenOverride = 1;
                $allergenConflictsJson = json_encode($conflictingAllergens);
            }
        }
    }

    // Pre-flight Checkout Defense: explicitly verify that online_pool_qty >= quantity for every item in the cart BEFORE creating the order
    $cartTotals = [];
    foreach ($input['items'] as $item) {
        $itemId = (int)($item['menu_item_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            jsonResponse(['error' => 'Invalid item or quantity in reservation request.'], 400);
        }
        $cartTotals[$itemId] = ($cartTotals[$itemId] ?? 0) + $qty;
    }

    foreach ($cartTotals as $itemId => $requiredQty) {
        $stmtStock = $pdo->prepare("
            SELECT m.name, COALESCE(i.online_pool_qty, 0) AS online_pool_qty, i.sell_by
            FROM menu_items m
            LEFT JOIN inventory i ON m.id = i.menu_item_id
            WHERE m.id = ?
        ");
        $stmtStock->execute([$itemId]);
        $stockRow = $stmtStock->fetch(PDO::FETCH_ASSOC);

        $dishName = $stockRow ? $stockRow['name'] : "Dish #$itemId";
        $availableQty = $stockRow ? max(0, (int)$stockRow['online_pool_qty']) : 0;

        if (!$stockRow || $availableQty < $requiredQty) {
            jsonResponse(['error' => "Out of stock: Only {$availableQty} portions of {$dishName} remaining."], 400);
        }

        // Food Safety Check: Sell-By timestamp
        if (!empty($stockRow['sell_by'])) {
            date_default_timezone_set('Asia/Manila');
            if (time() >= strtotime($stockRow['sell_by'])) {
                jsonResponse(['error' => "Food safety violation: Item '{$dishName}' has passed its safe sell-by expiration time ({$stockRow['sell_by']}) and cannot be ordered."], 400);
            }
        }
    }

    date_default_timezone_set('Asia/Manila');
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $closingTime = $settings['closing_time'] ?? '20:00';
    $openingTime = $settings['opening_time'] ?? '06:00';

    $fulfillment_type = !empty($input['fulfillment_type']) && in_array($input['fulfillment_type'], ['take_out', 'dine_in']) 
        ? $input['fulfillment_type'] 
        : 'dine_in';

    $pickup_time = null;
    $pickupDateTime = null;
    if ($fulfillment_type === 'take_out') {
        $pickup_time = !empty($input['pickup_time']) ? trim($input['pickup_time']) : null;
        if (empty($pickup_time)) {
            jsonResponse(['error' => 'Please select a pickup time slot for take-out orders.'], 400);
        }
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $pickup_time)) {
            jsonResponse(['error' => 'Invalid pickup time format. Must be HH:MM.'], 400);
        }

        // Validate that pickup_time is present in generatePickupSlots output
        $availableSlots = generatePickupSlots($closingTime, 'now', $openingTime);
        if (!in_array($pickup_time, $availableSlots, true)) {
            jsonResponse(['error' => 'Selected pickup time slot is not available or past cafeteria hours.'], 422);
        }

        // Validate pickup_time >= NOW()
        $pickupDateTime = new DateTime($now->format('Y-m-d') . ' ' . $pickup_time, $tz);
        if ($pickupDateTime < $now) {
            jsonResponse(['error' => 'Pickup time must be in the future.'], 422);
        }
    }

    $pdo->beginTransaction();
    try {
        $total_amount = 0;
        
        do {
            $claimToken = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $checkToken = $pdo->prepare("SELECT 1 FROM orders WHERE store_id = ? AND claim_token = ? AND status IN ('pending', 'ready', 'reserved', 'paid')");
            $checkToken->execute([$store_id, $claimToken]);
        } while ($checkToken->fetchColumn());

        // Order Expiry (TTL): Calculate expiry as max(standard_ttl_time, pickup_slot_time), but capped at closing_time.
        $reservationTtl = isset($settings['reservation_ttl']) ? max(1, (int)$settings['reservation_ttl']) : 30;
        $standardTtlTime = (clone $now)->modify("+{$reservationTtl} minutes");

        if ($fulfillment_type === 'take_out' && $pickupDateTime) {
            $expiryTime = ($pickupDateTime > $standardTtlTime) ? clone $pickupDateTime : clone $standardTtlTime;
        } else {
            $expiryTime = clone $standardTtlTime;
        }

        $closingDateTime = new DateTime($now->format('Y-m-d') . ' ' . $closingTime, $tz);
        if ($expiryTime > $closingDateTime) {
            $expiryTime = clone $closingDateTime;
        }
        $expiryTimeFormatted = $expiryTime->format('Y-m-d H:i:s');

        $insertOrder = $pdo->prepare("
            INSERT INTO orders (
                user_id, store_id, status, ttl_expires_at, claim_token, 
                fulfillment_type, pickup_time, notification_status, 
                allergen_override, allergen_conflicts
            ) VALUES (?, ?, 'reserved', ?, ?, ?, ?, 'none', ?, ?)
        ");
        $insertOrder->execute([
            $user_id, $store_id, $expiryTimeFormatted, $claimToken, 
            $fulfillment_type, $pickup_time, $isAllergenOverride, $allergenConflictsJson
        ]);
        $order_id = $pdo->lastInsertId();

        $insertItem = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price_at_order, portion_preference, best_effort_disclaimer_ack) VALUES (?, ?, ?, ?, ?, ?)");
        $updateInventory = $pdo->prepare("UPDATE inventory SET online_pool_qty = online_pool_qty - ?, delisted_from_app = CASE WHEN online_pool_qty - ? <= critical_stock_threshold THEN 1 ELSE 0 END WHERE menu_item_id = ? AND online_pool_qty >= ?");
        
        $checkClaims = $pdo->prepare("SELECT quantity_claimed FROM daily_discount_claims WHERE user_id = ? AND menu_item_id = ? AND claim_date = CURDATE()");
        $upsertClaims = $pdo->prepare("INSERT INTO daily_discount_claims (user_id, menu_item_id, claim_date, quantity_claimed) VALUES (?, ?, CURDATE(), ?) ON DUPLICATE KEY UPDATE quantity_claimed = quantity_claimed + ?");

        foreach ($input['items'] as $item) {
            $item_id = $item['menu_item_id'];
            $qty = (int)$item['quantity'];

            // Validate inventory, food safety & calculate price using unified PricingEngine
            $stmt = $pdo->prepare("
                SELECT m.name, m.base_price, m.price_floor, COALESCE(i.online_pool_qty, 0) AS online_pool_qty, i.sell_by 
                FROM menu_items m 
                LEFT JOIN inventory i ON m.id = i.menu_item_id 
                WHERE m.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$item_id]);
            $itemData = $stmt->fetch();
            
            $dishName = $itemData ? $itemData['name'] : "Item $item_id";
            $availableQty = $itemData ? max(0, (int)$itemData['online_pool_qty']) : 0;

            if (!$itemData || $availableQty < $qty) {
                throw new BusinessException("Out of stock: Only {$availableQty} portions of {$dishName} remaining.", 400);
            }

            // Food Safety Enforcement: Check sell_by in Asia/Manila
            if (!empty($itemData['sell_by'])) {
                $sellByDateTime = new DateTime($itemData['sell_by'], $tz);
                if ($now >= $sellByDateTime) {
                    throw new BusinessException("Food safety violation: Item '$dishName' has passed its safe sell-by expiration time ({$itemData['sell_by']}) and cannot be ordered.", 422);
                }
                // Pickup time must be <= sell_by of all requested items
                if ($fulfillment_type === 'take_out' && $pickupDateTime && $pickupDateTime > $sellByDateTime) {
                    throw new BusinessException("Pickup time ({$pickup_time}) exceeds safe sell-by time for '{$dishName}' ({$itemData['sell_by']}).", 422);
                }
            }

            $current_price = PricingEngine::calculateCurrentPrice(
                $itemData['base_price'],
                $itemData['price_floor'],
                $settings
            );
            $is_discounted = ($current_price < $itemData['base_price']);

            // Anti-hoarding check using max_discount_qty from global settings
            if ($is_discounted) {
                $checkClaims->execute([$user_id, $item_id]);
                $claimed = $checkClaims->fetchColumn() ?: 0;
                $maxDiscountQty = isset($settings['max_discount_qty']) ? (int)$settings['max_discount_qty'] : 5;
                if ($claimed + $qty > $maxDiscountQty) {
                    throw new BusinessException("Daily discount claim limit exceeded for item $item_id", 422);
                }
                $upsertClaims->execute([$user_id, $item_id, $qty, $qty]);
            }

            $allowedPrefs = ['no_preference', 'lean_meat', 'bone_fat', 'extra_sauce'];
            $portionPref = in_array($item['portion_preference'] ?? '', $allowedPrefs) 
                ? $item['portion_preference'] 
                : 'no_preference';

            $insertItem->execute([
                $order_id, 
                $item_id, 
                $qty, 
                $current_price, 
                $portionPref, 
                $item['ack'] ?? 0
            ]);

            $total_amount += ($current_price * $qty);

            $updateInventory->execute([$qty, $qty, $item_id, $qty]);
            if ($updateInventory->rowCount() === 0) {
                throw new BusinessException("Out of stock: Only 0 portions of {$dishName} remaining.", 400);
            }
        }

        $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $order_id]);
        
        $pdo->commit();
        jsonResponse(['message' => 'Reservation created', 'order_id' => $order_id, 'total_amount' => $total_amount, 'claim_token' => $claimToken]);
    } catch (BusinessException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $code = ($e->getCode() >= 400 && $e->getCode() < 500) ? (int)$e->getCode() : 422;
        jsonResponse(['error' => $e->getMessage()], $code);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("handleCreateReservation error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
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
            throw new BusinessException("Order not found", 404);
        }
        if ($order['status'] !== 'reserved') {
            throw new BusinessException("Order is not in reserved status", 400);
        }
        if (new DateTime() > new DateTime($order['ttl_expires_at'])) {
            throw new BusinessException("Reservation expired", 400);
        }

        $updateOrder = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE id = ?");
        $updateOrder->execute([$order_id]);

        $insertPayment = $pdo->prepare("INSERT INTO payments (order_id, amount, method, status, paid_at) VALUES (?, ?, ?, 'completed', NOW())");
        $insertPayment->execute([$order_id, $order['total_amount'], $method]);

        $pdo->commit();
        jsonResponse(['message' => 'Payment successful', 'claim_token' => $order['claim_token']]);
    } catch (BusinessException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $code = ($e->getCode() >= 400 && $e->getCode() < 500) ? (int)$e->getCode() : 400;
        jsonResponse(['error' => $e->getMessage()], $code);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("handlePayReservation error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
}

function handleGetCustomerOrders($pdo) {
    $user_id = requireCustomer($pdo);
    
    $stmt = $pdo->prepare("SELECT id, store_id, status, reserved_at, ttl_expires_at, claim_token, total_amount, fulfillment_type, pickup_time, notification_status, allergen_override, allergen_conflicts FROM orders WHERE user_id = ? ORDER BY reserved_at DESC");
    $stmt->execute([$user_id]);
    jsonResponse($stmt->fetchAll());
}

function handleGetMyOrders($pdo) {
    $user_id = requireCustomer($pdo);

    $stmt = $pdo->prepare("
        SELECT id, store_id, status, reserved_at, ttl_expires_at, claim_token, total_amount, fulfillment_type, pickup_time, notification_status, allergen_override, allergen_conflicts
        FROM orders
        WHERE user_id = ?
        ORDER BY reserved_at DESC
    ");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $orderIds = array_column($orders, 'id');
    if (!empty($orderIds)) {
        $inQuery = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $pdo->prepare("
            SELECT oi.id, oi.order_id, oi.menu_item_id, oi.quantity, oi.unit_price_at_order, oi.portion_preference,
                   COALESCE(m.name, 'Item') AS item_name, m.image_url
            FROM order_items oi
            LEFT JOIN menu_items m ON oi.menu_item_id = m.id
            WHERE oi.order_id IN ($inQuery)
            ORDER BY oi.id ASC
        ");
        $itemStmt->execute($orderIds);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $itemsByOrder = [];
        foreach ($items as $item) {
            $itemsByOrder[$item['order_id']][] = $item;
        }

        foreach ($orders as &$order) {
            $order['items'] = $itemsByOrder[$order['id']] ?? [];
        }
    } else {
        foreach ($orders as &$order) {
            $order['items'] = [];
        }
    }

    jsonResponse($orders);
}

function handleUpdateFulfillment($pdo) {
    $user_id = requireCustomer($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['token']) || empty($input['fulfillment_type'])) {
        jsonResponse(['error' => 'Missing token or fulfillment_type'], 400);
    }

    try {
        $fulfillment_type = $input['fulfillment_type'];
        if (!in_array($fulfillment_type, ['take_out', 'dine_in'], true)) {
            throw new BusinessException('Invalid fulfillment type', 400);
        }

        $stmt = $pdo->prepare("SELECT id, status, store_id, reserved_at FROM orders WHERE claim_token = ? AND user_id = ?");
        $stmt->execute([$input['token'], $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new BusinessException('Order not found or unauthorized', 404);
        }

        if ($order['status'] !== 'reserved') {
            throw new BusinessException('Fulfillment type can only be updated while order is in reserved status', 400);
        }

        date_default_timezone_set('Asia/Manila');
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);

        if ($fulfillment_type === 'take_out') {
            $pickup_time = !empty($input['pickup_time']) ? trim($input['pickup_time']) : null;
            if (empty($pickup_time)) {
                throw new BusinessException('Please select a pickup time slot for take-out orders.', 400);
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $pickup_time)) {
                throw new BusinessException('Invalid pickup time format. Must be HH:MM.', 400);
            }

            $settings = getCustomerSettings($pdo);
            $closingTime = $settings['closing_time'] ?? '20:00';
            $openingTime = $settings['opening_time'] ?? '06:00';

            // Validate that pickup_time is present in generatePickupSlots output
            $availableSlots = generatePickupSlots($closingTime, 'now', $openingTime);
            if (!in_array($pickup_time, $availableSlots, true)) {
                throw new BusinessException('Selected pickup time slot is not available or past cafeteria hours.', 422);
            }

            // Validate pickup_time >= NOW()
            $pickupDateTime = new DateTime($now->format('Y-m-d') . ' ' . $pickup_time, $tz);
            if ($pickupDateTime < $now) {
                throw new BusinessException('Pickup time must be in the future.', 422);
            }

            // Validate pickup_time <= sell_by of all items in this order
            $stmtItems = $pdo->prepare("
                SELECT m.name, i.sell_by
                FROM order_items oi
                JOIN menu_items m ON oi.menu_item_id = m.id
                LEFT JOIN inventory i ON m.id = i.menu_item_id
                WHERE oi.order_id = ?
            ");
            $stmtItems->execute([$order['id']]);
            $orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

            foreach ($orderItems as $item) {
                if (!empty($item['sell_by'])) {
                    $sellByDateTime = new DateTime($item['sell_by'], $tz);
                    if ($now >= $sellByDateTime) {
                        throw new BusinessException("Food safety violation: Item '{$item['name']}' has passed its safe sell-by expiration time ({$item['sell_by']}) and cannot be ordered.", 422);
                    }
                    if ($pickupDateTime > $sellByDateTime) {
                        throw new BusinessException("Pickup time ({$pickup_time}) exceeds safe sell-by time for '{$item['name']}' ({$item['sell_by']}).", 422);
                    }
                }
            }

            // Recalculate expiry time as max(standard_ttl_time, pickup_slot_time), capped at closing_time
            $reservationTtl = isset($settings['reservation_ttl']) ? max(1, (int)$settings['reservation_ttl']) : 30;
            $standardTtlTime = (clone $now)->modify("+{$reservationTtl} minutes");
            $expiryTime = ($pickupDateTime > $standardTtlTime) ? clone $pickupDateTime : clone $standardTtlTime;
            $closingDateTime = new DateTime($now->format('Y-m-d') . ' ' . $closingTime, $tz);
            if ($expiryTime > $closingDateTime) {
                $expiryTime = clone $closingDateTime;
            }
            $expiryTimeFormatted = $expiryTime->format('Y-m-d H:i:s');

            $updateStmt = $pdo->prepare("UPDATE orders SET fulfillment_type = 'take_out', pickup_time = ?, ttl_expires_at = ? WHERE id = ?");
            $updateStmt->execute([$pickup_time, $expiryTimeFormatted, $order['id']]);
        } else {
            // dine_in
            $updateStmt = $pdo->prepare("UPDATE orders SET fulfillment_type = 'dine_in', pickup_time = NULL WHERE id = ?");
            $updateStmt->execute([$order['id']]);
        }

        jsonResponse(['message' => 'Fulfillment type updated successfully']);
    } catch (BusinessException $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() < 500) ? (int)$e->getCode() : 400;
        jsonResponse(['error' => $e->getMessage()], $code);
    } catch (Exception $e) {
        error_log("handleUpdateFulfillment error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
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
        error_log("handleUpdateProfile error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected database error occurred.'], 500);
    }
}

