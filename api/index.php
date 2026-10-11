<?php
ini_set('display_errors', 0);
error_reporting(0);
date_default_timezone_set('Asia/Manila');
session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once 'db.php';
require_once 'PricingEngine.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Base path handler if running via subfolder in Laragon or Virtual Host
if (strpos($path, '/optimeal/api') === 0) {
    $route = substr($path, strlen('/optimeal/api'));
} elseif (strpos($path, '/api') === 0) {
    $route = substr($path, strlen('/api'));
} else {
    $route = $path; // fallback
}
$route = rtrim($route, '/') ?: '/';

function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function requireRole($pdo, array $allowedRoles) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        jsonResponse(['error' => 'Unauthorized. Login required.'], 401);
    }
    if (!in_array($_SESSION['role'], $allowedRoles)) {
        jsonResponse(['error' => 'Forbidden. Insufficient permissions.'], 403);
    }
    return $_SESSION;
}

function requireAdmin($pdo) {
    return requireRole($pdo, ['admin']);
}

function requireStudent($pdo) {
    return requireRole($pdo, ['student', 'customer']);
}

function requireStaff($pdo) {
    requireRole($pdo, ['vendor', 'admin']);

    $stmt = $pdo->query("SELECT id FROM stores WHERE is_active = 1 LIMIT 1");
    $storeId = $stmt->fetchColumn();
    if (!$storeId) {
        $stmt = $pdo->query("SELECT id FROM stores LIMIT 1");
        $storeId = $stmt->fetchColumn();
    }
    if (!$storeId) {
        jsonResponse(['error' => 'No active cafeteria store found.'], 500);
    }
    return (int)$storeId;
}


// Simple Router Pattern
if ($route === '/auth/register' && $method === 'POST') {
    require 'routes/auth.php';
    handleRegister($pdo);
} elseif ($route === '/auth/login' && $method === 'POST') {
    require 'routes/auth.php';
    handleLogin($pdo);
} elseif ($route === '/menu' && $method === 'GET') {
    require 'routes/customer.php';
    handleGetMenu($pdo);
} elseif ($route === '/customer/profile' && $method === 'GET') {
    requireStudent($pdo);
    require 'routes/customer.php';
    handleGetProfile($pdo);
} elseif ($route === '/customer/profile' && $method === 'POST') {
    requireStudent($pdo);
    require 'routes/customer.php';
    handleUpdateProfile($pdo);
} elseif ($route === '/reservations' && $method === 'POST') {
    require 'routes/customer.php';
    handleCreateReservation($pdo);
} elseif (preg_match('#^/reservations/(\d+)/pay$#', $route, $matches) && $method === 'POST') {
    require 'routes/customer.php';
    handlePayReservation($pdo, $matches[1]);
} elseif ($route === '/reservations/update-fulfillment' && $method === 'POST') {
    require 'routes/customer.php';
    handleUpdateFulfillment($pdo);
} elseif ($route === '/customer/orders' && $method === 'GET') {
    requireStudent($pdo);
    require 'routes/customer.php';
    handleGetMyOrders($pdo);
} elseif ($route === '/orders' && $method === 'GET') {
    require 'routes/customer.php';
    handleGetCustomerOrders($pdo);
} elseif ($route === '/vendor/decay' && $method === 'GET') {
    requireStaff($pdo);
    require 'routes/vendor.php';
    handleGetDecayConfig($pdo);
} elseif ($route === '/vendor/decay' && $method === 'POST') {
    requireStaff($pdo);
    require 'routes/vendor.php';
    handleUpdateDecayConfig($pdo);
} elseif ($route === '/vendor/decay/emergency' && $method === 'POST') {
    requireStaff($pdo);
    require 'routes/vendor.php';
    handleTriggerEmergencyDecay($pdo);
} elseif ($route === '/vendor/pricing-rules' && $method === 'GET') {
    require 'routes/vendor.php';
    handleGetPricingRules($pdo);
} elseif ($route === '/vendor/pricing-rules' && $method === 'POST') {
    require 'routes/vendor.php';
    handlePostPricingRules($pdo);
} elseif ($route === '/vendor/inventory' && $method === 'GET') {
    require 'routes/vendor.php';
    handleGetInventory($pdo);
} elseif ($route === '/vendor/inventory' && $method === 'POST') {
    require 'routes/vendor.php';
    handlePostInventory($pdo);
} elseif ($route === '/vendor/orders' && $method === 'GET') {
    require 'routes/vendor.php';
    handleGetOrders($pdo);
} elseif ($route === '/vendor/orders/update' && $method === 'POST') {
    require 'routes/vendor.php';
    handleUpdateOrderStatus($pdo);
} elseif ($route === '/vendor/enrich-dish' && $method === 'POST') {
    require 'routes/vendor.php';
    handleEnrichDish($pdo);
} elseif ($route === '/vendor/dish' && $method === 'POST') {
    require 'routes/vendor.php';
    handleSaveDish($pdo);
} elseif ($route === '/vendor/menu' && $method === 'GET') {
    require 'routes/vendor.php';
    handleGetVendorMenu($pdo);
} elseif ($route === '/vendor/menu/launch' && $method === 'POST') {
    require 'routes/vendor.php';
    handleLaunchMenu($pdo);
} elseif ($route === '/vendor/audit' && $method === 'GET') {
    requireStaff($pdo);
    require 'routes/vendor.php';
    handleGetDailyAudit($pdo);
} elseif ($route === '/admin/users' && $method === 'GET') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleGetUsers($pdo);
} elseif ($route === '/admin/users' && $method === 'POST') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleCreateUser($pdo);
} elseif ($route === '/admin/taxonomy' && $method === 'GET') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleGetTaxonomy($pdo);
} elseif ($route === '/admin/ingredient' && $method === 'POST') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleUpdateIngredient($pdo);
} elseif ($route === '/admin/taxonomy/queue' && $method === 'GET') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleGetPendingIngredients($pdo);
} elseif ($route === '/admin/taxonomy/queue/resolve' && $method === 'POST') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleResolveSubmission($pdo);
} elseif ($route === '/admin/settings' && $method === 'GET') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleGetSettings($pdo);
} elseif ($route === '/admin/settings' && $method === 'POST') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleUpdateSettings($pdo);
} elseif ($route === '/admin/metrics' && $method === 'GET') {
    requireAdmin($pdo);
    require 'routes/admin.php';
    handleGetDashboardMetrics($pdo);
} else {
    jsonResponse(['error' => 'Not Found'], 404);
}

