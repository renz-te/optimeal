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
