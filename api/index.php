<?php
ini_set('display_errors', 0);
error_reporting(0);
session_start();
header('Content-Type: application/json');

require_once 'config.php';
require_once 'db.php';
require_once 'PricingEngine.php';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Base path handler if running via subfolder in Laragon
$basepath = '/optimeal/api';
if (strpos($path, $basepath) === 0) {
    $route = substr($path, strlen($basepath));
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
} elseif ($route === '/reservations' && $method === 'POST') {
    require 'routes/customer.php';
    handleCreateReservation($pdo);
} elseif (preg_match('#^/reservations/(\d+)/pay$#', $route, $matches) && $method === 'POST') {
    require 'routes/customer.php';
    handlePayReservation($pdo, $matches[1]);
} elseif ($route === '/orders' && $method === 'GET') {
    require 'routes/customer.php';
    handleGetCustomerOrders($pdo);
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
    handleGetVendorOrders($pdo);
} elseif ($route === '/vendor/enrich-dish' && $method === 'POST') {
    require 'routes/vendor.php';
    handleEnrichDish($pdo);
} elseif ($route === '/vendor/dish' && $method === 'POST') {
    require 'routes/vendor.php';
    handleSaveDish($pdo);
} else {
    jsonResponse(['error' => 'Not Found'], 404);
}
