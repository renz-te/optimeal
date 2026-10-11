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

