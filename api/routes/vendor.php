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

function handleEnrichDish($pdo) {
    // Parse incoming JSON payload
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate presence of name
    if (empty($input['name'])) {
        jsonResponse(['error' => 'Missing or empty dish name'], 400);
    }

    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey || $apiKey === 'your_api_key_here') {
        jsonResponse(['error' => 'Server configuration error: Missing API Key'], 500);
    }

    $dishName = $input['name'];
    $description = isset($input['description']) ? $input['description'] : '';
    $portion = isset($input['portion']) ? $input['portion'] : '';

    $systemInstruction = "You are a culinary data assistant. You MUST respond with ONLY raw JSON. Do NOT wrap the JSON in markdown code blocks (e.g. no ```json). Return an object with two keys: 'ingredients' (an array of strings) and 'macros' (an object with integer keys: calories, protein_g, carbs_g, fat_g, sodium_mg). You must estimate these values based on the dish name and description. CRITICAL RULE: Do NOT include or guess allergens (e.g. do not say 'Peanuts', 'Shellfish', 'Soy', etc.). Just list the core ingredients.";
    
    $userPrompt = "Dish Name: $dishName\nDescription: $description\nPortion: $portion\nGenerate the ingredients and macros JSON.";

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

    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        jsonResponse(['error' => 'AI Service Unavailable'], 502);
    }

    $responseData = json_decode($response, true);
    
    // Extract the text from the Gemini response
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $aiText = $responseData['candidates'][0]['content']['parts'][0]['text'];
        $aiJson = json_decode($aiText, true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($aiJson['ingredients']) && isset($aiJson['macros'])) {
            jsonResponse($aiJson, 200);
        }
    }
    
    jsonResponse(['error' => 'Invalid AI Response Format'], 502);
}
