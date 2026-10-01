<?php
function handleRegister($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['name'], $input['email'], $input['password'], $input['role'])) {
        jsonResponse(['error' => 'Missing fields'], 400);
    }
    
    $hash = password_hash($input['password'], PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$input['name'], $input['email'], $hash, $input['role']]);
        jsonResponse(['message' => 'User registered', 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        jsonResponse(['error' => 'Email already exists or invalid data'], 400);
    }
}

function handleLogin($pdo) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['email'], $input['password'])) {
        jsonResponse(['error' => 'Missing credentials'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT id, name, password_hash, role FROM users WHERE email = ?");
    $stmt->execute([$input['email']]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($input['password'], $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        jsonResponse([
            'message' => 'Logged in', 
            'role' => $user['role'],
            'name' => $user['name']
        ]);
    } else {
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }
}
