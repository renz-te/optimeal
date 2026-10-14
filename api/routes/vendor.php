<?php
require_once __DIR__ . '/../PricingEngine.php';
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
            SELECT oi.order_id, oi.menu_item_id, oi.quantity, oi.portion_preference, m.name as item_name, m.image_url
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

        $checkStmt = $pdo->prepare("SELECT id, user_id, status, allergen_override, allergen_conflicts FROM orders WHERE id = ? AND store_id = ? FOR UPDATE");
        $checkStmt->execute([$order_id, $store_id]);
        $order = $checkStmt->fetch();

        if (!$order) {
            throw new BusinessException("Order not found or does not belong to your store.", 404);
        }
        
        // Prevent changing status if already cancelled or completed
        if (in_array($order['status'], ['cancelled', 'completed'])) {
            throw new BusinessException("Cannot change status of a " . $order['status'] . " order.", 400);
        }

        // Restrict "Mark Claimed" action if allergen_override is active until confirmed by staff
        if (in_array($status, ['claimed', 'completed']) && !empty($order['allergen_override'])) {
            $staffConfirmed = !empty($input['confirm_allergen_override']) || !empty($input['allergen_confirmed']);
            if (!$staffConfirmed) {
                $conflicts = !empty($order['allergen_conflicts']) ? $order['allergen_conflicts'] : 'undisclosed allergens';
                throw new BusinessException("Allergen Warning: This order has an active allergen override ($conflicts). Staff confirmation is required to mark it as claimed.", 400);
            }
        }

        $staffUserId = $_SESSION['user_id'];
        $updateStmt = $pdo->prepare("UPDATE orders SET status = ?, handled_by = ? WHERE id = ?");
        $updateStmt->execute([$status, $staffUserId, $order_id]);

        if ($status === 'cancelled') {
            $itemStmt = $pdo->prepare("SELECT menu_item_id, batch_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$order_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStmt = $pdo->prepare("UPDATE inventory SET online_pool_qty = online_pool_qty + ?, delisted_from_app = CASE WHEN online_pool_qty + ? > critical_stock_threshold THEN 0 ELSE delisted_from_app END WHERE menu_item_id = ?");
            $refundClaim = $pdo->prepare("
                UPDATE daily_discount_claims d
                JOIN orders o ON o.user_id = d.user_id
                SET d.quantity_claimed = GREATEST(0, d.quantity_claimed - ?)
                WHERE d.menu_item_id = ? AND o.id = ? AND d.claim_date = DATE(o.reserved_at)
            ");
            $releaseBatch = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE id = ?");
            $fallbackRelease = $pdo->prepare("UPDATE batches SET held_stock = GREATEST(0, held_stock - ?) WHERE menu_item_id = ? AND held_stock > 0 ORDER BY sell_by ASC LIMIT 1");

            foreach ($items as $item) {
                $qty = (int)$item['quantity'];
                $restoreStmt->execute([$qty, $qty, $item['menu_item_id']]);
                $refundClaim->execute([$qty, $item['menu_item_id'], $order_id]);

                if (!empty($item['batch_id'])) {
                    $releaseBatch->execute([$qty, $item['batch_id']]);
                } else {
                    $fallbackRelease->execute([$qty, $item['menu_item_id']]);
                }
            }
        } elseif (in_array($status, ['claimed', 'completed'])) {
            $itemStmt = $pdo->prepare("SELECT menu_item_id, batch_id, quantity FROM order_items WHERE order_id = ?");
            $itemStmt->execute([$order_id]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $claimBatch = $pdo->prepare("
                UPDATE batches 
                SET remaining_stock = GREATEST(0, remaining_stock - ?), 
                    held_stock = GREATEST(0, held_stock - ?) 
                WHERE id = ?
            ");
            $fallbackClaim = $pdo->prepare("
                UPDATE batches 
                SET remaining_stock = GREATEST(0, remaining_stock - ?), 
                    held_stock = GREATEST(0, held_stock - ?) 
                WHERE menu_item_id = ? AND remaining_stock > 0 
                ORDER BY sell_by ASC LIMIT 1
            ");

            foreach ($items as $item) {
                $qty = (int)$item['quantity'];
                if (!empty($item['batch_id'])) {
                    $claimBatch->execute([$qty, $qty, $item['batch_id']]);
                } else {
                    $fallbackClaim->execute([$qty, $qty, $item['menu_item_id']]);
                }
            }
        }

        $pdo->commit();

        try {
            $customerUserId = (int)($order['user_id'] ?? 0);
            if ($customerUserId > 0) {
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, message, reference_id, is_read, created_at)
                    VALUES (?, 'order_status', ?, ?, 0, NOW())
                ");
                $notifStmt->execute([
                    $customerUserId,
                    "Your order #{$order_id} is now {$status}.",
                    $order_id
                ]);
            }
        } catch (Exception $e) {
            error_log("Failed to dispatch order_status notification: " . $e->getMessage());
        }

        jsonResponse(['message' => 'Order status updated successfully']);
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
        error_log("handleUpdateOrderStatus error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
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

function deleteDishImageSafely($imageUrl) {
    if (empty($imageUrl) || !is_string($imageUrl)) {
        return;
    }
    $cleanPath = ltrim($imageUrl, '/');
    if (str_starts_with($cleanPath, 'optimeal/')) {
        $cleanPath = substr($cleanPath, 9);
    }
    if (!str_starts_with($cleanPath, 'uploads/dishes/') || str_contains($cleanPath, '..')) {
        return;
    }
    $filename = basename($cleanPath);
    if ($filename === '.htaccess' || empty($filename)) {
        return;
    }
    $uploadDir = realpath(__DIR__ . '/../../uploads/dishes');
    if (!$uploadDir) {
        return;
    }
    $filePath = $uploadDir . DIRECTORY_SEPARATOR . $filename;
    $realFilePath = realpath($filePath);
    if ($realFilePath && str_starts_with($realFilePath, $uploadDir) && file_exists($realFilePath)) {
        @unlink($realFilePath);
    }
}

function handleUploadDishImage($pdo) {
    requireStaff($pdo);

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        jsonResponse(['error' => 'No image file uploaded.'], 422);
    }

    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE   => 'Image size exceeds server configuration limit.',
            UPLOAD_ERR_FORM_SIZE  => 'Image size exceeds form limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No image file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload blocked by extension.'
        ];
        jsonResponse(['error' => $errMap[$file['error']] ?? 'Image upload failed.'], 422);
    }

    // 1. File size validation (Max 2MB)
    $maxBytes = 2 * 1024 * 1024;
    if ($file['size'] > $maxBytes || $file['size'] <= 0) {
        jsonResponse(['error' => 'Image size exceeds the 2MB limit.'], 422);
    }

    $tmpPath = $file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        jsonResponse(['error' => 'Invalid upload origin.'], 422);
    }

    // 2. MIME type validation with finfo
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!isset($allowedMimes[$detectedMime])) {
        jsonResponse(['error' => 'Invalid image format. Only JPEG, PNG, and WebP are allowed.'], 422);
    }

    // 3. Binary header & dimension validation with getimagesize
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        jsonResponse(['error' => 'Corrupt or invalid image file.'], 422);
    }

    if (!isset($imageInfo['mime']) || !isset($allowedMimes[$imageInfo['mime']])) {
        jsonResponse(['error' => 'Image metadata does not match allowed image formats.'], 422);
    }

    // 4. Generate random hex filename
    $ext = $allowedMimes[$detectedMime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    // 5. Ensure uploads directory exists
    $uploadDir = realpath(__DIR__ . '/../../uploads/dishes');
    if (!$uploadDir) {
        $candidate = __DIR__ . '/../../uploads/dishes';
        if (!is_dir($candidate)) {
            mkdir($candidate, 0755, true);
        }
        $uploadDir = realpath($candidate);
    }
    if (!$uploadDir) {
        jsonResponse(['error' => 'Failed to resolve upload destination directory.'], 500);
    }

    $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        jsonResponse(['error' => 'Failed to store uploaded image.'], 500);
    }

    jsonResponse([
        'message' => 'Image uploaded successfully',
        'image_url' => 'uploads/dishes/' . $filename
    ]);
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
    $basePrice = (float)$input['base_price'];
    $priceFloor = isset($input['price_floor']) ? (float)$input['price_floor'] : $basePrice;

    if ($priceFloor < 0 || $priceFloor > $basePrice) {
        jsonResponse(['error' => "Price floor cannot be negative and cannot exceed the base price of ₱{$basePrice}."], 422);
    }

    // Validate image_url strictly if provided
    $imageUrl = null;
    if (isset($input['image_url']) && trim($input['image_url']) !== '') {
        $rawUrl = trim($input['image_url']);
        $cleanUrl = ltrim($rawUrl, '/');
        if (str_starts_with($cleanUrl, 'optimeal/')) {
            $cleanUrl = substr($cleanUrl, 9);
        }
        if (!str_starts_with($cleanUrl, 'uploads/dishes/') || str_contains($cleanUrl, '..')) {
            jsonResponse(['error' => 'Invalid image URL: must be stored in uploads/dishes/'], 422);
        }
        $imageUrl = 'uploads/dishes/' . basename($cleanUrl);
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
            $oldDishStmt = $pdo->prepare("SELECT image_url FROM menu_items WHERE id = ? AND store_id = ?");
            $oldDishStmt->execute([$dishId, $store_id]);
            $oldImageUrl = $oldDishStmt->fetchColumn();
            if ($oldImageUrl && $oldImageUrl !== $imageUrl) {
                deleteDishImageSafely($oldImageUrl);
            }

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
                $imageUrl,
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
                $imageUrl,
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
        error_log("handleSaveDish error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to save dish due to an internal server error.'], 500);
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
        error_log("handleLaunchMenu error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to launch menu due to an internal server error.'], 500);
    }
}

/**
 * R1: Computes today's active reservations (status 'reserved' or 'paid') for a vendor's store,
 * grouped by menu_item_id and pickup_time slot (ordered sequentially earliest first).
 */
function getStoreTodayReservationsMap($pdo, $store_id) {
    $stmt = $pdo->prepare("
        SELECT oi.menu_item_id,
               COALESCE(NULLIF(TRIM(o.pickup_time), ''), 'Dine-In/Walk-in') AS slot,
               COUNT(DISTINCT o.id) AS order_count,
               SUM(oi.quantity) AS total_qty
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.store_id = ?
          AND o.status IN ('reserved', 'paid')
          AND DATE(COALESCE(o.reserved_at, NOW())) = CURDATE()
        GROUP BY oi.menu_item_id, slot
    ");
    $stmt->execute([$store_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $r) {
        $mid = (int)$r['menu_item_id'];
        if (!isset($map[$mid])) {
            $map[$mid] = [
                'reserved_qty' => 0,
                'pickup_slots' => [],
                'slots_summary' => ''
            ];
        }
        $qty = (int)$r['total_qty'];
        $orderCount = (int)$r['order_count'];
        $slotName = $r['slot'];

        $map[$mid]['reserved_qty'] += $qty;
        $map[$mid]['pickup_slots'][] = [
            'slot' => $slotName,
            'orders' => $orderCount,
            'qty' => $qty
        ];
    }

    foreach ($map as $mid => &$data) {
        // Sort sequentially: time formats (e.g. '11:30', '14:15') earliest first, then non-time slots like 'Dine-In/Walk-in'
        usort($data['pickup_slots'], function ($a, $b) {
            $aSlot = $a['slot'];
            $bSlot = $b['slot'];
            $aIsTime = preg_match('/^\d{1,2}:\d{2}/', $aSlot);
            $bIsTime = preg_match('/^\d{1,2}:\d{2}/', $bSlot);
            if ($aIsTime && $bIsTime) {
                return strcmp($aSlot, $bSlot);
            }
            if ($aIsTime && !$bIsTime) return -1;
            if (!$aIsTime && $bIsTime) return 1;
            return strcmp($aSlot, $bSlot);
        });

        $summaryParts = [];
        foreach ($data['pickup_slots'] as $s) {
            $summaryParts[] = $s['slot'] . ' - ' . $s['orders'] . ' ' . ($s['orders'] === 1 ? 'order' : 'orders');
        }
        $data['slots_summary'] = implode(', ', $summaryParts);
    }
    unset($data);

    return $map;
}

function handleGetVendorMenu($pdo) {
    $store_id = requireVendor($pdo);
    $reservationsMap = getStoreTodayReservationsMap($pdo, $store_id);

    $stmt = $pdo->prepare("
        SELECT m.id, m.store_id, m.name, m.description, m.category, m.dietary, 
               m.base_price, m.price_floor, m.image_url, m.calories, m.protein_g, 
               m.carbs_g, m.fat_g, m.sodium_mg, m.ai_confirmed, m.is_active,
               m.hold_hours, m.refrigerated_hours, m.reheat_hours, m.reheatable,
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

    // Fetch and group active batches (remaining_stock > 0 and state != 'discarded') under menu_item_id
    $batchStmt = $pdo->prepare("
        SELECT b.id, b.menu_item_id, b.parent_batch_id, b.state, b.cooked_at, b.cooled_at,
               b.reheated_at, b.hold_hours, b.refrigerated_hours, b.reheat_hours, b.sell_by,
               b.total_stock, b.remaining_stock, b.held_stock, b.reconciliation_offset, b.created_at
        FROM batches b
        JOIN menu_items m ON b.menu_item_id = m.id
        WHERE m.store_id = ? AND b.remaining_stock > 0 AND b.state != 'discarded'
        ORDER BY b.cooked_at ASC, b.id ASC
    ");
    $batchStmt->execute([$store_id]);
    $allBatches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

    $batchesByMenuItem = [];
    foreach ($allBatches as $b) {
        $mId = (int)$b['menu_item_id'];
        if (!isset($batchesByMenuItem[$mId])) {
            $batchesByMenuItem[$mId] = [];
        }
        $batchesByMenuItem[$mId][] = [
            'id' => (int)$b['id'],
            'menu_item_id' => $mId,
            'parent_batch_id' => $b['parent_batch_id'] !== null ? (int)$b['parent_batch_id'] : null,
            'state' => $b['state'],
            'cooked_at' => $b['cooked_at'],
            'cooled_at' => $b['cooled_at'],
            'reheated_at' => $b['reheated_at'],
            'hold_hours' => (int)$b['hold_hours'],
            'refrigerated_hours' => (int)$b['refrigerated_hours'],
            'reheat_hours' => (int)$b['reheat_hours'],
            'sell_by' => $b['sell_by'],
            'total_stock' => (int)$b['total_stock'],
            'remaining_stock' => (int)$b['remaining_stock'],
            'held_stock' => (int)($b['held_stock'] ?? 0),
            'reconciliation_offset' => (int)($b['reconciliation_offset'] ?? 0),
            'available_stock' => max(0, (int)$b['remaining_stock'] - (int)($b['held_stock'] ?? 0)),
            'created_at' => $b['created_at']
        ];
    }

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

        $resData = $reservationsMap[$item['id']] ?? [
            'reserved_qty' => 0,
            'pickup_slots' => [],
            'slots_summary' => ''
        ];

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
            'macros' => $macros,
            'reserved_qty' => (int)$resData['reserved_qty'],
            'pickup_slots' => $resData['pickup_slots'],
            'slots_summary' => $resData['slots_summary'],
            'hold_hours' => isset($item['hold_hours']) ? (int)$item['hold_hours'] : 6,
            'refrigerated_hours' => isset($item['refrigerated_hours']) ? (int)$item['refrigerated_hours'] : 72,
            'reheat_hours' => isset($item['reheat_hours']) ? (int)$item['reheat_hours'] : 3,
            'reheatable' => isset($item['reheatable']) ? (bool)$item['reheatable'] : true,
            'batches' => $batchesByMenuItem[(int)$item['id']] ?? []
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

    if ($tier2Discount < $tier1Discount) {
        jsonResponse(['error' => 'Tier 2 discount percent must be greater than or equal to Tier 1 discount percent.'], 422);
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
    $stmtSettings = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settingsRow = $stmtSettings->fetch(PDO::FETCH_ASSOC) ?: [];
    $openingTime = !empty($settingsRow['opening_time']) ? trim($settingsRow['opening_time']) : '07:00';
    $closingTime = PricingEngine::getEffectiveClosingTime($settingsRow);

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

function getAffectedDishesWithSuggestedFloor($pdo, $store_id, array $ingredientNames) {
    if (empty($ingredientNames)) return [];

    $placeholders = implode(',', array_fill(0, count($ingredientNames), '?'));
    $params = array_merge([$store_id], array_map('strtolower', array_map('trim', $ingredientNames)));

    $sql = "
        SELECT DISTINCT m.id, m.name, m.base_price, m.price_floor, r.id as recipe_id, r.yield_servings
        FROM menu_items m
        JOIN recipes r ON m.id = r.menu_item_id
        JOIN recipe_ingredients ri ON r.id = ri.recipe_id
        WHERE m.store_id = ?
          AND LOWER(TRIM(ri.ingredient_name)) IN ($placeholders)
        ORDER BY m.name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $dishes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load store's latest ingredient costs
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

    $affected = [];
    foreach ($dishes as $d) {
        $stmtIngs = $pdo->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ?");
        $stmtIngs->execute([(int)$d['recipe_id']]);
        $recipeIngredients = $stmtIngs->fetchAll(PDO::FETCH_ASSOC);

        $totalBatchCost = 0.0;
        foreach ($recipeIngredients as $ri) {
            $matchedCost = null;
            if (!empty($ri['ingredient_id']) && isset($costMapById[(int)$ri['ingredient_id']])) {
                $matchedCost = $costMapById[(int)$ri['ingredient_id']];
            } else {
                $clean = strtolower(trim($ri['ingredient_name']));
                if (isset($costMapByName[$clean])) {
                    $matchedCost = $costMapByName[$clean];
                }
            }
            if ($matchedCost) {
                $costRes = calculateIngredientItemCost((float)$ri['quantity'], $ri['unit'], (float)$matchedCost['cost'], $matchedCost['cost_unit']);
                if ($costRes['cost'] !== null) {
                    $totalBatchCost += $costRes['cost'];
                }
            }
        }

        $yield = max(1, (int)$d['yield_servings']);
        $costPerServing = $totalBatchCost / $yield;
        $multiplier = defined('FLOOR_COST_MULTIPLIER') ? FLOOR_COST_MULTIPLIER : 1.2;
        $rawFloor = round($costPerServing * $multiplier, 2);
        $basePrice = (float)$d['base_price'];
        $suggestedFloor = min($basePrice, $rawFloor);

        $affected[] = [
            'menu_item_id' => (int)$d['id'],
            'name' => $d['name'],
            'base_price' => $basePrice,
            'current_price_floor' => (float)$d['price_floor'],
            'cost_per_serving' => round($costPerServing, 2),
            'suggested_price_floor' => $suggestedFloor
        ];
    }

    return $affected;
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
    $findExistingStmt = $pdo->prepare("SELECT cost, cost_unit FROM vendor_ingredient_costs WHERE store_id = ? AND LOWER(ingredient_name) = LOWER(?) LIMIT 1");
    $upsertStmt = $pdo->prepare("
        INSERT INTO vendor_ingredient_costs (store_id, ingredient_id, ingredient_name, cost, cost_unit)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ingredient_id = VALUES(ingredient_id),
            cost = VALUES(cost),
            cost_unit = VALUES(cost_unit),
            updated_at = CURRENT_TIMESTAMP
    ");
    $historyStmt = $pdo->prepare("
        INSERT INTO ingredient_price_history (store_id, ingredient_name, old_cost, new_cost, cost_unit, action, batch_id)
        VALUES (?, ?, ?, ?, ?, 'manual', NULL)
    ");

    $saved = [];
    $changedNames = [];

    foreach ($items as $item) {
        $name = trim($item['ingredient_name'] ?? $item['name'] ?? '');
        if ($name === '') continue;
        $cost = max(0, (float)($item['cost'] ?? 0));
        $unit = trim($item['cost_unit'] ?? $item['unit'] ?? 'kg');

        $findTaxStmt->execute([$name]);
        $ingId = $findTaxStmt->fetchColumn() ?: null;

        $findExistingStmt->execute([$store_id, $name]);
        $existing = $findExistingStmt->fetch(PDO::FETCH_ASSOC);

        $oldCost = $existing ? (float)$existing['cost'] : 0.00;

        $upsertStmt->execute([$store_id, $ingId, $name, $cost, $unit]);

        // Log manual history if price changed or first time creation
        if (!$existing || abs($oldCost - $cost) > 0.001) {
            $historyStmt->execute([$store_id, $name, $oldCost, $cost, $unit]);
            $changedNames[] = $name;
        }

        $saved[] = [
            'store_id' => $store_id,
            'ingredient_id' => $ingId,
            'ingredient_name' => $name,
            'cost' => $cost,
            'cost_unit' => $unit
        ];
    }

    $affectedDishes = getAffectedDishesWithSuggestedFloor($pdo, $store_id, $changedNames);

    jsonResponse([
        'message' => 'Ingredient costs saved',
        'count' => count($saved),
        'saved' => $saved,
        'affected_dishes' => $affectedDishes
    ]);
}

function handleBulkPreviewIngredientCosts($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['percent']) && !isset($input['percentage'])) {
        jsonResponse(['error' => 'Percentage adjustment is required.'], 422);
    }
    $percent = (float)($input['percent'] ?? $input['percentage']);

    if ($percent < -50 || $percent > 100) {
        jsonResponse(['error' => 'Percentage adjustment must be strictly between -50% and +100%.'], 422);
    }

    $scope = $input['scope'] ?? 'all';
    if ($scope === 'selected') {
        $rawIds = $input['ingredient_ids'] ?? ($input['selected_ids'] ?? []);
        if (!is_array($rawIds) || empty($rawIds)) {
            jsonResponse(['error' => 'No ingredients selected for adjustment.'], 422);
        }
        $selectedIds = array_values(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0));
        if (empty($selectedIds)) {
            jsonResponse(['error' => 'No valid ingredient IDs provided.'], 422);
        }
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ? AND id IN ($inPlaceholders) ORDER BY ingredient_name ASC");
        $stmt->execute(array_merge([$store_id], $selectedIds));
    } else {
        $stmt = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ? ORDER BY ingredient_name ASC");
        $stmt->execute([$store_id]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        jsonResponse(['error' => 'No ingredients found in catalog matching the selected criteria.'], 422);
    }

    $offendingIngredients = [];
    $calculations = [];
    foreach ($items as $it) {
        $oldCost = (float)$it['cost'];
        $newCost = round($oldCost * (1 + $percent / 100), 2);
        if ($newCost <= 0) {
            $offendingIngredients[] = $it['ingredient_name'] . " (calculated ₱" . number_format($newCost, 2) . " from ₱" . number_format($oldCost, 2) . ")";
        }
        $calculations[] = [
            'item' => $it,
            'oldCost' => $oldCost,
            'newCost' => $newCost
        ];
    }

    if (!empty($offendingIngredients)) {
        $names = implode(', ', $offendingIngredients);
        jsonResponse([
            'error' => "Adjustment rejected: newly calculated cost would drop to zero or negative for: $names.",
            'offending_ingredients' => $offendingIngredients
        ], 422);
    }

    $preview = [];
    foreach ($calculations as $calc) {
        $it = $calc['item'];
        $oldCost = $calc['oldCost'];
        $newCost = $calc['newCost'];
        $preview[] = [
            'id' => (int)$it['id'],
            'ingredient_name' => $it['ingredient_name'],
            'old_cost' => $oldCost,
            'current_cost' => $oldCost,
            'new_cost' => $newCost,
            'diff' => round($newCost - $oldCost, 2),
            'cost_unit' => $it['cost_unit']
        ];
    }

    jsonResponse([
        'percent' => $percent,
        'scope' => $scope,
        'count' => count($preview),
        'items' => $preview,
        'preview' => $preview
    ]);
}

function handleBulkApplyIngredientCosts($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['percent']) && !isset($input['percentage'])) {
        jsonResponse(['error' => 'Percentage adjustment is required.'], 422);
    }
    $percent = (float)($input['percent'] ?? $input['percentage']);

    if ($percent < -50 || $percent > 100) {
        jsonResponse(['error' => 'Percentage adjustment must be strictly between -50% and +100%.'], 422);
    }

    $scope = $input['scope'] ?? 'all';
    if ($scope === 'selected') {
        $rawIds = $input['ingredient_ids'] ?? ($input['selected_ids'] ?? []);
        if (!is_array($rawIds) || empty($rawIds)) {
            jsonResponse(['error' => 'No ingredients selected for adjustment.'], 422);
        }
        $selectedIds = array_values(array_filter(array_map('intval', $rawIds), fn($id) => $id > 0));
        if (empty($selectedIds)) {
            jsonResponse(['error' => 'No valid ingredient IDs provided.'], 422);
        }
        $inPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ? AND id IN ($inPlaceholders) ORDER BY ingredient_name ASC");
        $stmt->execute(array_merge([$store_id], $selectedIds));
    } else {
        $stmt = $pdo->prepare("SELECT * FROM vendor_ingredient_costs WHERE store_id = ? ORDER BY ingredient_name ASC");
        $stmt->execute([$store_id]);
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        jsonResponse(['error' => 'No ingredients found in catalog matching the selected criteria.'], 422);
    }

    $offendingIngredients = [];
    $calculations = [];
    foreach ($items as $it) {
        $oldCost = (float)$it['cost'];
        $newCost = round($oldCost * (1 + $percent / 100), 2);
        if ($newCost <= 0) {
            $offendingIngredients[] = $it['ingredient_name'] . " (calculated ₱" . number_format($newCost, 2) . " from ₱" . number_format($oldCost, 2) . ")";
        }
        $calculations[] = [
            'item' => $it,
            'oldCost' => $oldCost,
            'newCost' => $newCost
        ];
    }

    if (!empty($offendingIngredients)) {
        $names = implode(', ', $offendingIngredients);
        jsonResponse([
            'error' => "Adjustment rejected: newly calculated cost would drop to zero or negative for: $names.",
            'offending_ingredients' => $offendingIngredients
        ], 422);
    }

    $batchId = 'batch_' . bin2hex(random_bytes(8));
    $updateStmt = $pdo->prepare("UPDATE vendor_ingredient_costs SET cost = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $historyStmt = $pdo->prepare("
        INSERT INTO ingredient_price_history (store_id, ingredient_name, old_cost, new_cost, cost_unit, action, batch_id)
        VALUES (?, ?, ?, ?, ?, 'bulk', ?)
    ");

    $pdo->beginTransaction();
    try {
        $updated = [];
        $changedNames = [];
        foreach ($calculations as $calc) {
            $it = $calc['item'];
            $oldCost = $calc['oldCost'];
            $newCost = $calc['newCost'];
            $updateStmt->execute([$newCost, (int)$it['id']]);
            $historyStmt->execute([$store_id, $it['ingredient_name'], $oldCost, $newCost, $it['cost_unit'], $batchId]);

            $updated[] = [
                'ingredient_name' => $it['ingredient_name'],
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'cost_unit' => $it['cost_unit']
            ];
            $changedNames[] = $it['ingredient_name'];
        }
        $pdo->commit();

        $affectedDishes = getAffectedDishesWithSuggestedFloor($pdo, $store_id, $changedNames);

        jsonResponse([
            'message' => "Bulk price adjustment of {$percent}% applied successfully",
            'batch_id' => $batchId,
            'scope' => $scope,
            'count' => count($updated),
            'affected_count' => count($updated),
            'updated' => $updated,
            'affected_dishes' => $affectedDishes
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Bulk price apply error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to apply bulk price adjustment due to an internal server error.'], 500);
    }
}

function handleGetIngredientPriceHistory($pdo) {
    $store_id = requireVendor($pdo);
    $stmt = $pdo->prepare("
        SELECT id, store_id, ingredient_name, old_cost, new_cost, cost_unit, action, batch_id, is_rolled_back, created_at
        FROM ingredient_price_history
        WHERE store_id = ?
        ORDER BY id DESC
        LIMIT 200
    ");
    $stmt->execute([$store_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse($history);
}

function handleRollbackIngredientCosts($pdo) {
    $store_id = requireVendor($pdo);
    $input = json_decode(file_get_contents('php://input'), true);

    $batchId = trim($input['batch_id'] ?? '');
    $historyId = (int)($input['history_id'] ?? 0);

    if (empty($batchId) && $historyId <= 0) {
        jsonResponse(['error' => 'batch_id or history_id is required for rollback'], 422);
    }

    $pdo->beginTransaction();
    try {
        $records = [];
        if (!empty($batchId)) {
            $stmt = $pdo->prepare("SELECT * FROM ingredient_price_history WHERE store_id = ? AND batch_id = ? AND action = 'bulk'");
            $stmt->execute([$store_id, $batchId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM ingredient_price_history WHERE store_id = ? AND id = ?");
            $stmt->execute([$store_id, $historyId]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($records)) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Price history record not found'], 404);
        }

        // Double-rollback defense: reject if already rolled back
        foreach ($records as $r) {
            if ((int)$r['is_rolled_back'] === 1) {
                $pdo->rollBack();
                jsonResponse(['error' => 'This change has already been rolled back.'], 422);
            }
        }

        // Critical edge-case defense:
        // Ensure rolling back an older adjustment/batch does NOT corrupt subsequent valid adjustments.
        // If an ingredient has a subsequent active adjustment, fail gracefully with an informative error.
        $recordIds = array_map(fn($rec) => (int)$rec['id'], $records);
        $inIds = implode(',', array_fill(0, count($recordIds), '?'));

        foreach ($records as $r) {
            $query = "
                SELECT id, batch_id, created_at, action, new_cost
                FROM ingredient_price_history
                WHERE store_id = ?
                  AND LOWER(ingredient_name) = LOWER(?)
                  AND id > ?
                  AND is_rolled_back = 0
                  AND action != 'rollback'
                  AND id NOT IN ($inIds)
                ORDER BY id DESC
                LIMIT 1
            ";
            $params = array_merge([$store_id, $r['ingredient_name'], (int)$r['id']], $recordIds);
            $newerStmt = $pdo->prepare($query);
            $newerStmt->execute($params);
            $newer = $newerStmt->fetch(PDO::FETCH_ASSOC);

            if ($newer) {
                $pdo->rollBack();
                $actionLabel = !empty($newer['batch_id']) ? "batch '{$newer['batch_id']}'" : "adjustment #{$newer['id']}";
                jsonResponse([
                    'error' => "Cannot roll back change: ingredient '{$r['ingredient_name']}' has a subsequent active price adjustment ({$actionLabel}). Non-contiguous rollbacks are not allowed; please roll back newer adjustments first."
                ], 422);
            }
        }

        $rollbackBatchId = 'rollback_' . bin2hex(random_bytes(8));
        $updateCostStmt = $pdo->prepare("UPDATE vendor_ingredient_costs SET cost = ?, updated_at = CURRENT_TIMESTAMP WHERE store_id = ? AND LOWER(ingredient_name) = LOWER(?)");
        $markRolledBackStmt = $pdo->prepare("UPDATE ingredient_price_history SET is_rolled_back = 1 WHERE id = ?");
        $logRollbackStmt = $pdo->prepare("
            INSERT INTO ingredient_price_history (store_id, ingredient_name, old_cost, new_cost, cost_unit, action, batch_id, is_rolled_back)
            VALUES (?, ?, ?, ?, ?, 'rollback', ?, 0)
        ");

        $changedNames = [];
        foreach ($records as $r) {
            $revertCost = (float)$r['old_cost'];
            $currentCost = (float)$r['new_cost'];

            $updateCostStmt->execute([$revertCost, $store_id, $r['ingredient_name']]);
            $markRolledBackStmt->execute([(int)$r['id']]);
            $logRollbackStmt->execute([$store_id, $r['ingredient_name'], $currentCost, $revertCost, $r['cost_unit'], $rollbackBatchId]);

            $changedNames[] = $r['ingredient_name'];
        }

        $pdo->commit();

        $affectedDishes = getAffectedDishesWithSuggestedFloor($pdo, $store_id, $changedNames);

        jsonResponse([
            'message' => 'Rollback completed successfully',
            'rolled_back_count' => count($records),
            'rollback_batch_id' => $rollbackBatchId,
            'affected_dishes' => $affectedDishes
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Rollback error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to rollback price changes due to an internal server error.'], 500);
    }
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
            'margin_multiplier' => defined('FLOOR_COST_MULTIPLIER') ? FLOOR_COST_MULTIPLIER : 1.2
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
    $multiplier = defined('FLOOR_COST_MULTIPLIER') ? FLOOR_COST_MULTIPLIER : 1.2;
    $suggestedPriceFloor = min((float)$dish['base_price'], round($costPerServing * $multiplier, 2));
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
        'margin_multiplier' => $multiplier
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
        error_log("handleSaveRecipe error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to save recipe due to an internal server error.'], 500);
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

    $cacheKey = 'recipe_suggest_' . md5(strtolower(trim($dishName)) . '|' . json_encode($existingIngredientsList));

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
    $reservationsMap = getStoreTodayReservationsMap($pdo, $store_id);

    // Query store ingredient costs
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
    $totalCookServings = 0;

    foreach ($requestedItems as $req) {
        $menuItemId = (int)($req['menu_item_id'] ?? 0);
        $plannedServings = min(1000, max(1, (int)($req['servings'] ?? 10)));
        if ($menuItemId <= 0) continue;

        $stmtDish = $pdo->prepare("SELECT id, name FROM menu_items WHERE id = ? AND store_id = ?");
        $stmtDish->execute([$menuItemId, $store_id]);
        $dish = $stmtDish->fetch(PDO::FETCH_ASSOC);
        if (!$dish) continue;

        $resData = $reservationsMap[$menuItemId] ?? [
            'reserved_qty' => 0,
            'pickup_slots' => [],
            'slots_summary' => ''
        ];
        $reservedQty = (int)$resData['reserved_qty'];
        $cookServings = max($plannedServings, $reservedQty);

        $stmtRecipe = $pdo->prepare("SELECT * FROM recipes WHERE menu_item_id = ?");
        $stmtRecipe->execute([$menuItemId]);
        $recipe = $stmtRecipe->fetch(PDO::FETCH_ASSOC);

        if (!$recipe) {
            // M4: Do not fabricate "1 pc" for dishes without a recipe. Omit their math and append to needs_recipe.
            $needsRecipe[] = [
                'menu_item_id' => $menuItemId,
                'name' => $dish['name'],
                'planned_servings' => $plannedServings,
                'reserved_qty' => $reservedQty,
                'cook_servings' => $cookServings,
                'pickup_slots' => $resData['pickup_slots'],
                'slots_summary' => $resData['slots_summary']
            ];
            continue;
        }

        $totalPlannedServings += $plannedServings;
        $totalCookServings += $cookServings;
        $yieldServings = max(1, (int)$recipe['yield_servings']);
        $scaleFactor = (float)$cookServings / (float)$yieldServings;

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
            'reserved_qty' => $reservedQty,
            'cook_servings' => $cookServings,
            'pickup_slots' => $resData['pickup_slots'],
            'slots_summary' => $resData['slots_summary'],
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
                    'ingredient_id' => !empty($ing['ingredient_id']) ? (int)$ing['ingredient_id'] : null,
                    'canonical_name' => !empty($ing['canonical_name']) ? trim($ing['canonical_name']) : null,
                    'dishes' => []
                ];
            } else {
                if (empty($combinedMap[$key]['ingredient_id']) && !empty($ing['ingredient_id'])) {
                    $combinedMap[$key]['ingredient_id'] = (int)$ing['ingredient_id'];
                }
                if (empty($combinedMap[$key]['canonical_name']) && !empty($ing['canonical_name'])) {
                    $combinedMap[$key]['canonical_name'] = trim($ing['canonical_name']);
                }
            }

            $combinedMap[$key]['total_base_qty'] += $baseQty;
            if ($isUnmapped) {
                $combinedMap[$key]['is_unmapped'] = true;
            }
            $combinedMap[$key]['dishes'][] = [
                'dish_name' => $dish['name'],
                'servings' => $cookServings,
                'planned_servings' => $plannedServings,
                'scaled_qty' => round($scaledQty, 2),
                'unit' => $unit
            ];
        }
    }

    $prepList = [];
    $grandTotal = 0.0;
    $costedCount = 0;
    $hasUnpricedIngredient = false;

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

        // Cost calculation using vendor_ingredient_costs
        $matchedCost = null;
        if (!empty($item['ingredient_id']) && isset($costMapById[$item['ingredient_id']])) {
            $matchedCost = $costMapById[$item['ingredient_id']];
        } elseif (isset($costMapByName[strtolower(trim($item['name']))])) {
            $matchedCost = $costMapByName[strtolower(trim($item['name']))];
        } elseif (!empty($item['canonical_name']) && isset($costMapByName[strtolower(trim($item['canonical_name']))])) {
            $matchedCost = $costMapByName[strtolower(trim($item['canonical_name']))];
        }

        $estimatedCost = null;
        $hasPrice = false;
        $unitPrice = null;
        $costUnit = null;

        if ($matchedCost && (float)$matchedCost['cost'] > 0) {
            $costCalc = calculateIngredientItemCost($finalQty, $finalUnit, (float)$matchedCost['cost'], $matchedCost['cost_unit']);
            if ($costCalc['cost'] !== null) {
                $estimatedCost = round((float)$costCalc['cost'], 2);
                $hasPrice = true;
                $unitPrice = (float)$matchedCost['cost'];
                $costUnit = $matchedCost['cost_unit'];
                $grandTotal += $estimatedCost;
                $costedCount++;
            }
        }

        if (!$hasPrice) {
            $hasUnpricedIngredient = true;
        }

        $prepList[] = [
            'name' => $item['name'],
            'total_quantity' => $finalQty,
            'unit' => $finalUnit,
            'dimension' => $item['dimension'],
            'is_unmapped' => (bool)$item['is_unmapped'],
            'used_in_dishes' => $dishNames,
            'dish_count' => count($dishNames),
            'estimated_cost' => $estimatedCost,
            'has_price' => $hasPrice,
            'unit_price' => $unitPrice,
            'cost_unit' => $costUnit,
            'breakdown' => $item['dishes']
        ];
    }

    usort($prepList, function($a, $b) {
        if ($a['is_unmapped'] !== $b['is_unmapped']) {
            return $b['is_unmapped'] <=> $a['is_unmapped'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $totalIngredients = count($prepList);
    $isPartial = ($totalIngredients > 0 && ($costedCount < $totalIngredients || $hasUnpricedIngredient));
    $coverageLabel = "{$costedCount} of {$totalIngredients} ingredients costed";

    jsonResponse([
        'total_dishes' => count($dishesBreakdown),
        'total_servings' => $totalCookServings,
        'total_planned_servings' => $totalPlannedServings,
        'total_cook_servings' => $totalCookServings,
        'dishes' => $dishesBreakdown,
        'needs_recipe' => $needsRecipe,
        'prep_list' => $prepList,
        'grand_total' => round($grandTotal, 2),
        'is_partial' => (bool)$isPartial,
        'costed_count' => $costedCount,
        'total_ingredients' => $totalIngredients,
        'coverage_label' => $coverageLabel
    ]);
}

function handleGetCafeteria($pdo)
{
    $stmt = $pdo->query("SELECT * FROM cafeteria WHERE id = 1");
    $cafeteria = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cafeteria) {
        jsonResponse(['error' => 'Cafeteria record not found'], 404);
        return;
    }

    $cafeteria['id'] = (int)$cafeteria['id'];
    if (isset($cafeteria['pickup_slot_capacity'])) {
        $cafeteria['pickup_slot_capacity'] = (int)$cafeteria['pickup_slot_capacity'];
    }
    if (isset($cafeteria['walkin_buffer_pct'])) {
        $cafeteria['walkin_buffer_pct'] = (int)$cafeteria['walkin_buffer_pct'];
    }
    if (isset($cafeteria['default_quota'])) {
        $cafeteria['default_quota'] = (int)$cafeteria['default_quota'];
    }

    jsonResponse($cafeteria);
}

function handlePatchCafeteria($pdo, $input = null)
{
    if ($input === null) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }

    $allowedKeys = [
        'tagline',
        'contact_email',
        'contact_number',
        'lunch_cutoff',
        'dinner_cutoff',
        'pickup_slot_capacity',
        'walkin_buffer_pct',
        'default_quota'
    ];

    $updates = [];
    $params = [];

    foreach ($input as $key => $value) {
        if (in_array($key, $allowedKeys, true)) {
            $updates[] = "`$key` = ?";
            if (in_array($key, ['pickup_slot_capacity', 'walkin_buffer_pct', 'default_quota'], true)) {
                $params[] = $value !== null && $value !== '' ? (int)$value : null;
            } elseif ($value === '') {
                $params[] = null;
            } else {
                $params[] = $value;
            }
        }
    }

    if (!empty($updates)) {
        $sql = "UPDATE cafeteria SET " . implode(', ', $updates) . " WHERE id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    jsonResponse(['status' => 'success']);
}

function handleGetBatches($pdo)
{
    $store_id = requireVendor($pdo);
    $menuItemId = isset($_GET['menu_item_id']) ? (int)$_GET['menu_item_id'] : null;

    if ($menuItemId) {
        $stmt = $pdo->prepare("
            SELECT b.*, m.name as dish_name, m.reheatable, m.reheat_hours, m.hold_hours
            FROM batches b
            JOIN menu_items m ON b.menu_item_id = m.id
            WHERE m.store_id = ? AND b.menu_item_id = ? AND b.state != 'discarded'
            ORDER BY b.id DESC
        ");
        $stmt->execute([$store_id, $menuItemId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT b.*, m.name as dish_name, m.reheatable, m.reheat_hours, m.hold_hours
            FROM batches b
            JOIN menu_items m ON b.menu_item_id = m.id
            WHERE m.store_id = ? AND b.state != 'discarded'
            ORDER BY b.id DESC
        ");
        $stmt->execute([$store_id]);
    }

    jsonResponse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleCreateBatch($pdo, $input = null)
{
    $store_id = requireVendor($pdo);

    if ($input === null) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }

    if (empty($input['menu_item_id'])) {
        jsonResponse(['error' => 'Missing menu_item_id'], 400);
        return;
    }

    $menuItemId = (int)$input['menu_item_id'];
    $state = !empty($input['state']) ? $input['state'] : 'fresh';
    $quantity = isset($input['quantity']) ? (int)$input['quantity'] : 0;
    $parentBatchId = !empty($input['parent_batch_id']) ? (int)$input['parent_batch_id'] : null;

    if (!in_array($state, ['fresh', 'reheated'], true)) {
        jsonResponse(['error' => "Invalid batch state. Allowed states: 'fresh', 'reheated'"], 400);
        return;
    }

    if ($quantity <= 0) {
        jsonResponse(['error' => 'Quantity must be greater than zero'], 400);
        return;
    }

    // Authz & dish existence check
    $stmtDish = $pdo->prepare("
        SELECT id, name, store_id, hold_hours, refrigerated_hours, reheat_hours, reheatable, is_active
        FROM menu_items
        WHERE id = ? AND store_id = ?
    ");
    $stmtDish->execute([$menuItemId, $store_id]);
    $dish = $stmtDish->fetch(PDO::FETCH_ASSOC);

    if (!$dish) {
        jsonResponse(['error' => 'Dish not found or does not belong to your store'], 404);
        return;
    }

    date_default_timezone_set('Asia/Manila');
    $nowManila = date('Y-m-d H:i:s');
    $holdHours = (int)($dish['hold_hours'] ?? 6);
    $refrigHours = (int)($dish['refrigerated_hours'] ?? 72);
    $reheatHours = (int)($dish['reheat_hours'] ?? 3);
    $reheatable = (bool)($dish['reheatable'] ?? 1);

    try {
        $pdo->beginTransaction();

        $newBatchId = null;

        if ($state === 'fresh') {
            $cookedAt = $nowManila;
            $sellBy = date('Y-m-d H:i:s', strtotime("+$holdHours hours", strtotime($cookedAt)));

            $stmtInsert = $pdo->prepare("
                INSERT INTO batches (
                    menu_item_id, parent_batch_id, state, cooked_at,
                    hold_hours, refrigerated_hours, reheat_hours, sell_by,
                    total_stock, remaining_stock
                ) VALUES (?, NULL, 'fresh', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $menuItemId,
                $cookedAt,
                $holdHours,
                $refrigHours,
                $reheatHours,
                $sellBy,
                $quantity,
                $quantity
            ]);
            $newBatchId = (int)$pdo->lastInsertId();

            // Sync with inventory pool
            $stmtInv = $pdo->prepare("
                INSERT INTO inventory (menu_item_id, online_pool_qty, walkin_pool_qty, critical_stock_threshold, delisted_from_app, cooked_at, sell_by)
                VALUES (?, ?, 0, 2, 0, ?, ?)
                ON DUPLICATE KEY UPDATE
                    online_pool_qty = online_pool_qty + VALUES(online_pool_qty),
                    delisted_from_app = 0,
                    cooked_at = VALUES(cooked_at),
                    sell_by = VALUES(sell_by)
            ");
            $stmtInv->execute([$menuItemId, $quantity, $cookedAt, $sellBy]);

        } elseif ($state === 'reheated') {
            if (!$reheatable) {
                $pdo->rollBack();
                jsonResponse(['error' => 'Dish is marked as non-reheatable'], 400);
                return;
            }

            if (!$parentBatchId) {
                $pdo->rollBack();
                jsonResponse(['error' => 'parent_batch_id is required for reheated batch'], 400);
                return;
            }

            // Lock and inspect parent batch
            $stmtParent = $pdo->prepare("
                SELECT id, menu_item_id, remaining_stock, state
                FROM batches
                WHERE id = ? AND menu_item_id = ?
                FOR UPDATE
            ");
            $stmtParent->execute([$parentBatchId, $menuItemId]);
            $parent = $stmtParent->fetch(PDO::FETCH_ASSOC);

            if (!$parent) {
                $pdo->rollBack();
                jsonResponse(['error' => 'Parent batch not found for this dish'], 404);
                return;
            }

            if ((int)$parent['remaining_stock'] < $quantity) {
                $pdo->rollBack();
                jsonResponse([
                    'error' => "Insufficient stock in parent batch. Remaining: {$parent['remaining_stock']}, Requested: $quantity"
                ], 400);
                return;
            }

            // Decrement parent remaining stock
            $stmtDec = $pdo->prepare("
                UPDATE batches
                SET remaining_stock = remaining_stock - ?
                WHERE id = ?
            ");
            $stmtDec->execute([$quantity, $parentBatchId]);

            $reheatedAt = $nowManila;
            $sellBy = date('Y-m-d H:i:s', strtotime("+$reheatHours hours", strtotime($reheatedAt)));

            $stmtInsert = $pdo->prepare("
                INSERT INTO batches (
                    menu_item_id, parent_batch_id, state, reheated_at,
                    hold_hours, refrigerated_hours, reheat_hours, sell_by,
                    total_stock, remaining_stock
                ) VALUES (?, ?, 'reheated', ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $menuItemId,
                $parentBatchId,
                $reheatedAt,
                $holdHours,
                $refrigHours,
                $reheatHours,
                $sellBy,
                $quantity,
                $quantity
            ]);
            $newBatchId = (int)$pdo->lastInsertId();

            // Sync with inventory pool
            $stmtInv = $pdo->prepare("
                INSERT INTO inventory (menu_item_id, online_pool_qty, walkin_pool_qty, critical_stock_threshold, delisted_from_app, cooked_at, sell_by)
                VALUES (?, ?, 0, 2, 0, ?, ?)
                ON DUPLICATE KEY UPDATE
                    online_pool_qty = online_pool_qty + VALUES(online_pool_qty),
                    delisted_from_app = 0,
                    sell_by = VALUES(sell_by)
            ");
            $stmtInv->execute([$menuItemId, $quantity, $reheatedAt, $sellBy]);
        }

        // Activate dish if inactive
        $pdo->prepare("UPDATE menu_items SET is_active = 1 WHERE id = ?")->execute([$menuItemId]);

        $pdo->commit();

        // Fetch newly created batch row
        $stmtFetch = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
        $stmtFetch->execute([$newBatchId]);
        $createdBatch = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        jsonResponse([
            'status' => 'success',
            'message' => 'Batch created successfully',
            'batch' => $createdBatch
        ], 201);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'Failed to create batch: ' . $e->getMessage()], 500);
    }
}

function handleDiscardBatch($pdo, $batchId)
{
    $store_id = requireVendor($pdo);
    $batchId = (int)$batchId;
    if ($batchId <= 0) {
        jsonResponse(['error' => 'Invalid batch ID'], 400);
        return;
    }

    try {
        $pdo->beginTransaction();

        // Lock and verify ownership of batch via menu_items
        $stmt = $pdo->prepare("
            SELECT b.id, b.menu_item_id, b.remaining_stock, b.state, COALESCE(b.status, b.state) as status, m.name as dish_name
            FROM batches b
            JOIN menu_items m ON b.menu_item_id = m.id
            WHERE b.id = ? AND m.store_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$batchId, $store_id]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$batch) {
            $pdo->rollBack();
            jsonResponse(['error' => 'Batch not found or does not belong to your store'], 404);
            return;
        }

        if ($batch['state'] === 'discarded' || $batch['status'] === 'discarded') {
            $pdo->rollBack();
            jsonResponse(['error' => 'Batch is already marked as discarded'], 400);
            return;
        }

        $discardedQty = (int)$batch['remaining_stock'];

        // Requirement: set status = 'discarded', setting discarded_qty = remaining_stock, and zeroing out remaining_stock
        $stmtUpdate = $pdo->prepare("
            UPDATE batches
            SET status = 'discarded',
                state = 'discarded',
                discarded_qty = remaining_stock,
                remaining_stock = 0,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmtUpdate->execute([$batchId]);

        // Decrement online_pool_qty in inventory if active
        if ($discardedQty > 0) {
            $stmtInv = $pdo->prepare("
                UPDATE inventory
                SET online_pool_qty = GREATEST(0, online_pool_qty - ?),
                    delisted_from_app = CASE WHEN GREATEST(0, online_pool_qty - ?) <= critical_stock_threshold THEN 1 ELSE delisted_from_app END
                WHERE menu_item_id = ?
            ");
            $stmtInv->execute([$discardedQty, $discardedQty, $batch['menu_item_id']]);
        }

        $pdo->commit();

        jsonResponse([
            'status' => 'success',
            'message' => "Batch #$batchId successfully marked as discarded ({$discardedQty} portions discarded).",
            'batch' => [
                'id' => $batchId,
                'menu_item_id' => (int)$batch['menu_item_id'],
                'dish_name' => $batch['dish_name'],
                'status' => 'discarded',
                'state' => 'discarded',
                'discarded_qty' => $discardedQty,
                'remaining_stock' => 0
            ]
        ], 200);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("handleDiscardBatch error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to discard batch: ' . $e->getMessage()], 500);
    }
}




