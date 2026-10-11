<?php

function handleGetUsers($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id DESC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse($users);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Failed to fetch users: ' . $e->getMessage()], 500);
    }
}

function handleCreateUser($pdo) {
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
        jsonResponse(['error' => 'Failed to create user: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleGetTaxonomy($pdo) {
    try {
        $allergens = $pdo->query("SELECT id, name FROM critical_allergens ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        $sensitivities = [];
        try {
            $sensitivities = $pdo->query("SELECT id, name FROM digestive_sensitivities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

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
        jsonResponse(['error' => 'Failed to fetch taxonomy: ' . $e->getMessage()], 500);
    }
}

function handleUpdateIngredient($pdo) {
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
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleGetPendingIngredients($pdo) {
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
        jsonResponse(['error' => 'Failed to fetch pending submissions: ' . $e->getMessage()], 500);
    }
}

function handleResolveSubmission($pdo) {
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
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleGetSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, updated_at FROM settings WHERE id = 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $pdo->exec("INSERT IGNORE INTO settings (id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate) VALUES (1, 30, '07:00', '20:00', 5, 10.00)");
            $stmt = $pdo->query("SELECT id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, updated_at FROM settings WHERE id = 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        jsonResponse([
            'settings' => [
                'id' => (int)$settings['id'],
                'reservation_ttl' => (int)$settings['reservation_ttl'],
                'opening_time' => $settings['opening_time'],
                'closing_time' => $settings['closing_time'],
                'max_discount_qty' => (int)$settings['max_discount_qty'],
                'default_decay_rate' => (float)$settings['default_decay_rate'],
                'updated_at' => $settings['updated_at']
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Failed to fetch settings: ' . $e->getMessage()], 500);
    }
}

function handleUpdateSettings($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['reservation_ttl'])) {
        jsonResponse(['error' => 'Reservation TTL is required.'], 400);
    }

    $reservationTtl = max(1, (int)$input['reservation_ttl']);
    $openingTime = isset($input['opening_time']) ? trim($input['opening_time']) : '07:00';
    $closingTime = isset($input['closing_time']) ? trim($input['closing_time']) : '20:00';
    $maxDiscountQty = isset($input['max_discount_qty']) ? max(0, (int)$input['max_discount_qty']) : 5;
    $defaultDecayRate = isset($input['default_decay_rate']) ? max(0, (float)$input['default_decay_rate']) : 10.00;

    try {
        $stmt = $pdo->prepare("
            UPDATE settings 
            SET reservation_ttl = ?, opening_time = ?, closing_time = ?, max_discount_qty = ?, default_decay_rate = ? 
            WHERE id = 1
        ");
        $stmt->execute([$reservationTtl, $openingTime, $closingTime, $maxDiscountQty, $defaultDecayRate]);

        // If no row updated, ensure row 1 exists
        if ($stmt->rowCount() === 0) {
            $check = $pdo->query("SELECT id FROM settings WHERE id = 1")->fetchColumn();
            if (!$check) {
                $ins = $pdo->prepare("INSERT INTO settings (id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate) VALUES (1, ?, ?, ?, ?, ?)");
                $ins->execute([$reservationTtl, $openingTime, $closingTime, $maxDiscountQty, $defaultDecayRate]);
            }
        }

        $fetchStmt = $pdo->query("SELECT id, reservation_ttl, opening_time, closing_time, max_discount_qty, default_decay_rate, updated_at FROM settings WHERE id = 1");
        $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse([
            'message' => 'Settings updated successfully',
            'settings' => [
                'id' => (int)$updated['id'],
                'reservation_ttl' => (int)$updated['reservation_ttl'],
                'opening_time' => $updated['opening_time'],
                'closing_time' => $updated['closing_time'],
                'max_discount_qty' => (int)$updated['max_discount_qty'],
                'default_decay_rate' => (float)$updated['default_decay_rate'],
                'updated_at' => $updated['updated_at']
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Failed to update settings: ' . $e->getMessage()], 500);
    }
}

function handleGetDashboardMetrics($pdo) {
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
            ]
        ]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Failed to calculate metrics: ' . $e->getMessage()], 500);
    }
}



