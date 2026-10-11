<?php
function requireVendor($pdo) {
    $session = requireRole($pdo, ['vendor']);
    
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

function handleGetOrders($pdo) {
    $store_id = requireStaff($pdo);
    
    $stmt = $pdo->prepare("
        SELECT o.id, o.user_id, o.status, o.total_amount, o.claim_token, o.reserved_at,
               o.fulfillment_type, o.handled_by, o.allergen_override, o.allergen_conflicts, u.name as customer_name
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

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $apiKey;

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
    
    try {
        $pdo->beginTransaction();
        
        if ($dishId) {
            $stmt = $pdo->prepare("
                UPDATE menu_items 
                SET name = ?, description = ?, category = ?, dietary = ?, base_price = ?, price_floor = ?, image_url = ?,
                    calories = ?, protein_g = ?, carbs_g = ?, fat_g = ?, sodium_mg = ?, ai_confirmed = ?
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
                $dishId, $store_id
            ]);
            
            $pdo->prepare("DELETE FROM menu_item_ingredients WHERE menu_item_id = ?")->execute([$dishId]);
            $pdo->prepare("DELETE FROM menu_item_critical_allergens WHERE menu_item_id = ?")->execute([$dishId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO menu_items (store_id, name, description, category, dietary, base_price, price_floor, image_url, calories, protein_g, carbs_g, fat_g, sodium_mg, ai_confirmed, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
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
                $calories, $protein_g, $carbs_g, $fat_g, $sodium_mg, $ai_confirmed
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
    
    $rawIngredients = [];
    if (!empty($input['ingredients']) && is_array($input['ingredients'])) {
        $rawIngredients = $input['ingredients'];
    } elseif (!empty($input['ingredientsString'])) {
        $rawIngredients = explode(',', $input['ingredientsString']);
    }

    if (!empty($rawIngredients)) {
        $ingredients = array_unique(array_filter(array_map('trim', $rawIngredients)));
        
        $findStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $subStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_submissions (raw_name, status) VALUES (?, 'pending')");
        $mapIngStmt = $pdo->prepare("INSERT IGNORE INTO menu_item_ingredients (menu_item_id, ingredient_id) VALUES (?, ?)");
        $getAllergensStmt = $pdo->prepare("SELECT allergen_id FROM ingredient_allergen_map WHERE ingredient_id = ?");
        $mapAllergenStmt = $pdo->prepare("INSERT IGNORE INTO menu_item_critical_allergens (menu_item_id, allergen_id) VALUES (?, ?)");

        foreach ($ingredients as $ing) {
            if ($ing === '') continue;

            $findStmt->execute([$ing]);
            $ingId = $findStmt->fetchColumn();

            if ($ingId) {
                // Recognized ingredient: map to dish and critical allergens
                $mapIngStmt->execute([$dishId, $ingId]);
                    
                $getAllergensStmt->execute([$ingId]);
                $allergens = $getAllergensStmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($allergens as $allergenId) {
                    $mapAllergenStmt->execute([$dishId, $allergenId]);
                }
            } else {
                // Unrecognized ingredient: intercept and push to ingredient_submissions queue
                $subStmt->execute([$ing]);
            }
        }
    }
    
        $mappedAllergenNames = [];
        if ($dishId) {
            $stmt = $pdo->prepare("
                SELECT ca.name 
                FROM menu_item_critical_allergens mica
                JOIN critical_allergens ca ON mica.allergen_id = ca.id
                WHERE mica.menu_item_id = ?
            ");
            $stmt->execute([$dishId]);
            $mappedAllergenNames = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $pdo->commit();
        
        jsonResponse(['message' => 'Dish saved', 'id' => $dishId, 'mapped_allergens' => $mappedAllergenNames]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to save dish: ' . $e->getMessage()], 500);
    }
}

function handleLaunchMenu($pdo) {
    $session = requireRole($pdo, ['vendor', 'admin']);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['store_id'])) {
        jsonResponse(['error' => 'Missing store_id'], 400);
    }
    $store_id = $input['store_id'];

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

        // Reset all dishes for this store to inactive
        $stmtReset = $pdo->prepare("UPDATE menu_items SET is_active = 0 WHERE store_id = ?");
        $stmtReset->execute([$store_id]);

        $stmtActivate = $pdo->prepare("UPDATE menu_items SET is_active = 1 WHERE id = ? AND store_id = ?");
        $stmtUpdateStock = $pdo->prepare("
            UPDATE inventory 
            SET online_pool_qty = ?, 
                delisted_from_app = 0,
                cooked_at = ?,
                sell_by = ?
            WHERE menu_item_id = ?
        ");

        foreach ($input['items'] as $item) {
            if (!isset($item['menu_item_id'], $item['stock'])) {
                continue;
            }
            
            $itemCookedAt = !empty($item['cooked_at']) 
                ? date('Y-m-d H:i:s', strtotime($item['cooked_at'])) 
                : $batchCookedAt;
                
            $itemSellBy = !empty($item['sell_by']) 
                ? date('Y-m-d H:i:s', strtotime($item['sell_by'])) 
                : $batchSellBy;

            $stmtActivate->execute([$item['menu_item_id'], $store_id]);
            
            if ($stmtActivate->rowCount() > 0) {
                // Ensure inventory updates only if the item genuinely belongs to the store
                $stmtUpdateStock->execute([$item['stock'], $itemCookedAt, $itemSellBy, $item['menu_item_id']]);
            }
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

    // 2. Unsold live inventory / waste for active dishes (including Walk-in Pool)
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
            CASE 
                WHEN i.sell_by IS NOT NULL AND i.sell_by <= NOW() THEN 1 
                ELSE 0 
            END AS is_expired,
            ROUND(m.base_price * (i.online_pool_qty + i.walkin_pool_qty), 2) AS estimated_waste_value
        FROM menu_items m
        JOIN inventory i ON m.id = i.menu_item_id
        WHERE m.store_id = ? AND m.is_active = 1
        ORDER BY m.name ASC
    ";
    $stmtInventory = $pdo->prepare($inventoryQuery);
    $stmtInventory->execute([$store_id]);
    $unsoldItems = $stmtInventory->fetchAll(PDO::FETCH_ASSOC);

    $totalUnsoldQty = 0;
    $totalWasteValue = 0.0;
    $expiredDishesCount = 0;
    foreach ($unsoldItems as $item) {
        $totalUnsoldQty += (int)$item['remaining_stock'];
        $totalWasteValue += (float)$item['estimated_waste_value'];
        if (!empty($item['is_expired'])) {
            $expiredDishesCount++;
        }
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
            'total_revenue' => (float)$orderSummary['total_revenue'],
            'total_orders' => (int)$orderSummary['total_orders'],
            'claimed_orders' => (int)$orderSummary['claimed_orders'],
            'cancelled_orders' => (int)$orderSummary['cancelled_orders'],
            'expired_orders' => (int)$orderSummary['expired_orders'],
            'active_orders' => (int)$orderSummary['active_orders'],
            'total_unsold_units' => $totalUnsoldQty,
            'total_waste_value' => round($totalWasteValue, 2),
            'expired_dishes_count' => $expiredDishesCount
        ],
        'unsold_inventory' => $unsoldItems,
        'orders' => $recentOrders
    ]);
}
