<?php
require_once __DIR__ . '/../PricingEngine.php';
require_once __DIR__ . '/../services/PayMongoService.php';

function requireCustomer($pdo)
{
    $session = requireRole($pdo, ['student', 'customer']);
    return $session['user_id'];
}

function getCustomerSettings($pdo)
{
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        $pdo->exec("INSERT IGNORE INTO settings (id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, ai_enabled, tier1_minutes_before_close, tier1_discount_percent, tier2_minutes_before_close, tier2_discount_percent, emergency_clearance_active) VALUES (1, 30, '07:00', '20:00', 5, 10.00, 1, 120, 25.00, 60, 40.00, 0)");
        $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $settings;
}

function expireUnclaimedOrdersAfterClosing($pdo)
{
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'reserved'");
        $stmt->execute();
        $expired_orders = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($expired_orders) > 0) {
            $placeholders = str_repeat('?,', count($expired_orders) - 1) . '?';
            $updateStatus = $pdo->prepare("UPDATE orders SET status = 'expired' WHERE id IN ($placeholders)");
            $updateStatus->execute($expired_orders);

            $itemsStmt = $pdo->prepare("SELECT menu_item_id, batch_id, quantity, order_id FROM order_items WHERE order_id IN ($placeholders)");
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
            $releaseBatch = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE id = ?");
            $fallbackReleaseBatch = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE menu_item_id = ? AND held_stock > 0 ORDER BY sell_by ASC LIMIT 1");

            foreach ($order_items as $item) {
                $qty = (int)$item['quantity'];
                $restoreInv->execute([$qty, $qty, $item['menu_item_id']]);
                $refundClaim->execute([$qty, $item['menu_item_id'], $item['order_id']]);

                if (!empty($item['batch_id'])) {
                    $releaseBatch->execute([$qty, $item['batch_id']]);
                } else {
                    $fallbackReleaseBatch->execute([$qty, $item['menu_item_id']]);
                }
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

function generatePickupSlots($closingTimeStr, $currentTimeStr = 'now', $openingTimeStr = null)
{
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

function handleGetPickupSlots($pdo)
{
    date_default_timezone_set('Asia/Manila');
    $settings = getCustomerSettings($pdo);
    $closingTime = PricingEngine::getEffectiveClosingTime($settings);
    $openingTime = $settings['opening_time'] ?? '06:00';

    $slots = generatePickupSlots($closingTime, 'now', $openingTime);

    jsonResponse([
        'closing_time' => $closingTime,
        'opening_time' => $openingTime,
        'current_time' => date('H:i'),
        'slots' => $slots
    ]);
}

function handleGetMenu($pdo)
{
    date_default_timezone_set('Asia/Manila');
    $settings = getCustomerSettings($pdo);

    $openingTime = !empty($settings['opening_time']) ? trim($settings['opening_time']) : '07:00';
    $closingTime = PricingEngine::getEffectiveClosingTime($settings);

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
        SELECT m.id, m.name, m.base_price, m.price_floor, m.description, m.image_url, m.photo_path, m.ingredients_text,
               m.category, m.dietary, m.ai_confirmed, m.calories, m.protein_g, m.carbs_g, m.fat_g, m.sodium_mg,
               m.hold_hours, m.refrigerated_hours, m.reheat_hours, m.reheatable,
               b.id AS batch_id, b.state AS batch_state, b.cooked_at, b.reheated_at, b.sell_by,
               b.remaining_stock, b.held_stock, b.hold_hours AS batch_hold_hours, b.reheat_hours AS batch_reheat_hours
        FROM menu_items m
        JOIN batches b ON m.id = b.menu_item_id
        WHERE m.store_id = ? AND m.is_active = 1 AND b.remaining_stock > 0 AND (b.remaining_stock - b.held_stock) > 0 AND b.state IN ('fresh', 'reheated')
        ORDER BY m.id DESC, b.sell_by ASC
    ");
    $stmt->execute([$store_id]);
    $batchRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group batches by dish ID and pool state ('fresh', 'reheated')
    $dishes = [];
    foreach ($batchRows as $row) {
        $dishId = (int)$row['id'];
        if (!isset($dishes[$dishId])) {
            $dishes[$dishId] = [
                'item' => $row,
                'pools' => [
                    'fresh' => [],
                    'reheated' => []
                ]
            ];
        }
        $state = $row['batch_state'];
        if (isset($dishes[$dishId]['pools'][$state])) {
            $dishes[$dishId]['pools'][$state][] = $row;
        }
    }

    // Fetch logged-in user's dietary preferences (medical allergens and dietary boundaries)
    $userAllergens = [];
    $userBoundaries = [];
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId) {
        $profileStmt = $pdo->prepare("SELECT allergens, dietary_boundaries FROM student_profiles WHERE user_id = ?");
        $profileStmt->execute([$userId]);
        $userProfile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if ($userProfile) {
            $userAllergens = json_decode($userProfile['allergens'] ?? '[]', true) ?: [];
            $userBoundaries = json_decode($userProfile['dietary_boundaries'] ?? '[]', true) ?: [];
        }
    }

    $nowTimestamp = time();
    $closingTimeStr = PricingEngine::getEffectiveClosingTime($settings);
    $todayClosingTimestamp = strtotime(date('Y-m-d ') . $closingTimeStr);

    $result = [];
    foreach ($dishes as $dishId => $dishData) {
        $item = $dishData['item'];

        // 1. Fetch allergens & sensitivities
        $astmt = $pdo->prepare("
            SELECT ca.name 
            FROM menu_item_critical_allergens mca
            JOIN critical_allergens ca ON mca.allergen_id = ca.id
            WHERE mca.menu_item_id = ?
        ");
        $astmt->execute([$dishId]);
        $critical_allergens = $astmt->fetchAll(PDO::FETCH_COLUMN);

        $sstmt = $pdo->prepare("
            SELECT ds.name 
            FROM menu_item_digestive_sensitivities mids
            JOIN digestive_sensitivities ds ON mids.sensitivity_id = ds.id
            WHERE mids.menu_item_id = ?
        ");
        $sstmt->execute([$dishId]);
        $digestive_sensitivities = $sstmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Fetch ingredients
        $istmt = $pdo->prepare("
            SELECT i.name 
            FROM menu_item_ingredients mii
            JOIN ingredients i ON mii.ingredient_id = i.id
            WHERE mii.menu_item_id = ?
        ");
        $istmt->execute([$dishId]);
        $ingredients = $istmt->fetchAll(PDO::FETCH_COLUMN);

        // Fetch unmapped ingredients from recipe_ingredients (where ingredient_id IS NULL)
        $unmappedStmt = $pdo->prepare("
            SELECT ri.ingredient_name
            FROM recipe_ingredients ri
            JOIN recipes r ON ri.recipe_id = r.id
            WHERE r.menu_item_id = ? AND ri.ingredient_id IS NULL
        ");
        $unmappedStmt->execute([$dishId]);
        $unmappedNames = $unmappedStmt->fetchAll(PDO::FETCH_COLUMN);

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

        // Evaluate conflict_tier: critical, boundary, or safe (B4.4 logic)
        $hasCriticalConflict = false;
        if (!empty($userAllergens)) {
            foreach ($userAllergens as $ua) {
                $normUa = strtolower(trim($ua));
                if ($normUa === '') continue;

                foreach ($critical_allergens as $ca) {
                    $normCa = strtolower(trim($ca));
                    if ($normCa === $normUa || strpos($normCa, $normUa) !== false || strpos($normUa, $normCa) !== false) {
                        $hasCriticalConflict = true;
                        break 2;
                    }
                }

                foreach ($ingredients as $ing) {
                    $normIng = strtolower(trim($ing));
                    if (strpos($normIng, $normUa) !== false) {
                        $hasCriticalConflict = true;
                        break 2;
                    }
                }
            }
        }

        $hasBoundaryConflict = false;
        if (!$hasCriticalConflict && !empty($userBoundaries)) {
            foreach ($userBoundaries as $ub) {
                $normUb = strtolower(trim($ub));
                if ($normUb === '') continue;

                if (strpos($normUb, 'vegetarian') !== false || strpos($normUb, 'vegan') !== false) {
                    $isVegDish = false;
                    foreach ($dietary as $dt) {
                        if (stripos($dt, 'vegetarian') !== false || stripos($dt, 'vegan') !== false) {
                            $isVegDish = true;
                            break;
                        }
                    }
                    if (!$isVegDish) {
                        $meatKeywords = ['pork', 'beef', 'chicken', 'meat', 'fish', 'bacon', 'shrimp', 'crab', 'tuna', 'salmon', 'poultry'];
                        foreach ($ingredients as $ing) {
                            $normIng = strtolower($ing);
                            foreach ($meatKeywords as $mk) {
                                if (strpos($normIng, $mk) !== false) {
                                    $hasBoundaryConflict = true;
                                    break 3;
                                }
                            }
                        }
                    }
                }

                if (strpos($normUb, 'halal') !== false || strpos($normUb, 'pork-free') !== false || strpos($normUb, 'no pork') !== false) {
                    foreach ($dietary as $dt) {
                        if (stripos($dt, 'pork') !== false) {
                            $hasBoundaryConflict = true;
                            break 2;
                        }
                    }
                    foreach ($ingredients as $ing) {
                        if (stripos($ing, 'pork') !== false || stripos($ing, 'bacon') !== false || stripos($ing, 'ham') !== false || stripos($ing, 'lard') !== false) {
                            $hasBoundaryConflict = true;
                            break 2;
                        }
                    }
                }

                if (strpos($normUb, 'gluten') !== false) {
                    foreach ($ingredients as $ing) {
                        if (stripos($ing, 'wheat') !== false || stripos($ing, 'gluten') !== false || stripos($ing, 'flour') !== false) {
                            $hasBoundaryConflict = true;
                            break 2;
                        }
                    }
                }

                if (strpos($normUb, 'dairy') !== false) {
                    foreach ($ingredients as $ing) {
                        if (stripos($ing, 'milk') !== false || stripos($ing, 'cheese') !== false || stripos($ing, 'butter') !== false || stripos($ing, 'cream') !== false) {
                            $hasBoundaryConflict = true;
                            break 2;
                        }
                    }
                }

                foreach ($ingredients as $ing) {
                    $normIng = strtolower($ing);
                    if (strpos($normIng, $normUb) !== false) {
                        $hasBoundaryConflict = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasCriticalConflict) {
            $conflictTier = 'critical';
        } elseif ($hasBoundaryConflict) {
            $conflictTier = 'boundary';
        } else {
            $conflictTier = 'safe';
        }

        // 3. Process each pool: 'fresh' and 'reheated'
        foreach (['fresh', 'reheated'] as $pool_state) {
            if (empty($dishData['pools'][$pool_state])) {
                continue;
            }

            $poolBatches = $dishData['pools'][$pool_state];

            // Sum (remaining_stock - held_stock) in this pool
            $availableQty = 0;
            foreach ($poolBatches as $pb) {
                $availableQty += max(0, (int)$pb['remaining_stock'] - (int)($pb['held_stock'] ?? 0));
            }
            if ($availableQty <= 0) {
                continue;
            }

            // Earliest sell_by (FIFO)
            $earliestBatch = $poolBatches[0];
            $earliestSellBy = $earliestBatch['sell_by'];
            $earliestCookedAt = ($pool_state === 'reheated')
                ? ($earliestBatch['reheated_at'] ?? $earliestBatch['cooked_at'])
                : $earliestBatch['cooked_at'];

            // Effective cutoff = min(sell_by, settings.closing_time)
            $sellByTimestamp = !empty($earliestSellBy) ? strtotime($earliestSellBy) : $todayClosingTimestamp;
            $effectiveCutoff = min($sellByTimestamp, $todayClosingTimestamp);

            if ($nowTimestamp > $effectiveCutoff) {
                continue; // Food safety expiration or closing cutoff passed
            }

            // 4. Time-Based Pricing Engine
            $basePrice = (float)$item['base_price'];
            $discountPercent = 0.0;

            if ($pool_state === 'reheated') {
                // Flat 50% discount for reheated
                $discountPercent = 50.0;
            } else {
                // Fresh: percentage of time left = (sell_by - NOW) / hold_hours
                $holdHours = (int)($item['hold_hours'] ?? ($earliestBatch['batch_hold_hours'] ?? 6));
                $holdSeconds = max(1, $holdHours * 3600);
                $timeLeftSeconds = max(0, $sellByTimestamp - $nowTimestamp);
                $timeRatio = $timeLeftSeconds / $holdSeconds;

                if ($timeRatio > 0.50) {
                    $discountPercent = 0.0;
                } elseif ($timeRatio > 0.25) {
                    $discountPercent = 25.0;
                } else {
                    $discountPercent = 40.0;
                }
            }

            $discountedPrice = round($basePrice * (1.0 - ($discountPercent / 100.0)), 2);

            $cartId = $item['id'] . '_' . $pool_state;

            $result[] = [
                'id' => $cartId,
                'cart_id' => $cartId,
                'original_dish_id' => (int)$item['id'],
                'menu_item_id' => (int)$item['id'],
                'batch_type' => $pool_state,
                'name' => $item['name'],
                'description' => $item['description'],
                'image_url' => $item['image_url'],
                'photo_path' => $item['photo_path'] ?? $item['image_url'],
                'category' => $item['category'] ?? 'Uncategorized',
                'dietary' => $dietary,
                'price' => $basePrice,
                'base_price' => $basePrice,
                'discounted_price' => $discountedPrice,
                'current_price' => $discountedPrice,
                'discount_percent' => $discountPercent,
                'available_qty' => $availableQty,
                'stock' => $availableQty,
                'online_pool_qty' => $availableQty,
                'cooked_at' => $earliestCookedAt,
                'sell_by' => $earliestSellBy,
                'ingredients' => $ingredients,
                'allergens' => $critical_allergens,
                'critical_allergens' => $critical_allergens,
                'sensitivities' => $digestive_sensitivities,
                'digestive_sensitivities' => $digestive_sensitivities,
                'macros' => $macros,
                'has_critical' => count($critical_allergens) > 0 ? 1 : 0,
                'conflict_tier' => $conflictTier
            ];
        }
    }

    // 3. Sort items: critical conflicts/allergens to the bottom
    usort($result, function ($a, $b) {
        $tierOrder = ['safe' => 0, 'boundary' => 1, 'critical' => 2];
        $tierA = $tierOrder[$a['conflict_tier']] ?? 0;
        $tierB = $tierOrder[$b['conflict_tier']] ?? 0;

        if ($tierA !== $tierB) {
            return $tierA <=> $tierB;
        }

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

function handleCreateReservation($pdo, $input = null)
{
    $user_id = requireCustomer($pdo);
    if ($input === null) {
        $input = json_decode(file_get_contents('php://input'), true);
    }

    // Expects: { "items": [ {"menu_item_id": 1, "quantity": 1, "portion_preference": "lean_meat", "ack": true} ] }
    if (!isset($input['items']) || !is_array($input['items'])) {
        jsonResponse(['error' => 'Missing items'], 400);
    }

    $settings = getCustomerSettings($pdo);

    // Closing behavior: reject reservations after closing_time and expire unclaimed orders
    if (PricingEngine::isPastClosing($settings)) {
        expireUnclaimedOrdersAfterClosing($pdo);
        $closingTime = PricingEngine::getEffectiveClosingTime($settings);
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
        $itemIds = array_values(array_filter(array_map(function ($i) {
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

    // Pre-flight Checkout Defense: explicitly verify that batches remaining_stock >= quantity for every item & batch_type in the cart BEFORE creating the order
    $cartTotals = [];
    foreach ($input['items'] as $item) {
        $rawId = $item['menu_item_id'] ?? ($item['original_dish_id'] ?? 0);
        $itemId = (int)explode('_', (string)$rawId)[0];
        $bType = (!empty($item['batch_type']) && in_array($item['batch_type'], ['fresh', 'reheated'], true))
            ? $item['batch_type']
            : ((is_string($rawId) && strpos($rawId, 'reheated') !== false) ? 'reheated' : 'fresh');

        $qty = (int)($item['quantity'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            jsonResponse(['error' => 'Invalid item or quantity in reservation request.'], 400);
        }
        $key = $itemId . '_' . $bType;
        if (!isset($cartTotals[$key])) {
            $cartTotals[$key] = [
                'menu_item_id' => $itemId,
                'batch_type' => $bType,
                'quantity' => 0
            ];
        }
        $cartTotals[$key]['quantity'] += $qty;
    }

    foreach ($cartTotals as $key => $cartReq) {
        $itemId = $cartReq['menu_item_id'];
        $bType = $cartReq['batch_type'];
        $requiredQty = $cartReq['quantity'];

        $stmtStock = $pdo->prepare("
            SELECT b.id, b.remaining_stock, b.held_stock, b.sell_by, m.name
            FROM batches b
            JOIN menu_items m ON b.menu_item_id = m.id
            WHERE b.menu_item_id = ? AND b.state = ? AND b.remaining_stock > 0 AND (b.remaining_stock - b.held_stock) > 0
            ORDER BY b.sell_by ASC
        ");
        $stmtStock->execute([$itemId, $bType]);
        $stockRows = $stmtStock->fetchAll(PDO::FETCH_ASSOC);

        $dishName = !empty($stockRows) ? $stockRows[0]['name'] : "Dish #$itemId";
        $availableQty = 0;
        foreach ($stockRows as $sr) {
            $availableQty += max(0, (int)$sr['remaining_stock'] - (int)($sr['held_stock'] ?? 0));
        }

        if ($availableQty < $requiredQty) {
            jsonResponse(['error' => "Out of stock: Only {$availableQty} portions of {$dishName} ({$bType}) remaining."], 400);
        }

        // Food Safety Check: Sell-By timestamp of earliest batch
        if (!empty($stockRows[0]['sell_by'])) {
            date_default_timezone_set('Asia/Manila');
            if (time() >= strtotime($stockRows[0]['sell_by'])) {
                jsonResponse(['error' => "Food safety violation: Item '{$dishName}' ({$bType}) has passed its safe sell-by expiration time ({$stockRows[0]['sell_by']}) and cannot be ordered."], 400);
            }
        }
    }

    date_default_timezone_set('Asia/Manila');
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $closingTime = PricingEngine::getEffectiveClosingTime($settings);
    $openingTime = $settings['opening_time'] ?? '06:00';

    $fulfillment_type = !empty($input['fulfillment_type']) && in_array($input['fulfillment_type'], ['take_out', 'dine_in'])
        ? $input['fulfillment_type']
        : 'dine_in';

    $payment_method = !empty($input['payment_method']) && in_array(strtolower($input['payment_method']), ['paymongo', 'cash'], true)
        ? strtolower($input['payment_method'])
        : 'cash';

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
                allergen_override, allergen_conflicts, payment_status, payment_method
            ) VALUES (?, ?, 'reserved', ?, ?, ?, ?, 'none', ?, ?, 'unpaid', ?)
        ");
        $insertOrder->execute([
            $user_id,
            $store_id,
            $expiryTimeFormatted,
            $claimToken,
            $fulfillment_type,
            $pickup_time,
            $isAllergenOverride,
            $allergenConflictsJson,
            $payment_method
        ]);
        $order_id = $pdo->lastInsertId();

        $paymongoLineItems = [];

        $insertItem = $pdo->prepare("INSERT INTO order_items (order_id, menu_item_id, batch_id, quantity, unit_price_at_order, portion_preference, best_effort_disclaimer_ack) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $checkClaims = $pdo->prepare("SELECT quantity_claimed FROM daily_discount_claims WHERE user_id = ? AND menu_item_id = ? AND claim_date = CURDATE()");
        $upsertClaims = $pdo->prepare("INSERT INTO daily_discount_claims (user_id, menu_item_id, claim_date, quantity_claimed) VALUES (?, ?, CURDATE(), ?) ON DUPLICATE KEY UPDATE quantity_claimed = quantity_claimed + ?");

        foreach ($input['items'] as $item) {
            $rawId = $item['menu_item_id'] ?? ($item['original_dish_id'] ?? 0);
            $item_id = (int)explode('_', (string)$rawId)[0];
            $batch_type = (!empty($item['batch_type']) && in_array($item['batch_type'], ['fresh', 'reheated'], true))
                ? $item['batch_type']
                : ((is_string($rawId) && strpos($rawId, 'reheated') !== false) ? 'reheated' : 'fresh');

            $qty = (int)$item['quantity'];

            // Query active batches FOR UPDATE (FIFO ordering by sell_by ASC)
            $stmt = $pdo->prepare("
                SELECT b.id, b.remaining_stock, b.held_stock, b.sell_by, b.hold_hours, m.name, m.base_price, m.price_floor, m.hold_hours AS dish_hold_hours
                FROM batches b
                JOIN menu_items m ON b.menu_item_id = m.id
                WHERE b.menu_item_id = ? AND b.state = ? AND b.remaining_stock > 0 AND (b.remaining_stock - b.held_stock) > 0
                ORDER BY b.sell_by ASC
                FOR UPDATE
            ");
            $stmt->execute([$item_id, $batch_type]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($batches)) {
                $dStmt = $pdo->prepare("SELECT name FROM menu_items WHERE id = ?");
                $dStmt->execute([$item_id]);
                $dishName = $dStmt->fetchColumn() ?: "Item #$item_id";
                throw new BusinessException("Out of stock: No {$batch_type} portions of {$dishName} remaining.", 400);
            }

            $dishName = $batches[0]['name'];
            $base_price = (float)$batches[0]['base_price'];
            $price_floor = (float)$batches[0]['price_floor'];
            $earliestSellBy = $batches[0]['sell_by'];

            $availableQty = 0;
            foreach ($batches as $b) {
                $availableQty += max(0, (int)$b['remaining_stock'] - (int)($b['held_stock'] ?? 0));
            }

            if ($availableQty < $qty) {
                throw new BusinessException("Out of stock: Only {$availableQty} portions of {$dishName} ({$batch_type}) remaining.", 400);
            }

            // Food Safety Enforcement: Check sell_by in Asia/Manila
            if (!empty($earliestSellBy)) {
                $sellByDateTime = new DateTime($earliestSellBy, $tz);
                if ($now >= $sellByDateTime) {
                    throw new BusinessException("Food safety violation: Item '$dishName' ($batch_type) has passed its safe sell-by expiration time ({$earliestSellBy}) and cannot be ordered.", 422);
                }
                // Pickup time must be <= sell_by of all requested items
                if ($fulfillment_type === 'take_out' && $pickupDateTime && $pickupDateTime > $sellByDateTime) {
                    throw new BusinessException("Pickup time ({$pickup_time}) exceeds safe sell-by time for '{$dishName}' ({$earliestSellBy}).", 422);
                }
            }

            // Calculate unit price using Time-Based Pricing Engine rules
            if ($batch_type === 'reheated') {
                $discountPercent = 50.0;
            } else {
                $holdHours = (int)($batches[0]['dish_hold_hours'] ?? ($batches[0]['hold_hours'] ?? 6));
                $holdSeconds = max(1, $holdHours * 3600);
                $timeLeftSeconds = max(0, strtotime($earliestSellBy) - time());
                $timeRatio = $timeLeftSeconds / $holdSeconds;

                if ($timeRatio > 0.50) {
                    $discountPercent = 0.0;
                } elseif ($timeRatio > 0.25) {
                    $discountPercent = 25.0;
                } else {
                    $discountPercent = 40.0;
                }
            }

            $current_price = round($base_price * (1.0 - ($discountPercent / 100.0)), 2);

            $is_discounted = ($current_price < $base_price);

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

            $total_amount += ($current_price * $qty);

            $paymongoLineItems[] = [
                'name' => $dishName . ($batch_type === 'reheated' ? ' (Reheated)' : ''),
                'quantity' => $qty,
                'amount' => (int)round($current_price * 100),
                'currency' => 'PHP'
            ];

            // FIFO Reservation across batches: increment held_stock instead of decrementing remaining_stock
            $remainingToHold = $qty;
            $stmtHoldBatch = $pdo->prepare("
                UPDATE batches
                SET held_stock = held_stock + ?
                WHERE id = ? AND (remaining_stock - held_stock) >= ?
            ");

            foreach ($batches as $b) {
                if ($remainingToHold <= 0) break;
                $availInBatch = max(0, (int)$b['remaining_stock'] - (int)($b['held_stock'] ?? 0));
                if ($availInBatch <= 0) continue;

                $holdFromThis = min($availInBatch, $remainingToHold);
                $stmtHoldBatch->execute([$holdFromThis, $b['id'], $holdFromThis]);
                if ($stmtHoldBatch->rowCount() > 0) {
                    $insertItem->execute([
                        $order_id,
                        $item_id,
                        $b['id'],
                        $holdFromThis,
                        $current_price,
                        $portionPref,
                        $item['ack'] ?? 0
                    ]);
                    $remainingToHold -= $holdFromThis;
                }
            }

            if ($remainingToHold > 0) {
                throw new BusinessException("Out of stock: Unable to reserve {$qty} portions from {$batch_type} batches of {$dishName}.", 400);
            }

            // Sync with inventory table
            $updateInventory = $pdo->prepare("
                UPDATE inventory 
                SET online_pool_qty = GREATEST(0, online_pool_qty - ?),
                    delisted_from_app = CASE WHEN online_pool_qty - ? <= critical_stock_threshold THEN 1 ELSE delisted_from_app END
                WHERE menu_item_id = ?
            ");
            $updateInventory->execute([$qty, $qty, $item_id]);
        }

        $checkoutSession = null;
        if ($payment_method === 'paymongo') {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? 'http://localhost:5173');
            $originParsed = parse_url($origin);
            $originUrl = ($originParsed && !empty($originParsed['scheme']) && !empty($originParsed['host']))
                ? $originParsed['scheme'] . '://' . $originParsed['host'] . (!empty($originParsed['port']) ? ':' . $originParsed['port'] : '')
                : 'http://localhost:5173';

            $successUrl = $input['success_url'] ?? "{$originUrl}/customer/orders?payment=success&order_id={$order_id}";
            $cancelUrl = $input['cancel_url'] ?? "{$originUrl}/customer/orders?payment=cancelled&order_id={$order_id}";
            $description = "OptiMeal Order #{$order_id}";

            $checkoutSession = PayMongoService::createCheckoutSession(
                $paymongoLineItems,
                $successUrl,
                $cancelUrl,
                $description
            );

            $sessionId = $checkoutSession['id'] ?? null;
            $pdo->prepare("UPDATE orders SET total_amount = ?, paymongo_session_id = ? WHERE id = ?")->execute([$total_amount, $sessionId, $order_id]);
        } else {
            $pdo->prepare("UPDATE orders SET total_amount = ? WHERE id = ?")->execute([$total_amount, $order_id]);
        }

        $overriddenAllergenIds = !empty($input['overridden_allergen_ids']) && is_array($input['overridden_allergen_ids'])
            ? $input['overridden_allergen_ids']
            : [];

        if (!empty($overriddenAllergenIds)) {
            $insOverride = $pdo->prepare("INSERT INTO allergen_overrides (user_id, order_id, allergen_id) VALUES (?, ?, ?)");
            foreach ($overriddenAllergenIds as $allergenId) {
                if ((int)$allergenId > 0) {
                    $insOverride->execute([$user_id, $order_id, (int)$allergenId]);
                }
            }
        }

        $pdo->commit();

        try {
            $vendorStmt = $pdo->prepare("SELECT vendor_user_id FROM stores WHERE id = ?");
            $vendorStmt->execute([$store_id]);
            $vendorUserId = (int)$vendorStmt->fetchColumn();

            if ($vendorUserId > 0) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, reference_id, is_read, created_at)
                    VALUES (?, 'new_order', ?, ?, 0, NOW())
                ");
                $notifStmt->execute([
                    $vendorUserId,
                    "New order #{$order_id} received.",
                    $order_id
                ]);
            }
        } catch (Exception $e) {
            error_log("Failed to dispatch new_order notification: " . $e->getMessage());
        }

        $responsePayload = [
            'message' => 'Reservation created',
            'order_id' => $order_id,
            'total_amount' => $total_amount,
            'claim_token' => $claimToken,
            'payment_method' => $payment_method,
            'payment_status' => 'unpaid'
        ];

        if ($payment_method === 'paymongo' && !empty($checkoutSession['checkout_url'])) {
            $responsePayload['checkout_url'] = $checkoutSession['checkout_url'];
            $responsePayload['paymongo_session_id'] = $checkoutSession['id'] ?? null;
        }

        jsonResponse($responsePayload);
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

function handlePayReservation($pdo, $order_id)
{
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

function handleGetCustomerOrders($pdo)
{
    $user_id = requireCustomer($pdo);

    $stmt = $pdo->prepare("SELECT id, store_id, status, reserved_at, ttl_expires_at, claim_token, total_amount, fulfillment_type, pickup_time, notification_status, allergen_override, allergen_conflicts FROM orders WHERE user_id = ? ORDER BY reserved_at DESC");
    $stmt->execute([$user_id]);
    jsonResponse($stmt->fetchAll());
}

function handleGetMyOrders($pdo)
{
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

function handleUpdateFulfillment($pdo)
{
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
            $closingTime = PricingEngine::getEffectiveClosingTime($settings);
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

function handleGetProfile($pdo)
{
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

function handleUpdateProfile($pdo)
{
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

function handleCancelReservation($pdo, $reservationId)
{
    $userId = requireCustomer($pdo);
    $reservationId = (int)$reservationId;
    if ($reservationId <= 0) {
        jsonResponse(['error' => 'Invalid reservation ID.'], 400);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$reservationId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new BusinessException("Reservation not found.", 404);
        }

        if ((int)$order['user_id'] !== (int)$userId) {
            throw new BusinessException("Unauthorized: You do not own this reservation.", 403);
        }

        // Must be in reserved, confirmed, paid, or pending status
        $cancellableStatuses = ['reserved', 'confirmed', 'pending', 'paid'];
        if (!in_array(strtolower($order['status']), $cancellableStatuses, true)) {
            throw new BusinessException("Cannot cancel order with status '{$order['status']}'. Only active reservations can be cancelled.", 400);
        }

        // Cutoff check
        date_default_timezone_set('Asia/Manila');
        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);

        if (!empty($order['ttl_expires_at'])) {
            $cutoff = new DateTime($order['ttl_expires_at'], $tz);
            if ($now >= $cutoff) {
                throw new BusinessException("Cancellation cutoff time has already passed.", 400);
            }
        }

        $updateStmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        $updateStmt->execute([$reservationId]);

        // Release stock on specific batch & inventory
        $itemsStmt = $pdo->prepare("SELECT menu_item_id, batch_id, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$reservationId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $releaseBatch = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE id = ?");
        $fallbackRelease = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE menu_item_id = ? AND held_stock > 0 ORDER BY sell_by ASC LIMIT 1");
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

        foreach ($orderItems as $item) {
            $qty = (int)$item['quantity'];
            $restoreInv->execute([$qty, $qty, $item['menu_item_id']]);
            $refundClaim->execute([$qty, $item['menu_item_id'], $reservationId]);

            if (!empty($item['batch_id'])) {
                $releaseBatch->execute([$qty, $item['batch_id']]);
            } else {
                $fallbackRelease->execute([$qty, $item['menu_item_id']]);
            }
        }

        $pdo->commit();
        jsonResponse(['success' => true, 'message' => 'Reservation cancelled successfully. Stock has been released.']);
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
        error_log("handleCancelReservation error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
}

function handleGetCustomerImpact($pdo)
{
    $userId = requireCustomer($pdo);

    $timeframe = strtolower(trim($_GET['timeframe'] ?? 'week'));
    if (!in_array($timeframe, ['week', 'month', 'all_time'], true)) {
        $timeframe = 'week';
    }

    $dateCondition = "";
    if ($timeframe === 'week') {
        $dateCondition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($timeframe === 'month') {
        $dateCondition = "AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    $sql = "
        SELECT 
            COALESCE(SUM(oi.quantity), 0) AS meals_rescued,
            COALESCE(SUM(GREATEST(0, (m.base_price - oi.unit_price_at_order) * oi.quantity)), 0.00) AS money_saved
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN menu_items m ON oi.menu_item_id = m.id
        WHERE o.user_id = ?
          AND o.status IN ('reserved', 'paid', 'claimed')
          $dateCondition
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $mealsRescued = (int)($row['meals_rescued'] ?? 0);
    $moneySaved = (float)($row['money_saved'] ?? 0.00);
    $co2Prevented = round($mealsRescued * 0.6, 1);

    jsonResponse([
        'timeframe' => $timeframe,
        'mealsRescued' => $mealsRescued,
        'moneySaved' => (float)number_format($moneySaved, 2, '.', ''),
        'co2Prevented' => (float)$co2Prevented,
        'meals_rescued' => $mealsRescued,
        'money_saved' => (float)number_format($moneySaved, 2, '.', ''),
        'co2_prevented' => (float)$co2Prevented
    ]);
}


