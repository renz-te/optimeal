<?php

require_once __DIR__ . '/../PricingEngine.php';


function handleGetUsers($pdo)
{
    try {
        $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse($users);
    } catch (PDOException $e) {
        error_log("handleGetUsers error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to fetch users due to an internal server error.'], 500);
    }
}

function handleCreateUser($pdo)
{
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['name'], $input['email'], $input['password'], $input['role'])) {
            jsonResponse(['error' => 'Missing required fields: name, email, password, and role are required.'], 400);
        }

        $name = trim($input['name']);
        $email = trim($input['email']);
        $password = $input['password'];
        $role = strtolower(trim($input['role']));

        // Normalize customer -> student if passed from frontend
        if ($role === 'customer') {
            $role = 'student';
        }

        $allowedRoles = ['student', 'vendor', 'admin'];
        if (!in_array($role, $allowedRoles, true)) {
            jsonResponse(['error' => 'Invalid role. Allowed roles are: student, vendor, admin.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['error' => 'Invalid email address.'], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(['error' => 'Password must be at least 6 characters long.'], 400);
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $email, $passwordHash, $role]);
        $newUserId = (int)$pdo->lastInsertId();

        // If creating a vendor, provision a default store entry if none exists
        if ($role === 'vendor') {
            $stmtStore = $pdo->prepare("INSERT IGNORE INTO stores (vendor_user_id, name) VALUES (?, ?)");
            $stmtStore->execute([$newUserId, $name . ' Stall']);
        }

        jsonResponse([
            'message' => 'User created successfully',
            'user' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => $role
            ]
        ], 201);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            jsonResponse(['error' => 'A user with this email address already exists.'], 409);
        }
        error_log("handleCreateUser PDO error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to create user due to an internal database error.'], 500);
    } catch (Exception $e) {
        error_log("handleCreateUser error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
}

function handleGetTaxonomy($pdo)
{
    try {
        $allergens = $pdo->query("SELECT id, name FROM critical_allergens ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        $sensitivities = [];
        try {
            $sensitivities = $pdo->query("SELECT id, name FROM digestive_sensitivities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
        }

        $stmt = $pdo->query("
            SELECT i.id, i.name, 
                   GROUP_CONCAT(DISTINCT m.allergen_id) as allergen_ids,
                   GROUP_CONCAT(DISTINCT ca.name SEPARATOR '||') as allergen_names
            FROM ingredients i
            LEFT JOIN ingredient_allergen_map m ON i.id = m.ingredient_id
            LEFT JOIN critical_allergens ca ON m.allergen_id = ca.id
            GROUP BY i.id, i.name
            ORDER BY i.name ASC
        ");
        $ingredientsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ingredients = [];
        foreach ($ingredientsRaw as $row) {
            $allergenIds = !empty($row['allergen_ids']) ? array_map('intval', explode(',', $row['allergen_ids'])) : [];
            $allergenNames = !empty($row['allergen_names']) ? explode('||', $row['allergen_names']) : [];
            $ingredients[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'allergen_ids' => $allergenIds,
                'allergens' => $allergenNames
            ];
        }

        jsonResponse([
            'allergens' => $allergens,
            'sensitivities' => $sensitivities,
            'ingredients' => $ingredients
        ]);
    } catch (Exception $e) {
        error_log("handleGetTaxonomy error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to fetch taxonomy due to an internal server error.'], 500);
    }
}

function handleUpdateIngredient($pdo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['name']) || trim($input['name']) === '') {
        jsonResponse(['error' => 'Ingredient name is required.'], 400);
    }

    $name = trim($input['name']);
    $ingredientId = isset($input['id']) ? (int)$input['id'] : 0;
    $allergenIds = isset($input['allergen_ids']) && is_array($input['allergen_ids']) ? $input['allergen_ids'] : [];

    try {
        $pdo->beginTransaction();

        if ($ingredientId > 0) {
            // Update existing ingredient
            $stmt = $pdo->prepare("UPDATE ingredients SET name = ? WHERE id = ?");
            $stmt->execute([$name, $ingredientId]);
        } else {
            // Check if ingredient with this name already exists
            $checkStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?)");
            $checkStmt->execute([$name]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $ingredientId = (int)$existingId;
                $stmt = $pdo->prepare("UPDATE ingredients SET name = ? WHERE id = ?");
                $stmt->execute([$name, $ingredientId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO ingredients (name) VALUES (?)");
                $stmt->execute([$name]);
                $ingredientId = (int)$pdo->lastInsertId();
            }
        }

        // Sync allergen mappings in ingredient_allergen_map
        $delStmt = $pdo->prepare("DELETE FROM ingredient_allergen_map WHERE ingredient_id = ?");
        $delStmt->execute([$ingredientId]);

        if (!empty($allergenIds)) {
            $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_allergen_map (ingredient_id, allergen_id) VALUES (?, ?)");
            foreach ($allergenIds as $allergenId) {
                $insertMapStmt->execute([$ingredientId, (int)$allergenId]);
            }
        }

        $pdo->commit();

        jsonResponse([
            'message' => 'Ingredient updated successfully',
            'ingredient' => [
                'id' => $ingredientId,
                'name' => $name,
                'allergen_ids' => array_map('intval', $allergenIds)
            ]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("handleUpdateIngredient PDO error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to update ingredient due to a database error.'], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("handleUpdateIngredient error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
}

function handleGetPendingIngredients($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT id, raw_name, status, resolved_ingredient_id, created_at 
            FROM ingredient_submissions 
            WHERE status = 'pending' 
            ORDER BY id ASC
        ");
        $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse($queue);
    } catch (Exception $e) {
        error_log("handleGetPendingIngredients error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to fetch pending submissions due to an internal server error.'], 500);
    }
}

function handleResolveSubmission($pdo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['submission_id'], $input['action'])) {
        jsonResponse(['error' => 'Missing required fields: submission_id and action are required.'], 400);
    }

    $submissionId = (int)$input['submission_id'];
    $action = trim($input['action']);

    if (!in_array($action, ['approve_as_new', 'link_to_existing', 'reject'], true)) {
        jsonResponse(['error' => 'Invalid action. Allowed: approve_as_new, link_to_existing, reject.'], 400);
    }

    // Verify submission exists and is pending
    $stmt = $pdo->prepare("SELECT * FROM ingredient_submissions WHERE id = ?");
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$submission) {
        jsonResponse(['error' => 'Submission not found.'], 404);
    }

    try {
        $pdo->beginTransaction();

        $resolvedIngredientId = null;

        if ($action === 'approve_as_new') {
            $name = isset($input['name']) && trim($input['name']) !== '' ? trim($input['name']) : trim($submission['raw_name']);
            $allergenIds = isset($input['allergen_ids']) && is_array($input['allergen_ids']) ? $input['allergen_ids'] : [];

            // Check if ingredient exists
            $checkStmt = $pdo->prepare("SELECT id FROM ingredients WHERE LOWER(name) = LOWER(?)");
            $checkStmt->execute([$name]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                $resolvedIngredientId = (int)$existingId;
                $updateStmt = $pdo->prepare("UPDATE ingredients SET name = ? WHERE id = ?");
                $updateStmt->execute([$name, $resolvedIngredientId]);
            } else {
                $insertStmt = $pdo->prepare("INSERT INTO ingredients (name) VALUES (?)");
                $insertStmt->execute([$name]);
                $resolvedIngredientId = (int)$pdo->lastInsertId();
            }

            // Sync allergen mappings
            $delStmt = $pdo->prepare("DELETE FROM ingredient_allergen_map WHERE ingredient_id = ?");
            $delStmt->execute([$resolvedIngredientId]);

            if (!empty($allergenIds)) {
                $insertMapStmt = $pdo->prepare("INSERT IGNORE INTO ingredient_allergen_map (ingredient_id, allergen_id) VALUES (?, ?)");
                foreach ($allergenIds as $allergenId) {
                    $insertMapStmt->execute([$resolvedIngredientId, (int)$allergenId]);
                }
            }

            $updSubStmt = $pdo->prepare("UPDATE ingredient_submissions SET status = 'approved', resolved_ingredient_id = ? WHERE id = ?");
            $updSubStmt->execute([$resolvedIngredientId, $submissionId]);

            $pdo->commit();

            jsonResponse([
                'message' => 'Submission approved as new ingredient',
                'submission_id' => $submissionId,
                'status' => 'approved',
                'ingredient' => [
                    'id' => $resolvedIngredientId,
                    'name' => $name,
                    'allergen_ids' => array_map('intval', $allergenIds)
                ]
            ]);
        } elseif ($action === 'link_to_existing') {
            if (!isset($input['existing_ingredient_id']) || (int)$input['existing_ingredient_id'] <= 0) {
                $pdo->rollBack();
                jsonResponse(['error' => 'existing_ingredient_id is required to link submission.'], 400);
            }

            $resolvedIngredientId = (int)$input['existing_ingredient_id'];
            // Check that ingredient exists
            $checkStmt = $pdo->prepare("SELECT id, name FROM ingredients WHERE id = ?");
            $checkStmt->execute([$resolvedIngredientId]);
            $ingredient = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ingredient) {
                $pdo->rollBack();
                jsonResponse(['error' => 'Specified existing ingredient does not exist.'], 404);
            }

            $updSubStmt = $pdo->prepare("UPDATE ingredient_submissions SET status = 'approved', resolved_ingredient_id = ? WHERE id = ?");
            $updSubStmt->execute([$resolvedIngredientId, $submissionId]);

            $pdo->commit();

            jsonResponse([
                'message' => 'Submission linked to existing ingredient',
                'submission_id' => $submissionId,
                'status' => 'approved',
                'ingredient' => $ingredient
            ]);
        } elseif ($action === 'reject') {
            $updSubStmt = $pdo->prepare("UPDATE ingredient_submissions SET status = 'rejected' WHERE id = ?");
            $updSubStmt->execute([$submissionId]);

            $pdo->commit();

            jsonResponse([
                'message' => 'Submission rejected',
                'submission_id' => $submissionId,
                'status' => 'rejected'
            ]);
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("handleResolveSubmission PDO error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to resolve submission due to a database error.'], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("handleResolveSubmission error: " . $e->getMessage());
        jsonResponse(['error' => 'An unexpected internal server error occurred.'], 500);
    }
}

function handleGetSettings(PDO $pdo): void
{
    requireAdmin($pdo);
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        jsonResponse(['error' => 'Settings not found'], 404);
        return;
    }

    $tz = new DateTimeZone('Asia/Manila');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $isOverrideActive = (!empty($settings['override_closing_date']) &&
        $settings['override_closing_date'] === $today &&
        !empty($settings['override_closing_time']));

    $effectiveClosing = PricingEngine::getEffectiveClosingTime($settings);

    $settings['effective_closing_time'] = $effectiveClosing;
    $settings['is_override_active'] = $isOverrideActive;

    jsonResponse([
        'settings' => $settings,
        'data' => $settings
    ]);
}

function handleUpdateSettings(PDO $pdo): void
{
    requireAdmin();
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $openingTime = trim($body['opening_time'] ?? '07:00');
    $reservationTtl = max(5, min(120, (int)($body['reservation_ttl'] ?? 30)));
    $announcement = isset($body['global_announcement']) ? trim($body['global_announcement']) : null;
    if ($announcement === '') {
        $announcement = null;
    }

    $overrideForToday = !empty($body['override_for_today']);
    $overrideTime = null;
    $overrideDate = null;

    if ($overrideForToday && !empty($body['override_closing_time'])) {
        $rawTime = trim($body['override_closing_time']);
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $rawTime)) {
            $tz = new DateTimeZone('Asia/Manila');
            $overrideTime = $rawTime . ':00';
            $overrideDate = (new DateTime('now', $tz))->format('Y-m-d');
        } else {
            throw new BusinessException('Invalid override closing time format. Must be HH:MM.', 422);
        }
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE settings
            SET opening_time = :opening_time,
                reservation_ttl = :reservation_ttl,
                global_announcement = :global_announcement,
                override_closing_time = :override_closing_time,
                override_closing_date = :override_closing_date
            WHERE id = 1
        ");
        $stmt->execute([
            ':opening_time' => $openingTime,
            ':reservation_ttl' => $reservationTtl,
            ':global_announcement' => $announcement,
            ':override_closing_time' => $overrideTime,
            ':override_closing_date' => $overrideDate,
        ]);

        handleGetSettings($pdo);
    } catch (PDOException $e) {
        error_log("Failed to update settings: " . $e->getMessage());
        jsonResponse(['error' => 'An internal server error occurred.'], 500);
    }
}

function handleGetAnnouncement($pdo)
{
    try {
        $stmt = $pdo->query("SELECT global_announcement FROM settings WHERE id = 1");
        $announcement = $stmt ? $stmt->fetchColumn() : null;
        jsonResponse([
            'global_announcement' => $announcement ?: null
        ]);
    } catch (Exception $e) {
        error_log("handleGetAnnouncement error: " . $e->getMessage());
        jsonResponse(['global_announcement' => null]);
    }
}

function handleGetDashboardMetrics($pdo)
{
    try {
        // 1. Users metrics
        $userStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN role IN ('student', 'customer') THEN 1 ELSE 0 END) as student_users,
                SUM(CASE WHEN role = 'vendor' THEN 1 ELSE 0 END) as vendor_users,
                SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admin_users
            FROM users
        ");
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);

        // 2. Menu dishes metrics
        $menuStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_dishes,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_dishes
            FROM menu_items
        ");
        $menuData = $menuStmt->fetch(PDO::FETCH_ASSOC);

        // 3. Orders & Reservations metrics
        $orderStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status IN ('reserved', 'paid') THEN 1 ELSE 0 END) as active_orders,
                SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END) as claimed_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_orders
            FROM orders
        ");
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);

        // 4. Taxonomy & Quality metrics
        $ingStmt = $pdo->query("SELECT COUNT(*) FROM ingredients");
        $totalIngredients = (int)$ingStmt->fetchColumn();

        $subStmt = $pdo->query("SELECT COUNT(*) FROM ingredient_submissions WHERE status = 'pending'");
        $pendingSubmissions = (int)$subStmt->fetchColumn();

        $allergensStmt = $pdo->query("SELECT COUNT(*) FROM critical_allergens");
        $totalAllergens = (int)$allergensStmt->fetchColumn();

        // 5. AI Telemetry metrics
        $aiStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_ai_dishes,
                SUM(CASE WHEN ai_confirmed = 0 OR ai_confirmed IS NULL THEN 1 ELSE 0 END) as unconfirmed_ai_dishes,
                SUM(CASE WHEN ai_confirmed = 1 THEN 1 ELSE 0 END) as confirmed_ai_dishes
            FROM menu_items
        ");
        $aiData = $aiStmt->fetch(PDO::FETCH_ASSOC);

        $cacheCount = 0;
        try {
            $cacheStmt = $pdo->query("SELECT COUNT(*) FROM ai_query_cache");
            $cacheCount = (int)$cacheStmt->fetchColumn();
        } catch (Exception $e) {
        }

        jsonResponse([
            'users' => [
                'total' => (int)$userData['total_users'],
                'students' => (int)$userData['student_users'],
                'vendors' => (int)$userData['vendor_users'],
                'admins' => (int)$userData['admin_users']
            ],
            'menu' => [
                'total' => (int)$menuData['total_dishes'],
                'active' => (int)$menuData['active_dishes']
            ],
            'orders' => [
                'total' => (int)$orderData['total_orders'],
                'active' => (int)$orderData['active_orders'],
                'claimed' => (int)$orderData['claimed_orders'],
                'cancelled' => (int)$orderData['cancelled_orders'],
                'expired' => (int)$orderData['expired_orders']
            ],
            'taxonomy' => [
                'ingredients' => $totalIngredients,
                'pending_submissions' => $pendingSubmissions,
                'critical_allergens' => $totalAllergens
            ],
            'ai' => [
                'unconfirmed_dishes' => (int)$aiData['unconfirmed_ai_dishes'],
                'confirmed_dishes' => (int)$aiData['confirmed_ai_dishes'],
                'cached_queries' => $cacheCount
            ]
        ]);
    } catch (Exception $e) {
        error_log("handleGetDashboardMetrics error: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to calculate metrics due to an internal server error.'], 500);
    }
}
