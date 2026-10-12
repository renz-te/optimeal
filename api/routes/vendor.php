<?php
function requireVendor($pdo) {
    $session = requireRole($pdo, ['vendor', 'admin']);
    
    // Resolve vendor's store
    $stmt = $pdo->prepare("SELECT id FROM stores WHERE vendor_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $store = $stmt->fetch();
    if (!$store) {
        $stmtActive = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
        $storeId = $stmtActive->fetchColumn();
        if ($storeId) {
            return (int)$storeId;
        }
        jsonResponse(['error' => 'Vendor does not have a store configured'], 400);
    }
    return (int)$store['id'];
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

function handleGetOrders($pdo) {
    $store_id = requireStaff($pdo);
    
    $stmt = $pdo->prepare("
        SELECT o.id, o.user_id, o.status, o.total_amount, o.claim_token, o.reserved_at,
               o.fulfillment_type, o.pickup_time, o.notification_status, o.handled_by, o.allergen_override, o.allergen_conflicts, u.name as customer_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.store_id = ? AND o.status IN ('reserved', 'paid', 'claimed')
        ORDER BY o.id ASC
    ");
    $stmt->execute([$store_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch order items
    $orderIds = array_column($orders, 'id');
    if (!empty($orderIds)) {
        $inQuery = implode(',', array_fill(0, count($orderIds), '?'));
        $itemStmt = $pdo->prepare("
            SELECT oi.order_id, oi.menu_item_id, oi.quantity, oi.portion_preference, m.name as item_name
            FROM order_items oi
            JOIN menu_items m ON oi.menu_item_id = m.id
            WHERE oi.order_id IN ($inQuery)
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
    }
    
    jsonResponse($orders);
}

function handleUpdateOrderStatus($pdo) {
    $store_id = requireStaff($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['order_id']) || empty($input['status'])) {
        jsonResponse(['error' => 'Missing order_id or status'], 400);
    }
    
    $order_id = $input['order_id'];
    $status = $input['status'];

    if (!in_array($status, ['claimed', 'completed', 'cancelled'])) {
        jsonResponse(['error' => 'Invalid status'], 400);
    }

    try {
        $pdo->beginTransaction();

        $checkStmt = $pdo->prepare("SELECT id, status, allergen_override, allergen_conflicts FROM orders WHERE id = ? AND store_id = ? FOR UPDATE");
        $checkStmt->execute([$order_id, $store_id]);
        $order = $checkStmt->fetch();

        if (!$order) {
            throw new Exception("Order not found or does not belong to your store.");
        }
        
        // Prevent changing status if already cancelled or completed
        if (in_array($order['status'], ['cancelled', 'completed'])) {
            throw new Exception("Cannot change status of a " . $order['status'] . " order.");
        }

        // Restrict "Mark Claimed" action if allergen_override is active until confirmed by staff
        if (in_array($status, ['claimed', 'completed']) && !empty($order['allergen_override'])) {
            $staffConfirmed = !empty($input['confirm_allergen_override']) || !empty($input['allergen_confirmed']);
            if (!$staffConfirmed) {
                $conflicts = !empty($order['allergen_conflicts']) ? $order['allergen_conflicts'] : 'undisclosed allergens';
                throw new Exception("Allergen Warning: This order has an active allergen override ($conflicts). Staff confirmation is required to mark it as claimed.");
            }
        }

        $staffUserId = $_SESSION['user_id'];
        $updateStmt = $pdo->prepare("UPDATE orders SET status = ?, handled_by = ? WHERE id = ?");
        $updateStmt->execute([$status, $staffUserId, $order_id]);

        if ($status === 'cancelled') {
            $itemStmt = $pdo->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$order_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStmt = $pdo->prepare("UPDATE inventory SET online_pool_qty = online_pool_qty + ?, delisted_from_app = CASE WHEN online_pool_qty + ? > critical_stock_threshold THEN 0 ELSE delisted_from_app END WHERE menu_item_id = ?");
            foreach ($items as $item) {
                $restoreStmt->execute([$item['quantity'], $item['quantity'], $item['menu_item_id']]);
            }
        }

        $pdo->commit();
        jsonResponse(['message' => 'Order status updated successfully']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 400);
    }
}

function handleNotifyReady($pdo) {
    $store_id = requireStaff($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['order_id'])) {
        jsonResponse(['error' => 'Missing order_id'], 400);
    }

    $order_id = (int)$input['order_id'];

    $checkStmt = $pdo->prepare("SELECT id, status, fulfillment_type, notification_status FROM orders WHERE id = ? AND store_id = ?");
    $checkStmt->execute([$order_id, $store_id]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        jsonResponse(['error' => 'Order not found or does not belong to your store.'], 404);
    }

    if ($order['fulfillment_type'] !== 'take_out') {
        jsonResponse(['error' => 'Ready notifications are only applicable to take-out orders.'], 400);
    }

    if (!in_array($order['status'], ['reserved', 'paid'], true)) {
        jsonResponse(['error' => 'Order must be in reserved or paid status to send ready notification.'], 400);
    }

    $updateStmt = $pdo->prepare("UPDATE orders SET notification_status = 'ready_sent' WHERE id = ? AND store_id = ?");
    $updateStmt->execute([$order_id, $store_id]);

    jsonResponse([
        'success' => true,
        'message' => "Marked ready - visible in student's order history",
        'order_id' => $order_id,
        'notification_status' => 'ready_sent'
    ]);
}

function handleEnrichDish($pdo) {
    $session = requireRole($pdo, ['vendor', 'admin']);
    
    // Kill Switch Enforcement
    $stmt = $pdo->query("SELECT ai_enabled FROM settings WHERE id = 1");
    $aiEnabled = (int)($stmt->fetchColumn() ?? 1);
    if ($aiEnabled === 0) {
        jsonResponse(['error' => 'AI Generation is currently disabled by the Administrator.'], 403);
    }

    // Parse incoming JSON payload
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate presence of name
    if (empty($input['name'])) {
        jsonResponse(['error' => 'Missing or empty dish name'], 400);
    }

    $dishName = trim($input['name']);
    $sanitizedDishName = strtolower($dishName);

    // Backend Cache Hit: Query ai_query_cache for a match
    $cacheStmt = $pdo->prepare("SELECT response_payload FROM ai_query_cache WHERE dish_name = ?");
    $cacheStmt->execute([$sanitizedDishName]);
    $cached = $cacheStmt->fetch();
    if ($cached && !empty($cached['response_payload'])) {
        $aiJson = json_decode($cached['response_payload'], true);
        if ($aiJson) {
            jsonResponse($aiJson, 200);
        }
    }

    // Backend Cache Miss: Proceed with LLM generation logic
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey || $apiKey === 'your_api_key_here') {
        jsonResponse(['error' => 'Server configuration error: Missing API Key'], 500);
    }

    $description = isset($input['description']) ? htmlspecialchars(trim($input['description'])) : '';
    $portion = isset($input['portion']) ? htmlspecialchars(trim($input['portion'])) : '';

    $systemInstruction = "You are a precise culinary and nutritional data assistant. You MUST respond with ONLY raw JSON. Do NOT wrap the JSON in markdown code blocks.

VALIDATION & ERROR HANDLING RULE:
If the dish name is gibberish, not food, incomprehensible, or not a recognizable culinary viand/dish (e.g., 'asdf', 'Dish 1', random characters, non-edible items), you MUST return a JSON object with an 'error' key describing why it failed, and omit 'ingredients' and 'macros'.
Example failure format:
{\"error\": \"The input 'asdf' does not appear to be a valid food item.\"}

SUCCESS FORMAT & TAXONOMY RULES:
For valid food items and dishes, return ONLY a JSON object with two keys:
1. 'ingredients': An array of strings listing all primary raw and cooked ingredients.
   - CRITICAL REQUIREMENT: Break down all composite ingredients into their constituent culinary components (e.g., instead of 'Mayonnaise', return 'Eggs', 'Oil', 'Vinegar'; instead of sauces or composite foods, list their constituent raw ingredients). This enables accurate allergen taxonomy mapping.
   - Whenever applicable, use exact names from this approved taxonomy: [Soy Sauce, Tofu, Shrimp Paste, Shrimp, Crab, Squid, Clams, Peanut Butter, Peanuts, Egg Noodles, Eggs, Milk, Cheese, Butter, Condensed Milk, Evaporated Milk, Flour, Bread, Pasta, Sesame Oil, Sesame Seeds, Fish Sauce, Bangus, Tilapia]. If an ingredient is not in this list, use its standard single-ingredient culinary name.
2. 'macros': An object with integer values for: 'calories', 'protein_g', 'carbs_g', 'fat_g', 'sodium_mg'.

Do NOT output any allergen keys, allergen tags, or markdown formatting. Output raw JSON only.";
    
    $userPrompt = "Dish Name: $dishName\nDescription: $description\nPortion: $portion\nReference Source: USDA National Nutrient Database\nEvaluate the dish and generate the required JSON.";

    $payload = [
        "system_instruction" => [
            "parts" => [
                ["text" => $systemInstruction]
            ]
        ],
        "contents" => [
            [
                "parts" => [
                    ["text" => $userPrompt]
                ]
            ]
        ],
        "generationConfig" => [
            "response_mime_type" => "application/json"
        ]
    ];

    $modelName = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.5-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 429 || $httpCode >= 500 || !$response) {
        jsonResponse(['error' => 'AI rate limit exceeded or unavailable', 'fallback' => true], 503);
    } elseif ($httpCode !== 200) {
        jsonResponse(['error' => 'AI Service Error', 'fallback' => true], 502);
    }

    $responseData = json_decode($response, true);
    
    // Extract the text from the Gemini response
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $aiText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
        // Strip markdown code fences if present
        if (strpos($aiText, '```') !== false) {
            $aiText = preg_replace('/^```(?:json)?\s*/i', '', $aiText);
            $aiText = preg_replace('/\s*```$/', '', $aiText);
            $aiText = trim($aiText);
        }

        $aiJson = json_decode($aiText, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($aiJson)) {
            // Check if the AI returned a gibberish/non-food error
            if (!empty($aiJson['error'])) {
                jsonResponse(['error' => $aiJson['error']], 400);
            }

            // Validate that ingredients is an array and macros is an object/array
            if (isset($aiJson['ingredients']) && is_array($aiJson['ingredients']) &&
                isset($aiJson['macros']) && is_array($aiJson['macros'])) {
                
                // Do not cache failed or errored generations. Only cache successful, valid JSON outputs
                $insertStmt = $pdo->prepare("INSERT IGNORE INTO ai_query_cache (dish_name, response_payload) VALUES (?, ?)");
                $insertStmt->execute([$sanitizedDishName, json_encode($aiJson)]);
                
                jsonResponse($aiJson, 200);
            }
        }
    }
    
    jsonResponse(['error' => 'Invalid AI Response Format'], 502);
}

/**
 * A2: Synchronizes menu_item_ingredients and menu_item_critical_allergens
 * from all ingredient sources (menu_items.ingredients_text + recipe_ingredients + any explicit input ingredients).
 * This establishes a single source of truth so saving recipes does not wipe dish-level allergens,
 * and saving dishes preserves recipe-level ingredients.
 */
function syncDishIngredientLinks($pdo, $menuItemId, $storeId, ?array $additionalIngredients = null) {
    // 1. Fetch dish-level ingredients from menu_items.ingredients_text
    $stmtText = $pdo->prepare("SELECT ingredients_text FROM menu_items WHERE id = ?");
    $stmtText->execute([$menuItemId]);
    $dishIngText = $stmtText->fetchColumn();
    $dishIngredients = [];
    if (!empty($dishIngText)) {
        $dishIngredients = array_filter(array_map('trim', explode(',', $dishIngText)));
    }

    // 2. Fetch recipe-level ingredients from recipe_ingredients
    $stmtRecipe = $pdo->prepare("
        SELECT ri.ingredient_name 
        FROM recipe_ingredients ri
        JOIN recipes r ON ri.recipe_id = r.id
        WHERE r.menu_item_id = ?
    ");
    $stmtRecipe->execute([$menuItemId]);
    $recipeIngredients = $stmtRecipe->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // 3. Merge all sources
    $allRaw = array_merge($dishIngredients, $recipeIngredients, $additionalIngredients ?: []);
    $uniqueNames = array_values(array_unique(array_filter(array_map('trim', $allRaw))));

    if (empty($uniqueNames)) {
        $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id = ?")->execute([$menuItemId]);
        $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id = ?")->execute([$menuItemId]);
        return ['mapped_ids' => [], 'allergens' => []];
    }

    $findStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $subStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_submissions (raw_name, status, store_id) VALUES (?, 'pending', ?)");

    $mappedIngredientIds = [];
    foreach ($uniqueNames as $name) {
        if ($name === '') continue;
        $findStmt->execute([$name]);
        $ingId = $findStmt->fetchColumn();
        if ($ingId) {
            $mappedIngredientIds[] = (int)$ingId;
        } else {
            $subStmt->execute([$name, $storeId]);
        }
    }
    $mappedIngredientIds = array_values(array_unique($mappedIngredientIds));

    // 4. Synchronize menu_item_ingredients
    if (!empty($mappedIngredientIds)) {
        $inClause = implode(',', array_fill(0, count($mappedIngredientIds), '?'));
        $delStmt = $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id = ? AND ingredient_id NOT IN ($inClause)");
        $delStmt->execute(array_merge([$menuItemId], $mappedIngredientIds));

        $insStmt = $pdo->prepare("INSERT IGNORE INTO menu_item_ingredients (menu_item_id, ingredient_id) VALUES (?, ?)");
        foreach ($mappedIngredientIds as $mid) {
            $insStmt->execute([$menuItemId, $mid]);
        }
    } else {
        $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id = ?")->execute([$menuItemId]);
    }

    // 5. Synchronize menu_item_critical_allergens
    $detectedAllergens = [];
    if (!empty($mappedIngredientIds)) {
        $inClause = implode(',', array_fill(0, count($mappedIngredientIds), '?'));
        $stmtAllergens = $pdo->prepare("
            SELECT DISTINCT ca.id, ca.name
            FROM ingredient_allergen_map iam
            JOIN critical_allergens ca ON iam.allergen_id = ca.id
            WHERE iam.ingredient_id IN ($inClause)
            ORDER BY ca.name ASC
        ");
        $stmtAllergens->execute($mappedIngredientIds);
        $allergenRows = $stmtAllergens->fetchAll(PDO::FETCH_ASSOC);

        $detectedAllergenIds = array_map(function($r) { return (int)$r['id']; }, $allergenRows);
        $detectedAllergens = array_map(function($r) { return $r['name']; }, $allergenRows);

        if (!empty($detectedAllergenIds)) {
            $inClauseAllergens = implode(',', array_fill(0, count($detectedAllergenIds), '?'));
            $delAllergen = $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id = ? AND allergen_id NOT IN ($inClauseAllergens)");
            $delAllergen->execute(array_merge([$menuItemId], $detectedAllergenIds));

            $insAllergen = $pdo->prepare("INSERT IGNORE INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES (?, ?)");
            foreach ($detectedAllergenIds as $aid) {
                $insAllergen->execute([$menuItemId, $aid]);
            }
        } else {
            $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id = ?")->execute([$menuItemId]);
        }
    } else {
        $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id = ?")->execute([$menuItemId]);
    }

    return [
        'mapped_ids' => $mappedIngredientIds,
        'allergens' => $detectedAllergens
    ];
}

function handleSaveDish($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['name']) || empty($input['base_price'])) {
        jsonResponse(['error' => 'Missing required fields'], 400);
    }
    
    $dishId = isset($input['id']) ? $input['id'] : null;
    
    $category = isset($input['category']) ? $input['category'] : 'Uncategorized';
    $dietary = isset($input['dietary']) && is_array($input['dietary']) ? json_encode($input['dietary']) : json_encode([]);
    
    $calories = isset($input['macros']['calories']) ? $input['macros']['calories'] : null;
    $protein_g = isset($input['macros']['protein_g']) ? $input['macros']['protein_g'] : null;
    $carbs_g = isset($input['macros']['carbs_g']) ? $input['macros']['carbs_g'] : null;
    $fat_g = isset($input['macros']['fat_g']) ? $input['macros']['fat_g'] : null;
    $sodium_mg = isset($input['macros']['sodium_mg']) ? $input['macros']['sodium_mg'] : null;
    $ai_confirmed = !empty($input['ai_confirmed']) ? 1 : 0;
    
    // Server-side confirmation validation
    if ($calories !== null && $calories !== '' && !$ai_confirmed) {
        jsonResponse(['error' => 'AI-estimated macros must be explicitly confirmed by the vendor'], 400);
    }

    $rawIngredients = [];
    if (!empty($input['ingredients']) && is_array($input['ingredients'])) {
        $rawIngredients = $input['ingredients'];
    } elseif (!empty($input['ingredientsString'])) {
        $rawIngredients = explode(',', $input['ingredientsString']);
    }
    $ingredients = array_values(array_unique(array_filter(array_map('trim', $rawIngredients))));
    $ingredientsText = !empty($ingredients) ? implode(', ', $ingredients) : null;
    
    try {
        $pdo->beginTransaction();
        
        if ($dishId) {
            $stmt = $pdo->prepare("
                UPDATE menu_items 
                SET name = ?, description = ?, category = ?, dietary = ?, base_price = ?, price_floor = ?, image_url = ?,
                    calories = ?, protein_g = ?, carbs_g = ?, fat_g = ?, sodium_mg = ?, ai_confirmed = ?, ingredients_text = ?
                WHERE id = ? AND store_id = ?
            ");
            $stmt->execute([
                htmlspecialchars(trim($input['name'])), 
                isset($input['description']) ? htmlspecialchars(trim($input['description'])) : null,
                $category,
                $dietary,
                $input['base_price'],
                isset($input['price_floor']) ? $input['price_floor'] : $input['base_price'],
                isset($input['image_url']) ? $input['image_url'] : null,
                $calories, $protein_g, $carbs_g, $fat_g, $sodium_mg, $ai_confirmed,
                $ingredientsText,
                $dishId, $store_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO menu_items (store_id, name, description, category, dietary, base_price, price_floor, image_url, calories, protein_g, carbs_g, fat_g, sodium_mg, ai_confirmed, ingredients_text, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $store_id,
                htmlspecialchars(trim($input['name'])),
                isset($input['description']) ? htmlspecialchars(trim($input['description'])) : null,
                $category,
                $dietary,
                $input['base_price'],
                isset($input['price_floor']) ? $input['price_floor'] : $input['base_price'],
                isset($input['image_url']) ? $input['image_url'] : null,
                $calories, $protein_g, $carbs_g, $fat_g, $sodium_mg, $ai_confirmed,
                $ingredientsText
            ]);
            $dishId = $pdo->lastInsertId();
            
            // Auto-insert inventory record
            $invStmt = $pdo->prepare("
                INSERT INTO inventory (menu_item_id, walkin_pool_qty, online_pool_qty, critical_stock_threshold, delisted_from_app)
                VALUES (?, 0, 0, 2, 0)
            ");
            $invStmt->execute([$dishId]);
            
            // Auto-insert default pricing rule
            $prStmt = $pdo->prepare("
                INSERT INTO pricing_rules (menu_item_id, rush_hour_end_time, decay_start_offset_minutes, decay_rate_percent, decay_interval_minutes, min_stock_to_trigger_decay)
                VALUES (?, '13:30:00', 30, 5.00, 15, 3)
            ");
            $prStmt->execute([$dishId]);
        }
    
        // A2: Single source of truth sync
        $syncResult = syncDishIngredientLinks($pdo, $dishId, $store_id, $ingredients);
        $mappedAllergenNames = $syncResult['allergens'];
        
        $pdo->commit();
        
        jsonResponse(['message' => 'Dish saved', 'id' => $dishId, 'mapped_allergens' => $mappedAllergenNames]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to save dish: ' . $e->getMessage()], 500);
    }
}

function handleLaunchMenu($pdo) {
    $session = requireRole($pdo, ['vendor', 'admin']);
    $vendorStoreId = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    $store_id = ($_SESSION['role'] === 'admin' && !empty($input['store_id'])) 
        ? (int)$input['store_id'] 
        : $vendorStoreId;

    if (!isset($input['items']) || !is_array($input['items'])) {
        jsonResponse(['error' => 'Missing or invalid items array'], 400);
    }

    date_default_timezone_set('Asia/Manila');

    // Parse batch-level defaults
    $batchCookedAt = !empty($input['cooked_at']) 
        ? date('Y-m-d H:i:s', strtotime($input['cooked_at'])) 
        : date('Y-m-d H:i:s');
        
    $batchSellBy = !empty($input['sell_by']) 
        ? date('Y-m-d H:i:s', strtotime($input['sell_by'])) 
        : date('Y-m-d H:i:s', strtotime('+4 hours', strtotime($batchCookedAt)));

    try {
        $pdo->beginTransaction();

        // 1. Reset all dishes for this store: clear online_pool_qty and delist from app (clears un-launched dishes/yesterday's leftovers)
        $stmtResetInventory = $pdo->prepare("
            UPDATE inventory i
            JOIN menu_items m ON i.menu_item_id = m.id
            SET i.online_pool_qty = 0, i.delisted_from_app = 1
            WHERE m.store_id = ?
        ");
        $stmtResetInventory->execute([$store_id]);

        // 2. Reset all dishes for this store to inactive
        $stmtReset = $pdo->prepare("UPDATE menu_items SET is_active = 0 WHERE store_id = ?");
        $stmtReset->execute([$store_id]);

        $stmtCheckOwner = $pdo->prepare("SELECT 1 FROM menu_items WHERE id = ? AND store_id = ?");
        $stmtActivate = $pdo->prepare("UPDATE menu_items SET is_active = 1 WHERE id = ? AND store_id = ?");
        $stmtUpdateStock = $pdo->prepare("
            INSERT INTO inventory (menu_item_id, online_pool_qty, walkin_pool_qty, critical_stock_threshold, delisted_from_app, cooked_at, sell_by)
            VALUES (?, ?, 0, 2, 0, ?, ?)
            ON DUPLICATE KEY UPDATE
                online_pool_qty = VALUES(online_pool_qty),
                delisted_from_app = 0,
                cooked_at = VALUES(cooked_at),
                sell_by = VALUES(sell_by)
        ");

        foreach ($input['items'] as $item) {
            if (!isset($item['menu_item_id'], $item['stock'])) {
                continue;
            }
            
            $itemId = (int)$item['menu_item_id'];
            $stock = max(0, (int)$item['stock']);

            $stmtCheckOwner->execute([$itemId, $store_id]);
            if (!$stmtCheckOwner->fetchColumn()) {
                continue; // Dish does not belong to this store
            }

            $stmtActivate->execute([$itemId, $store_id]);

            $itemCookedAt = !empty($item['cooked_at']) 
                ? date('Y-m-d H:i:s', strtotime($item['cooked_at'])) 
                : $batchCookedAt;
                
            $itemSellBy = !empty($item['sell_by']) 
                ? date('Y-m-d H:i:s', strtotime($item['sell_by'])) 
                : $batchSellBy;

            $stmtUpdateStock->execute([$itemId, $stock, $itemCookedAt, $itemSellBy]);
        }

        $pdo->commit();
        jsonResponse(['message' => 'Menu launched successfully']);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to launch menu: ' . $e->getMessage()], 500);
    }
}

function handleGetVendorMenu($pdo) {
    $store_id = requireVendor($pdo);

    $stmt = $pdo->prepare("
        SELECT m.id, m.store_id, m.name, m.description, m.category, m.dietary, 
               m.base_price, m.price_floor, m.image_url, m.calories, m.protein_g, 
               m.carbs_g, m.fat_g, m.sodium_mg, m.ai_confirmed, m.is_active,
               i.walkin_pool_qty, i.online_pool_qty, i.critical_stock_threshold, i.delisted_from_app,
               i.cooked_at, i.sell_by,
               pr.rush_hour_end_time, pr.decay_start_offset_minutes, pr.decay_rate_percent, 
               pr.decay_interval_minutes, pr.min_stock_to_trigger_decay
        FROM menu_items m
        LEFT JOIN inventory i ON m.id = i.menu_item_id
        LEFT JOIN pricing_rules pr ON m.id = pr.menu_item_id
        WHERE m.store_id = ?
        ORDER BY m.id DESC
    ");
    $stmt->execute([$store_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($items as $item) {
        $ingStmt = $pdo->prepare("
            SELECT ing.name 
            FROM menu_item_ingredients mii 
            JOIN ingredients ing ON mii.ingredient_id = ing.id 
            WHERE mii.menu_item_id = ?
        ");
        $ingStmt->execute([$item['id']]);
        $ingredients = $ingStmt->fetchAll(PDO::FETCH_COLUMN);

        $astmt = $pdo->prepare("
            SELECT ca.name 
            FROM menu_item_critical_allergens mca
            JOIN critical_allergens ca ON mca.allergen_id = ca.id
            WHERE mca.menu_item_id = ?
        ");
        $astmt->execute([$item['id']]);
        $allergens = $astmt->fetchAll(PDO::FETCH_COLUMN);

        $sstmt = $pdo->prepare("
            SELECT ds.name 
            FROM menu_item_digestive_sensitivities mids
            JOIN digestive_sensitivities ds ON mids.sensitivity_id = ds.id
            WHERE mids.menu_item_id = ?
        ");
        $sstmt->execute([$item['id']]);
        $sensitivities = $sstmt->fetchAll(PDO::FETCH_COLUMN);

        $dietary = [];
        if (!empty($item['dietary'])) {
            $decoded = json_decode($item['dietary'], true);
            if (is_array($decoded)) {
                $dietary = $decoded;
            }
        }

        $macros = null;
        if ($item['calories'] !== null) {
            $macros = [
                'calories' => (int)$item['calories'],
                'protein_g' => (int)$item['protein_g'],
                'carbs_g' => (int)$item['carbs_g'],
                'fat_g' => (int)$item['fat_g'],
                'sodium_mg' => (int)$item['sodium_mg'],
            ];
        }

        $result[] = [
            'id' => (int)$item['id'],
            'name' => $item['name'],
            'description' => $item['description'] ?? '',
            'category' => $item['category'] ?? 'Uncategorized',
            'dietary' => $dietary,
            'base_price' => (float)$item['base_price'],
            'current_price' => (float)$item['base_price'],
            'stock' => isset($item['online_pool_qty']) ? (int)$item['online_pool_qty'] : 10,
            'walkin_pool_qty' => isset($item['walkin_pool_qty']) ? (int)$item['walkin_pool_qty'] : 0,
            'online_pool_qty' => isset($item['online_pool_qty']) ? (int)$item['online_pool_qty'] : 0,
            'delisted_from_app' => isset($item['delisted_from_app']) ? (int)$item['delisted_from_app'] : 0,
            'is_active' => (int)$item['is_active'],
            'ai_confirmed' => (bool)$item['ai_confirmed'],
            'ingredients' => $ingredients,
            'allergens' => $allergens,
            'sensitivities' => $sensitivities,
            'macros' => $macros
        ];
    }

    jsonResponse($result);
}

function handleGetDecayConfig($pdo) {
    $stmt = $pdo->query("SELECT closing_time, tier1_minutes_before_close, tier1_discount_percent, tier2_minutes_before_close, tier2_discount_percent, emergency_clearance_active, emergency_activated_at FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $pdo->exec("INSERT IGNORE INTO settings (id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, ai_enabled, tier1_minutes_before_close, tier1_discount_percent, tier2_minutes_before_close, tier2_discount_percent, emergency_clearance_active) VALUES (1, 30, '07:00', '20:00', 5, 10.00, 1, 120, 25.00, 60, 40.00, 0)");
        $stmt = $pdo->query("SELECT closing_time, tier1_minutes_before_close, tier1_discount_percent, tier2_minutes_before_close, tier2_discount_percent, emergency_clearance_active, emergency_activated_at FROM settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    jsonResponse([
        'closing_time' => $settings['closing_time'] ?? '20:00',
        'tier1_minutes_before_close' => (int)($settings['tier1_minutes_before_close'] ?? 120),
        'tier1_discount_percent' => (float)($settings['tier1_discount_percent'] ?? 25.00),
        'tier2_minutes_before_close' => (int)($settings['tier2_minutes_before_close'] ?? 60),
        'tier2_discount_percent' => (float)($settings['tier2_discount_percent'] ?? 40.00),
        'emergency_clearance_active' => (bool)($settings['emergency_clearance_active'] ?? 0),
        'emergency_activated_at' => $settings['emergency_activated_at'] ?? null
    ]);
}

function handleUpdateDecayConfig($pdo) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $closingTime = isset($input['closing_time']) ? trim($input['closing_time']) : ($current['closing_time'] ?? '20:00');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $closingTime)) {
        jsonResponse(['error' => 'Invalid closing_time format. Expected HH:MM.'], 400);
    }

    $tier1Minutes = isset($input['tier1_minutes_before_close']) ? max(0, (int)$input['tier1_minutes_before_close']) : (int)($current['tier1_minutes_before_close'] ?? 120);
    $tier2Minutes = isset($input['tier2_minutes_before_close']) ? max(0, (int)$input['tier2_minutes_before_close']) : (int)($current['tier2_minutes_before_close'] ?? 60);

    if ($tier1Minutes <= $tier2Minutes) {
        jsonResponse(['error' => 'Tier 1 must occur earlier than Tier 2 (higher minutes before close).'], 400);
    }

    $tier1Discount = isset($input['tier1_discount_percent']) ? (float)$input['tier1_discount_percent'] : (float)($current['tier1_discount_percent'] ?? 25.00);
    $tier2Discount = isset($input['tier2_discount_percent']) ? (float)$input['tier2_discount_percent'] : (float)($current['tier2_discount_percent'] ?? 40.00);

    if ($tier1Discount < 0 || $tier1Discount > 100 || $tier2Discount < 0 || $tier2Discount > 100) {
        jsonResponse(['error' => 'Discount percentages must be between 0% and 100%.'], 400);
    }

    $stmtUpdate = $pdo->prepare("
        UPDATE settings 
        SET closing_time = ?,
            tier1_minutes_before_close = ?,
            tier1_discount_percent = ?,
            tier2_minutes_before_close = ?,
            tier2_discount_percent = ?
        WHERE id = 1
    ");
    $stmtUpdate->execute([$closingTime, $tier1Minutes, $tier1Discount, $tier2Minutes, $tier2Discount]);

    jsonResponse([
        'message' => 'Decay rules updated successfully',
        'config' => [
            'closing_time' => $closingTime,
            'tier1_minutes_before_close' => $tier1Minutes,
            'tier1_discount_percent' => $tier1Discount,
            'tier2_minutes_before_close' => $tier2Minutes,
            'tier2_discount_percent' => $tier2Discount,
            'emergency_clearance_active' => (bool)($current['emergency_clearance_active'] ?? 0),
            'emergency_activated_at' => $current['emergency_activated_at'] ?? null
        ]
    ]);
}

function handleTriggerEmergencyDecay($pdo) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    // If active flag is provided, use it; otherwise default to activating (1)
    $active = isset($input['active']) ? ($input['active'] ? 1 : 0) : 1;

    $stmt = $pdo->prepare("
        UPDATE settings 
        SET emergency_clearance_active = ?,
            emergency_activated_at = CASE WHEN ? = 1 THEN NOW() ELSE NULL END
        WHERE id = 1
    ");
    $stmt->execute([$active, $active]);

    jsonResponse([
        'message' => $active ? 'Emergency 50% clearance activated!' : 'Emergency clearance deactivated.',
        'emergency_clearance_active' => (bool)$active
    ]);
}

function handleGetDailyAudit($pdo) {
    $store_id = requireStaff($pdo);
    date_default_timezone_set('Asia/Manila');

    // 0. Operating hours from settings
    $stmtSettings = $pdo->query("SELECT opening_time, closing_time FROM settings WHERE id = 1");
    $settingsRow = $stmtSettings->fetch(PDO::FETCH_ASSOC) ?: [];
    $openingTime = !empty($settingsRow['opening_time']) ? trim($settingsRow['opening_time']) : '07:00';
    $closingTime = !empty($settingsRow['closing_time']) ? trim($settingsRow['closing_time']) : '20:00';

    $nowTime = date('H:i');
    $openTimeFormat = date('H:i', strtotime($openingTime));
    $closeTimeFormat = date('H:i', strtotime($closingTime));

    if ($closeTimeFormat >= $openTimeFormat) {
        $isShopClosed = ($nowTime >= $closeTimeFormat || $nowTime < $openTimeFormat);
    } else {
        // Overnight operating hours
        $isShopClosed = ($nowTime >= $closeTimeFormat && $nowTime < $openTimeFormat);
    }

    // 0b. Store ingredient costs & recipe cost mapping
    $stmtCosts = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ?");
    $stmtCosts->execute([$store_id]);
    $allCosts = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);

    $costMapById = [];
    $costMapByName = [];
    foreach ($allCosts as $c) {
        if (!empty($c['ingredient_id'])) {
            $costMapById[(int)$c['ingredient_id']] = $c;
        }
        $costMapByName[strtolower(trim($c['ingredient_name']))] = $c;
    }

    // Fetch recipes and ingredients for this store's dishes
    $stmtRecipes = $pdo->prepare("
        SELECT r.id AS recipe_id, r.menu_item_id, r.yield_servings,
               ri.ingredient_id, ri.ingredient_name, ri.quantity, ri.unit
        FROM recipes r
        JOIN menu_items m ON r.menu_item_id = m.id
        LEFT JOIN recipe_ingredients ri ON r.id = ri.recipe_id
        WHERE m.store_id = ?
    ");
    $stmtRecipes->execute([$store_id]);
    $recipeRows = $stmtRecipes->fetchAll(PDO::FETCH_ASSOC);

    $dishCostPerServingMap = [];
    $recipeBatches = [];
    foreach ($recipeRows as $row) {
        $menuItemId = (int)$row['menu_item_id'];
        if (!isset($recipeBatches[$menuItemId])) {
            $recipeBatches[$menuItemId] = [
                'yield_servings' => max(1, (int)$row['yield_servings']),
                'total_batch_cost' => 0.0,
                'costed_count' => 0,
                'total_count' => 0
            ];
        }

        if (!empty($row['ingredient_name']) && (float)$row['quantity'] > 0) {
            $recipeBatches[$menuItemId]['total_count']++;
            $matchedCost = null;
            if (!empty($row['ingredient_id']) && isset($costMapById[(int)$row['ingredient_id']])) {
                $matchedCost = $costMapById[(int)$row['ingredient_id']];
            } else {
                $cleanName = strtolower(trim($row['ingredient_name']));
                if (isset($costMapByName[$cleanName])) {
                    $matchedCost = $costMapByName[$cleanName];
                }
            }

            if ($matchedCost) {
                $unitCost = (float)$matchedCost['cost'];
                $costRes = calculateIngredientItemCost((float)$row['quantity'], $row['unit'], $unitCost, $matchedCost['cost_unit']);
                if ($costRes['cost'] !== null) {
                    $recipeBatches[$menuItemId]['total_batch_cost'] += $costRes['cost'];
                    $recipeBatches[$menuItemId]['costed_count']++;
                }
            }
        }
    }

    $auditTotalIngredients = 0;
    $auditCostedIngredients = 0;
    foreach ($recipeBatches as $menuItemId => $data) {
        $dishCostPerServingMap[$menuItemId] = round($data['total_batch_cost'] / (float)$data['yield_servings'], 2);
        $auditTotalIngredients += $data['total_count'];
        $auditCostedIngredients += $data['costed_count'];
    }
    $isPartialAuditCost = ($auditTotalIngredients > 0 && $auditCostedIngredients < $auditTotalIngredients);
    $auditCostCoverage = $auditTotalIngredients > 0 
        ? "{$auditCostedIngredients} of {$auditTotalIngredients} ingredients costed" 
        : "No recipe ingredients";

    // 1. Aggregated daily order metrics for today in Asia/Manila
    $orderQuery = "
        SELECT 
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN status = 'claimed' THEN total_amount ELSE 0 END), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END), 0) AS claimed_orders,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired_orders,
            COALESCE(SUM(CASE WHEN status IN ('reserved', 'paid') THEN 1 ELSE 0 END), 0) AS active_orders
        FROM orders
        WHERE store_id = ? 
          AND DATE(COALESCE(reserved_at, NOW())) = CURDATE()
    ";
    $stmtOrders = $pdo->prepare($orderQuery);
    $stmtOrders->execute([$store_id]);
    $orderSummary = $stmtOrders->fetch(PDO::FETCH_ASSOC);

    // 1b. Compute COGS for claimed orders today
    $cogsQuery = "
        SELECT oi.menu_item_id, SUM(oi.quantity) AS qty_sold
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.store_id = ?
          AND o.status = 'claimed'
          AND DATE(COALESCE(o.reserved_at, NOW())) = CURDATE()
        GROUP BY oi.menu_item_id
    ";
    $stmtCogs = $pdo->prepare($cogsQuery);
    $stmtCogs->execute([$store_id]);
    $soldItems = $stmtCogs->fetchAll(PDO::FETCH_ASSOC);

    $totalCogs = 0.0;
    foreach ($soldItems as $sold) {
        $menuItemId = (int)$sold['menu_item_id'];
        $qtySold = (int)$sold['qty_sold'];
        $costPerServing = $dishCostPerServingMap[$menuItemId] ?? 0.0;
        $totalCogs += $costPerServing * $qtySold;
    }

    $totalRevenue = (float)$orderSummary['total_revenue'];
    $totalCogs = round($totalCogs, 2);
    $grossProfit = round($totalRevenue - $totalCogs, 2);
    $grossMarginPct = ($totalRevenue > 0) ? round(($grossProfit / $totalRevenue) * 100, 1) : 0.0;

    // 2. Unsold live inventory / surplus for active dishes (including Walk-in Pool)
    $inventoryQuery = "
        SELECT 
            m.id, 
            m.name, 
            m.category, 
            m.base_price, 
            (i.online_pool_qty + i.walkin_pool_qty) AS remaining_stock,
            i.online_pool_qty,
            i.walkin_pool_qty,
            i.cooked_at,
            i.sell_by,
            ROUND(m.base_price * (i.online_pool_qty + i.walkin_pool_qty), 2) AS unsold_stock_value
        FROM menu_items m
        JOIN inventory i ON m.id = i.menu_item_id
        WHERE m.store_id = ? AND m.is_active = 1
        ORDER BY m.name ASC
    ";
    $stmtInventory = $pdo->prepare($inventoryQuery);
    $stmtInventory->execute([$store_id]);
    $rawUnsoldItems = $stmtInventory->fetchAll(PDO::FETCH_ASSOC);

    $totalUnsoldQty = 0;
    $totalUnsoldStockValue = 0.0;
    $totalOnSaleValue = 0.0;
    $totalWrittenOffValue = 0.0;
    $totalWasteActualCost = 0.0;
    $totalOnSaleActualCost = 0.0;
    $totalWrittenOffActualCost = 0.0;
    $expiredDishesCount = 0;
    $unsoldItems = [];

    $nowTimestamp = time();
    foreach ($rawUnsoldItems as $item) {
        $remainingStock = (int)$item['remaining_stock'];
        $unsoldValue = (float)$item['unsold_stock_value'];
        $costPerServing = $dishCostPerServingMap[(int)$item['id']] ?? 0.0;
        $wasteActualCost = round($costPerServing * $remainingStock, 2);
        $isExpired = (!empty($item['sell_by']) && strtotime($item['sell_by']) <= $nowTimestamp);

        if ($isExpired) {
            $expiredDishesCount++;
        }

        // Status: "On sale" vs "Expired / closed"
        if ($isExpired || $isShopClosed) {
            $status = 'Expired / closed';
            $totalWrittenOffValue += $unsoldValue;
            $totalWrittenOffActualCost += $wasteActualCost;
        } else {
            $status = 'On sale';
            $totalOnSaleValue += $unsoldValue;
            $totalOnSaleActualCost += $wasteActualCost;
        }

        $totalUnsoldQty += $remainingStock;
        $totalUnsoldStockValue += $unsoldValue;
        $totalWasteActualCost += $wasteActualCost;

        $item['cost_per_serving'] = $costPerServing;
        $item['waste_actual_cost'] = $wasteActualCost;
        $item['status'] = $status;
        $item['is_expired'] = $isExpired ? 1 : 0;
        $item['is_closed'] = $isShopClosed ? 1 : 0;
        $unsoldItems[] = $item;
    }

    // 3. Itemized list of today's orders
    $ledgerQuery = "
        SELECT 
            o.id, 
            o.status, 
            o.total_amount, 
            o.claim_token, 
            o.fulfillment_type, 
            o.reserved_at, 
            u.name AS customer_name
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.store_id = ? 
          AND DATE(COALESCE(o.reserved_at, NOW())) = CURDATE()
        ORDER BY o.reserved_at DESC
    ";
    $stmtLedger = $pdo->prepare($ledgerQuery);
    $stmtLedger->execute([$store_id]);
    $recentOrders = $stmtLedger->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'date' => date('Y-m-d'),
        'summary' => [
            'total_revenue' => $totalRevenue,
            'total_cogs' => $totalCogs,
            'gross_profit' => $grossProfit,
            'gross_margin_pct' => $grossMarginPct,
            'cost_coverage' => $auditCostCoverage,
            'is_partial_cost' => $isPartialAuditCost,
            'total_orders' => (int)$orderSummary['total_orders'],
            'claimed_orders' => (int)$orderSummary['claimed_orders'],
            'cancelled_orders' => (int)$orderSummary['cancelled_orders'],
            'expired_orders' => (int)$orderSummary['expired_orders'],
            'active_orders' => (int)$orderSummary['active_orders'],
            'total_unsold_units' => $totalUnsoldQty,
            'unsold_stock_value' => round($totalUnsoldStockValue, 2),
            'total_on_sale_value' => round($totalOnSaleValue, 2),
            'total_written_off_value' => round($totalWrittenOffValue, 2),
            'waste_actual_cost' => round($totalWasteActualCost, 2),
            'on_sale_actual_cost' => round($totalOnSaleActualCost, 2),
            'written_off_actual_cost' => round($totalWrittenOffActualCost, 2),
            'expired_dishes_count' => $expiredDishesCount,
            'opening_time' => $openingTime,
            'closing_time' => $closingTime,
            'is_shop_closed' => (bool)$isShopClosed
        ],
        'unsold_inventory' => $unsoldItems,
        'orders' => $recentOrders
    ]);
}

function calculateIngredientItemCost($recipeQty, $recipeUnit, $cost, $costUnit) {
    if ($cost <= 0) {
        return ['cost' => null, 'reason' => 'No cost recorded'];
    }
    if ($recipeQty <= 0) {
        return ['cost' => 0.0, 'reason' => 'Zero recipe quantity'];
    }

    $rUnit = strtolower(trim($recipeUnit));
    $cUnit = strtolower(trim($costUnit));

    // Direct unit match
    if ($rUnit === $cUnit) {
        return ['cost' => (float)($recipeQty * $cost), 'reason' => 'Direct unit match'];
    }

    // Mass conversions (g <-> kg)
    if (in_array($rUnit, ['g', 'gram', 'grams'], true) && in_array($cUnit, ['kg', 'kilo', 'kilogram', 'kilograms'], true)) {
        return ['cost' => (float)(($recipeQty / 1000.0) * $cost), 'reason' => 'Mass conversion (g to kg)'];
    }
    if (in_array($rUnit, ['kg', 'kilo', 'kilogram', 'kilograms'], true) && in_array($cUnit, ['g', 'gram', 'grams'], true)) {
        return ['cost' => (float)(($recipeQty * 1000.0) * $cost), 'reason' => 'Mass conversion (kg to g)'];
    }

    // Volume conversions (ml <-> l)
    if (in_array($rUnit, ['ml', 'milliliter', 'milliliters'], true) && in_array($cUnit, ['l', 'liter', 'liters'], true)) {
        return ['cost' => (float)(($recipeQty / 1000.0) * $cost), 'reason' => 'Volume conversion (ml to l)'];
    }
    if (in_array($rUnit, ['l', 'liter', 'liters'], true) && in_array($cUnit, ['ml', 'milliliter', 'milliliters'], true)) {
        return ['cost' => (float)(($recipeQty * 1000.0) * $cost), 'reason' => 'Volume conversion (l to ml)'];
    }

    // Count conversions (pc, pcs, piece, pieces)
    if (in_array($rUnit, ['pc', 'pcs', 'piece', 'pieces'], true) && in_array($cUnit, ['pc', 'pcs', 'piece', 'pieces'], true)) {
        return ['cost' => (float)($recipeQty * $cost), 'reason' => 'Direct count match'];
    }

    // Incompatible units (e.g. recipe is 'tsp' or 'cup' while cost is 'kg')
    return ['cost' => null, 'reason' => "Incompatible units ({$recipeUnit} vs {$costUnit})"];
}

function handleGetIngredientCosts($pdo) {
    $store_id = requireVendor($pdo);
    $stmt = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ? ORDER BY ingredient_name ASC");
    $stmt->execute([$store_id]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleSaveIngredientCosts($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    $items = [];
    if (isset($input['items']) && is_array($input['items'])) {
        $items = $input['items'];
    } elseif (isset($input['ingredient_name']) || isset($input['name'])) {
        $items = [$input];
    }

    if (empty($items)) {
        jsonResponse(['error' => 'No ingredient costs provided'], 400);
    }

    $findTaxStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
    $upsertStmt = $pdo->prepare("
        INSERT INTO vendor_ingredient_costs (store_id, ingredient_id, ingredient_name, cost, cost_unit)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ingredient_id = VALUES(ingredient_id),
            cost = VALUES(cost),
            cost_unit = VALUES(cost_unit),
            updated_at = CURRENT_TIMESTAMP
    ");

    $saved = [];
    foreach ($items as $item) {
        $name = trim($item['ingredient_name'] ?? $item['name'] ?? '');
        if ($name === '') continue;
        $cost = max(0, (float)($item['cost'] ?? 0));
        $unit = trim($item['cost_unit'] ?? $item['unit'] ?? 'kg');

        $findTaxStmt->execute([$name]);
        $ingId = $findTaxStmt->fetchColumn() ?: null;

        $upsertStmt->execute([$store_id, $ingId, $name, $cost, $unit]);
        $saved[] = [
            'store_id' => $store_id,
            'ingredient_id' => $ingId,
            'ingredient_name' => $name,
            'cost' => $cost,
            'cost_unit' => $unit
        ];
    }

    jsonResponse(['message' => 'Ingredient costs saved', 'count' => count($saved), 'saved' => $saved]);
}

function handleApplyPriceFloor($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    $menu_item_id = (int)($input['menu_item_id'] ?? 0);
    $price_floor = (float)($input['price_floor'] ?? 0);

    if ($menu_item_id <= 0) {
        jsonResponse(['error' => 'Invalid menu_item_id'], 422);
    }

    $stmtCheck = $pdo->prepare("SELECT id, name, base_price FROM menu_items WHERE id = ? AND store_id = ?");
    $stmtCheck->execute([$menu_item_id, $store_id]);
    $dish = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$dish) {
        jsonResponse(['error' => 'Dish not found or does not belong to your store'], 404);
    }

    $basePrice = (float)$dish['base_price'];
    if ($price_floor <= 0 || $price_floor > $basePrice) {
        jsonResponse(['error' => "Price floor must be greater than 0 and cannot exceed the base price of ₱{$basePrice}."], 422);
    }

    $stmtUpdate = $pdo->prepare("UPDATE menu_items SET price_floor = ? WHERE id = ? AND store_id = ?");
    $stmtUpdate->execute([$price_floor, $menu_item_id, $store_id]);

    jsonResponse([
        'message' => 'Price floor updated successfully',
        'menu_item_id' => $menu_item_id,
        'price_floor' => $price_floor,
        'dish_name' => $dish['name']
    ]);
}

function handleGetRecipe($pdo) {
    $store_id = requireVendor($pdo);
    $menu_item_id = isset($_GET['menu_item_id']) ? (int)$_GET['menu_item_id'] : 0;
    if ($menu_item_id <= 0) {
        jsonResponse(['error' => 'Missing or invalid menu_item_id'], 400);
    }

    $stmtDish = $pdo->prepare("SELECT id, name, base_price, price_floor, category FROM menu_items WHERE id = ? AND store_id = ?");
    $stmtDish->execute([$menu_item_id, $store_id]);
    $dish = $stmtDish->fetch(PDO::FETCH_ASSOC);
    if (!$dish) {
        jsonResponse(['error' => 'Dish not found or does not belong to your store'], 404);
    }

    // Load store's saved ingredient costs
    $stmtCosts = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ?");
    $stmtCosts->execute([$store_id]);
    $allCosts = $stmtCosts->fetchAll(PDO::FETCH_ASSOC);

    $costMapById = [];
    $costMapByName = [];
    foreach ($allCosts as $c) {
        if (!empty($c['ingredient_id'])) {
            $costMapById[(int)$c['ingredient_id']] = $c;
        }
        $costMapByName[strtolower(trim($c['ingredient_name']))] = $c;
    }

    $stmtRecipe = $pdo->prepare("SELECT * FROM recipes WHERE menu_item_id = ?");
    $stmtRecipe->execute([$menu_item_id]);
    $recipe = $stmtRecipe->fetch(PDO::FETCH_ASSOC);

    if (!$recipe) {
        jsonResponse([
            'exists' => false,
            'menu_item_id' => $menu_item_id,
            'dish_name' => $dish['name'],
            'base_price' => (float)$dish['base_price'],
            'current_price_floor' => (float)$dish['price_floor'],
            'yield_servings' => 1,
            'instructions' => '',
            'ingredients' => [],
            'allergens' => [],
            'sensitivities' => [],
            'has_unmapped' => false,
            'total_batch_cost' => 0.0,
            'cost_per_serving' => 0.0,
            'cost_coverage' => '0 of 0 ingredients costed',
            'costed_count' => 0,
            'total_count' => 0,
            'suggested_price_floor' => 0.0,
            'margin_multiplier' => 2.5
        ]);
    }

    $stmtIng = $pdo->prepare("
        SELECT ri.id, ri.ingredient_id, ri.ingredient_name, ri.quantity, ri.unit,
               i.name as canonical_name
        FROM recipe_ingredients ri
        LEFT JOIN ingredients i ON ri.ingredient_id = i.id
        WHERE ri.recipe_id = ?
        ORDER BY ri.id ASC
    ");
    $stmtIng->execute([$recipe['id']]);
    $rawIngredients = $stmtIng->fetchAll(PDO::FETCH_ASSOC);

    $ingredients = [];
    $hasUnmapped = false;
    $ingredientIds = [];
    $totalBatchCost = 0.0;
    $costedCount = 0;
    $totalCount = 0;

    foreach ($rawIngredients as $ri) {
        $totalCount++;
        $isUnmapped = empty($ri['ingredient_id']);
        if ($isUnmapped) {
            $hasUnmapped = true;
        } else {
            $ingredientIds[] = (int)$ri['ingredient_id'];
        }

        // Match against vendor saved costs
        $matchedCost = null;
        if (!empty($ri['ingredient_id']) && isset($costMapById[(int)$ri['ingredient_id']])) {
            $matchedCost = $costMapById[(int)$ri['ingredient_id']];
        } else {
            $cleanName = strtolower(trim($ri['ingredient_name']));
            if (isset($costMapByName[$cleanName])) {
                $matchedCost = $costMapByName[$cleanName];
            }
        }

        $unitCost = $matchedCost ? (float)$matchedCost['cost'] : 0.0;
        $costUnit = $matchedCost ? $matchedCost['cost_unit'] : (in_array(strtolower($ri['unit']), ['g', 'kg']) ? 'kg' : (in_array(strtolower($ri['unit']), ['ml', 'l']) ? 'l' : $ri['unit']));
        $costResult = $matchedCost 
            ? calculateIngredientItemCost((float)$ri['quantity'], $ri['unit'], $unitCost, $matchedCost['cost_unit']) 
            : ['cost' => null, 'reason' => 'No cost recorded'];

        if ($costResult['cost'] !== null) {
            $totalBatchCost += $costResult['cost'];
            $costedCount++;
        }

        $ingredients[] = [
            'id' => (int)$ri['id'],
            'ingredient_id' => $ri['ingredient_id'] ? (int)$ri['ingredient_id'] : null,
            'name' => $ri['ingredient_name'],
            'quantity' => (float)$ri['quantity'],
            'unit' => $ri['unit'],
            'unit_cost' => $unitCost,
            'cost_unit' => $costUnit,
            'item_cost' => $costResult['cost'] !== null ? round($costResult['cost'], 2) : null,
            'cost_reason' => $costResult['reason'],
            'has_cost' => ($costResult['cost'] !== null),
            'is_unmapped' => $isUnmapped
        ];
    }

    $allergens = [];
    if (!empty($ingredientIds)) {
        $inClause = implode(',', array_fill(0, count($ingredientIds), '?'));
        $stmtAllergens = $pdo->prepare("
            SELECT DISTINCT ca.name 
            FROM ingredient_allergen_map iam
            JOIN critical_allergens ca ON iam.allergen_id = ca.id
            WHERE iam.ingredient_id IN ($inClause)
            ORDER BY ca.name ASC
        ");
        $stmtAllergens->execute($ingredientIds);
        $allergens = $stmtAllergens->fetchAll(PDO::FETCH_COLUMN);
    }

    $stmtSens = $pdo->prepare("
        SELECT DISTINCT ds.name
        FROM menu_item_digestive_sensitivities mids
        JOIN digestive_sensitivities ds ON mids.sensitivity_id = ds.id
        WHERE mids.menu_item_id = ?
        ORDER BY ds.name ASC
    ");
    $stmtSens->execute([$menu_item_id]);
    $sensitivities = $stmtSens->fetchAll(PDO::FETCH_COLUMN);

    $yieldServings = max(1, (int)$recipe['yield_servings']);
    $costPerServing = round($totalBatchCost / (float)$yieldServings, 2);
    $suggestedPriceFloor = min((float)$dish['base_price'], round($costPerServing * 2.5, 2));
    $costCoverage = "{$costedCount} of {$totalCount} ingredients costed";

    jsonResponse([
        'exists' => true,
        'id' => (int)$recipe['id'],
        'menu_item_id' => $menu_item_id,
        'dish_name' => $dish['name'],
        'base_price' => (float)$dish['base_price'],
        'current_price_floor' => (float)$dish['price_floor'],
        'yield_servings' => $yieldServings,
        'instructions' => $recipe['instructions'] ?? '',
        'ingredients' => $ingredients,
        'allergens' => $allergens,
        'sensitivities' => $sensitivities,
        'has_unmapped' => $hasUnmapped,
        'total_batch_cost' => round($totalBatchCost, 2),
        'cost_per_serving' => $costPerServing,
        'suggested_price_floor' => $suggestedPriceFloor,
        'cost_coverage' => $costCoverage,
        'costed_count' => $costedCount,
        'total_count' => $totalCount,
        'margin_multiplier' => 2.5
    ]);
}

function handleSaveRecipe($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['menu_item_id'])) {
        jsonResponse(['error' => 'Missing menu_item_id'], 400);
    }
    $menu_item_id = (int)$input['menu_item_id'];

    if (!isset($input['confirmed']) || $input['confirmed'] !== true) {
        jsonResponse(['error' => 'Recipe must be confirmed by vendor.'], 422);
    }

    $yield_servings = (int)($input['yield_servings'] ?? 1);
    if ($yield_servings < 1 || $yield_servings > 1000) {
        jsonResponse(['error' => 'Yield servings must be between 1 and 1000.'], 422);
    }

    $instructions = isset($input['instructions']) ? trim($input['instructions']) : '';
    $rawIngredients = isset($input['ingredients']) && is_array($input['ingredients']) ? $input['ingredients'] : [];

    $allowedUnits = ['g', 'kg', 'ml', 'l', 'pc', 'tsp', 'tbsp', 'cup'];

    foreach ($rawIngredients as $ing) {
        $ingName = trim($ing['name'] ?? '');
        if ($ingName === '') continue;

        $unit = strtolower(trim($ing['unit'] ?? ''));
        if (!in_array($unit, $allowedUnits, true)) {
            jsonResponse(['error' => "Invalid ingredient unit '{$unit}'. Allowed units: " . implode(', ', $allowedUnits)], 422);
        }

        $qty = (float)($ing['quantity'] ?? 0);
        if ($qty <= 0) {
            jsonResponse(['error' => "Quantity must be greater than 0 for ingredient '{$ingName}'."], 422);
        }

        if (in_array($unit, ['g', 'ml'], true) && $qty > 50000) {
            jsonResponse(['error' => "Quantity {$qty}{$unit} for '{$ingName}' exceeds limit of 50,000 for {$unit}."], 422);
        } elseif (in_array($unit, ['kg', 'l'], true) && $qty > 50) {
            jsonResponse(['error' => "Quantity {$qty}{$unit} for '{$ingName}' exceeds limit of 50 for {$unit}."], 422);
        } elseif (!in_array($unit, ['g', 'ml', 'kg', 'l'], true) && $qty > 500) {
            jsonResponse(['error' => "Quantity {$qty}{$unit} for '{$ingName}' exceeds limit of 500 for {$unit}."], 422);
        }
    }

    $stmtDish = $pdo->prepare("SELECT id, name FROM menu_items WHERE id = ? AND store_id = ?");
    $stmtDish->execute([$menu_item_id, $store_id]);
    $dish = $stmtDish->fetch(PDO::FETCH_ASSOC);
    if (!$dish) {
        jsonResponse(['error' => 'Dish not found or does not belong to your store'], 403);
    }

    try {
        $pdo->beginTransaction();

        $confirmedBy = $_SESSION['user_id'] ?? null;
        $stmtRecipe = $pdo->prepare("
            INSERT INTO recipes (menu_item_id, yield_servings, instructions, recipe_confirmed_by, recipe_confirmed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                yield_servings = VALUES(yield_servings),
                instructions = VALUES(instructions),
                recipe_confirmed_by = VALUES(recipe_confirmed_by),
                recipe_confirmed_at = NOW()
        ");
        $stmtRecipe->execute([$menu_item_id, $yield_servings, $instructions, $confirmedBy]);

        $stmtGetId = $pdo->prepare("SELECT id FROM recipes WHERE menu_item_id = ?");
        $stmtGetId->execute([$menu_item_id]);
        $recipeId = (int)$stmtGetId->fetchColumn();

        $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);

        $savedIngredients = [];
        $mappedIngredientIds = [];
        $hasUnmapped = false;

        $insertIng = $pdo->prepare("
            INSERT INTO recipe_ingredients (recipe_id, ingredient_id, ingredient_name, quantity, unit)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insertSub = $pdo->prepare("
            INSERT IGNORE INTO ingredient_submissions (raw_name, status, store_id)
            VALUES (?, 'pending', ?)
        ");

        foreach ($rawIngredients as $ing) {
            $ingName = trim($ing['name'] ?? '');
            if ($ingName === '') continue;
            $qty = (float)($ing['quantity'] ?? 0);
            $unit = strtolower(trim($ing['unit'] ?? 'g'));

            $stmtTax = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmtTax->execute([$ingName]);
            $taxId = $stmtTax->fetchColumn();

            $ingredientId = $taxId ? (int)$taxId : null;
            if ($ingredientId) {
                $mappedIngredientIds[] = $ingredientId;
            } else {
                $hasUnmapped = true;
                $insertSub->execute([$ingName, $store_id]);
            }

            $insertIng->execute([$recipeId, $ingredientId, $ingName, $qty, $unit]);
            $savedIngredients[] = [
                'name' => $ingName,
                'quantity' => $qty,
                'unit' => $unit,
                'ingredient_id' => $ingredientId,
                'is_unmapped' => ($ingredientId === null)
            ];
        }

        // A2: Single source of truth sync using syncDishIngredientLinks
        $syncResult = syncDishIngredientLinks($pdo, $menu_item_id, $store_id);
        $detectedAllergens = $syncResult['allergens'];

        $pdo->commit();

        jsonResponse([
            'success' => true,
            'message' => 'Recipe saved successfully',
            'recipe_id' => $recipeId,
            'menu_item_id' => $menu_item_id,
            'yield_servings' => $yield_servings,
            'recipe_confirmed_by' => $confirmedBy,
            'ingredients' => $savedIngredients,
            'allergens_preview' => array_values(array_unique($detectedAllergens)),
            'has_unmapped' => $hasUnmapped
        ], 200);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to save recipe: ' . $e->getMessage()], 500);
    }
}

function handleSuggestRecipe($pdo) {
    $session = requireRole($pdo, ['vendor', 'admin']);

    $stmt = $pdo->query("SELECT ai_enabled FROM settings WHERE id = 1");
    $aiEnabled = (int)($stmt->fetchColumn() ?? 1);
    if ($aiEnabled === 0) {
        jsonResponse([
            'error' => 'AI Recipe Generation is currently disabled by Administrator.',
            'fallback' => true,
            'ai_disabled' => true
        ], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $dishName = trim($input['name'] ?? $input['dish_name'] ?? '');
    if ($dishName === '') {
        jsonResponse(['error' => 'Missing dish name for recipe suggestion', 'fallback' => true], 400);
    }

    $rawExisting = isset($input['ingredients']) && is_array($input['ingredients']) 
        ? $input['ingredients'] 
        : (isset($input['existing_ingredients']) && is_array($input['existing_ingredients']) ? $input['existing_ingredients'] : []);

    $existingIngredientsList = [];
    foreach ($rawExisting as $item) {
        $n = is_array($item) ? trim($item['name'] ?? '') : trim($item);
        if ($n !== '') $existingIngredientsList[] = $n;
    }

    // In PHP sort modifies in place
    $sortedList = $existingIngredientsList;
    sort($sortedList);
    $cacheKey = 'recipe_suggest_' . md5(json_encode($sortedList));

    $stmtCache = $pdo->prepare("SELECT response_payload FROM ai_query_cache WHERE dish_name IN (?, ?) LIMIT 1");
    $stmtCache->execute([$cacheKey, 'recipe_' . md5(strtolower($dishName))]);
    $cached = $stmtCache->fetch(PDO::FETCH_ASSOC);

    if ($cached && !empty($cached['response_payload'])) {
        $cachedData = json_decode($cached['response_payload'], true);
        if ($cachedData && !empty($cachedData['ingredients'])) {
            jsonResponse([
                'success' => true,
                'cached' => true,
                'data' => $cachedData,
                'fallback' => false
            ], 200);
        }
    }

    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey || $apiKey === 'your_api_key_here') {
        jsonResponse([
            'error' => 'Gemini API key is not configured.',
            'fallback' => true
        ], 503);
    }

    $allowedUnits = ['g', 'kg', 'ml', 'l', 'pc', 'tbsp', 'tsp', 'cup'];

    $systemInstruction = "You are an expert institutional food-service chef. Return ONLY raw JSON without markdown formatting.
Schema:
{
  \"yield_servings\": 10,
  \"ingredients\": [
    {\"name\": \"Chicken Thigh\", \"quantity\": 1.5, \"unit\": \"kg\"},
    {\"name\": \"Soy Sauce\", \"quantity\": 200, \"unit\": \"ml\"}
  ],
  \"instructions\": \"Brief prep and cooking steps.\"
}
RULES:
1. 'unit' MUST strictly be one of: ['g', 'kg', 'ml', 'l', 'pc', 'tbsp', 'tsp', 'cup']. Any other unit is forbidden.
2. 'quantity' caps: g/ml <= 50000, kg/l <= 50, other units <= 500.
3. 'yield_servings' MUST be between 1 and 1000.
4. If an ingredient list is provided, ONLY include ingredients from that list. Do NOT invent new ingredients.
5. If the dish name is gibberish or not food, return JSON: {\"error\": \"Invalid food item\"}.";

    $userPrompt = "Generate institutional recipe for dish: " . $dishName;
    if (!empty($existingIngredientsList)) {
        $userPrompt .= "\nApproved ingredient list: " . implode(', ', $existingIngredientsList);
    }

    $modelName = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.5-flash';
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key=" . urlencode($apiKey);

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $systemInstruction . "\n\n" . $userPrompt]
                ]
            ]
        ],
        "generationConfig" => [
            "responseMimeType" => "application/json"
        ]
    ];

    $ch = curl_init($geminiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        jsonResponse([
            'error' => 'AI Service temporarily unavailable. Please enter recipe manually.',
            'fallback' => true
        ], 503);
    }

    $resData = json_decode($response, true);
    $text = $resData['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        jsonResponse([
            'error' => 'AI returned empty response.',
            'fallback' => true
        ], 503);
    }

    $aiJson = json_decode(trim($text), true);
    if (!$aiJson || !empty($aiJson['error']) || empty($aiJson['ingredients']) || !is_array($aiJson['ingredients'])) {
        jsonResponse([
            'error' => $aiJson['error'] ?? 'Could not recognize dish as food.',
            'fallback' => true
        ], 422);
    }

    // Validate AI output strictly against M3 unit/quantity bounds and drop any unprompted ingredients
    $validatedIngredients = [];
    $allowedNamesLower = !empty($existingIngredientsList) ? array_map('strtolower', $existingIngredientsList) : [];

    foreach ($aiJson['ingredients'] as $ing) {
        $name = trim($ing['name'] ?? '');
        if ($name === '') continue;

        // Drop any ingredient names the AI invents that weren't in the original prompt
        if (!empty($allowedNamesLower) && !in_array(strtolower($name), $allowedNamesLower, true)) {
            continue;
        }

        $unit = strtolower(trim($ing['unit'] ?? ''));
        if (!in_array($unit, $allowedUnits, true)) {
            continue;
        }

        $qty = (float)($ing['quantity'] ?? 0);
        if ($qty <= 0) continue;

        // Quantity caps (g/ml <= 50000, kg/l <= 50, others <= 500)
        if (in_array($unit, ['g', 'ml'], true)) {
            $qty = min(50000.0, $qty);
        } elseif (in_array($unit, ['kg', 'l'], true)) {
            $qty = min(50.0, $qty);
        } else {
            $qty = min(500.0, $qty);
        }

        $validatedIngredients[] = [
            'name' => $name,
            'quantity' => $qty,
            'unit' => $unit
        ];
    }

    $yieldServings = max(1, min(1000, (int)($aiJson['yield_servings'] ?? 10)));
    $aiJson['yield_servings'] = $yieldServings;
    $aiJson['ingredients'] = $validatedIngredients;

    $cacheStmt = $pdo->prepare("
        INSERT INTO ai_query_cache (dish_name, response_payload, created_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE response_payload = VALUES(response_payload), created_at = NOW()
    ");
    $cacheStmt->execute([$cacheKey, json_encode($aiJson)]);

    jsonResponse([
        'success' => true,
        'cached' => false,
        'data' => $aiJson,
        'fallback' => false
    ], 200);
}

function handleGetPrepPlan($pdo) {
    $store_id = requireVendor($pdo);

    $requestedItems = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $requestedItems = $body['items'] ?? [];
    } elseif (isset($_GET['items'])) {
        $requestedItems = json_decode($_GET['items'], true) ?: [];
    }

    if (empty($requestedItems)) {
        $stmtActive = $pdo->prepare("
            SELECT m.id as menu_item_id, m.name, 
                   GREATEST(1, COALESCE(inv.online_pool_qty, 0) + COALESCE(inv.walkin_pool_qty, 0)) as default_servings
            FROM menu_items m
            LEFT JOIN inventory inv ON m.id = inv.menu_item_id
            WHERE m.store_id = ? AND m.is_active = 1
            ORDER BY m.id ASC
        ");
        $stmtActive->execute([$store_id]);
        $activeRows = $stmtActive->fetchAll(PDO::FETCH_ASSOC);
        foreach ($activeRows as $ar) {
            $requestedItems[] = [
                'menu_item_id' => (int)$ar['menu_item_id'],
                'servings' => (int)$ar['default_servings']
            ];
        }
    }

    $combinedMap = [];
    $dishesBreakdown = [];
    $needsRecipe = [];
    $totalPlannedServings = 0;

    foreach ($requestedItems as $req) {
        $menuItemId = (int)($req['menu_item_id'] ?? 0);
        $plannedServings = min(1000, max(1, (int)($req['servings'] ?? 10)));
        if ($menuItemId <= 0) continue;

        $stmtDish = $pdo->prepare("SELECT id, name FROM menu_items WHERE id = ? AND store_id = ?");
        $stmtDish->execute([$menuItemId, $store_id]);
        $dish = $stmtDish->fetch(PDO::FETCH_ASSOC);
        if (!$dish) continue;

        $stmtRecipe = $pdo->prepare("SELECT * FROM recipes WHERE menu_item_id = ?");
        $stmtRecipe->execute([$menuItemId]);
        $recipe = $stmtRecipe->fetch(PDO::FETCH_ASSOC);

        if (!$recipe) {
            // M4: Do not fabricate "1 pc" for dishes without a recipe. Omit their math and append to needs_recipe.
            $needsRecipe[] = [
                'menu_item_id' => $menuItemId,
                'name' => $dish['name'],
                'planned_servings' => $plannedServings
            ];
            continue;
        }

        $totalPlannedServings += $plannedServings;
        $yieldServings = max(1, (int)$recipe['yield_servings']);
        $scaleFactor = (float)$plannedServings / (float)$yieldServings;

        $stmtIngs = $pdo->prepare("
            SELECT ri.*, i.name as canonical_name
            FROM recipe_ingredients ri
            LEFT JOIN ingredients i ON ri.ingredient_id = i.id
            WHERE ri.recipe_id = ?
        ");
        $stmtIngs->execute([$recipe['id']]);
        $dishIngredients = $stmtIngs->fetchAll(PDO::FETCH_ASSOC);

        $dishesBreakdown[] = [
            'menu_item_id' => $menuItemId,
            'name' => $dish['name'],
            'planned_servings' => $plannedServings,
            'yield_servings' => $yieldServings,
            'scale_factor' => round($scaleFactor, 2),
            'has_recipe' => true
        ];

        foreach ($dishIngredients as $ing) {
            $ingName = trim($ing['ingredient_name'] ?? '');
            if ($ingName === '') continue;

            $unit = strtolower(trim($ing['unit'] ?? 'g'));
            $origQty = (float)($ing['quantity'] ?? 0);
            $scaledQty = $origQty * $scaleFactor;
            $isUnmapped = empty($ing['ingredient_id']);

            $dimension = 'other';
            $baseQty = $scaledQty;
            $baseUnit = $unit;

            if (in_array($unit, ['g', 'kg'], true)) {
                $dimension = 'mass';
                $baseUnit = 'g';
                $baseQty = ($unit === 'kg') ? ($scaledQty * 1000) : $scaledQty;
            } elseif (in_array($unit, ['ml', 'l', 'liter', 'liters'], true)) {
                $dimension = 'volume';
                $baseUnit = 'ml';
                $baseQty = in_array($unit, ['l', 'liter', 'liters'], true) ? ($scaledQty * 1000) : $scaledQty;
            } elseif (in_array($unit, ['pc', 'pcs', 'piece', 'pieces'], true)) {
                $dimension = 'count';
                $baseUnit = 'pc';
            } else {
                $dimension = 'unit_' . $unit;
                $baseUnit = $unit;
            }

            $normName = strtolower($ingName);
            $key = $normName . '::' . $dimension;

            if (!isset($combinedMap[$key])) {
                $combinedMap[$key] = [
                    'name' => $ingName,
                    'dimension' => $dimension,
                    'base_unit' => $baseUnit,
                    'total_base_qty' => 0.0,
                    'is_unmapped' => false,
                    'dishes' => []
                ];
            }

            $combinedMap[$key]['total_base_qty'] += $baseQty;
            if ($isUnmapped) {
                $combinedMap[$key]['is_unmapped'] = true;
            }
            $combinedMap[$key]['dishes'][] = [
                'dish_name' => $dish['name'],
                'servings' => $plannedServings,
                'scaled_qty' => round($scaledQty, 2),
                'unit' => $unit
            ];
        }
    }

    $prepList = [];
    foreach ($combinedMap as $key => $item) {
        $finalQty = $item['total_base_qty'];
        $finalUnit = $item['base_unit'];

        if ($item['dimension'] === 'mass') {
            if ($finalQty >= 1000) {
                $finalQty = round($finalQty / 1000, 2);
                $finalUnit = 'kg';
            } else {
                $finalQty = round($finalQty, 1);
                $finalUnit = 'g';
            }
        } elseif ($item['dimension'] === 'volume') {
            if ($finalQty >= 1000) {
                $finalQty = round($finalQty / 1000, 2);
                $finalUnit = 'l';
            } else {
                $finalQty = round($finalQty, 1);
                $finalUnit = 'ml';
            }
        } elseif ($item['dimension'] === 'count') {
            $finalQty = round($finalQty, 1);
            $finalUnit = 'pc';
        } else {
            $finalQty = round($finalQty, 2);
        }

        $dishNames = array_values(array_unique(array_column($item['dishes'], 'dish_name')));

        $prepList[] = [
            'name' => $item['name'],
            'total_quantity' => $finalQty,
            'unit' => $finalUnit,
            'dimension' => $item['dimension'],
            'is_unmapped' => (bool)$item['is_unmapped'],
            'used_in_dishes' => $dishNames,
            'dish_count' => count($dishNames),
            'breakdown' => $item['dishes']
        ];
    }

    usort($prepList, function($a, $b) {
        if ($a['is_unmapped'] !== $b['is_unmapped']) {
            return $b['is_unmapped'] <=> $a['is_unmapped'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    jsonResponse([
        'total_dishes' => count($dishesBreakdown),
        'total_servings' => $totalPlannedServings,
        'dishes' => $dishesBreakdown,
        'needs_recipe' => $needsRecipe,
        'prep_list' => $prepList
    ]);
}

