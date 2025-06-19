<?php
/**
 * Inventory Management API - Entry Point
 * High-performance REST API with Redis caching
 * 
 * @author Pedro Mercado <pedromercadodev@gmail.com>
 * @version 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use InventoryAPI\Controllers\InventoryController;
use InventoryAPI\Controllers\AuthController;
use InventoryAPI\Middleware\AuthMiddleware;
use InventoryAPI\Config\Database;
use InventoryAPI\Config\Redis;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize database and Redis connections
$database = new Database();
$redis = new Redis();

// Simple router
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string and normalize path
$path = strtok($requestUri, '?');
$path = rtrim($path, '/');

// API Routes
switch (true) {
    // Authentication routes
    case $path === '/api/auth/login' && $requestMethod === 'POST':
        $controller = new AuthController($database, $redis);
        $controller->login();
        break;
        
    case $path === '/api/auth/refresh' && $requestMethod === 'POST':
        $controller = new AuthController($database, $redis);
        $controller->refresh();
        break;
        
    case $path === '/api/auth/logout' && $requestMethod === 'POST':
        $controller = new AuthController($database, $redis);
        $controller->logout();
        break;
    
    // Inventory routes (protected)
    case $path === '/api/inventory' && $requestMethod === 'GET':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->index();
        break;
        
    case preg_match('/^\/api\/inventory\/(\d+)$/', $path, $matches) && $requestMethod === 'GET':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->show((int)$matches[1]);
        break;
        
    case $path === '/api/inventory' && $requestMethod === 'POST':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->store();
        break;
        
    case preg_match('/^\/api\/inventory\/(\d+)$/', $path, $matches) && $requestMethod === 'PUT':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->update((int)$matches[1]);
        break;
        
    case preg_match('/^\/api\/inventory\/(\d+)$/', $path, $matches) && $requestMethod === 'DELETE':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->delete((int)$matches[1]);
        break;
        
    case $path === '/api/inventory/search' && $requestMethod === 'GET':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->search();
        break;
        
    case $path === '/api/inventory/bulk' && $requestMethod === 'POST':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->bulkUpdate();
        break;
    
    // Analytics routes
    case $path === '/api/analytics/performance' && $requestMethod === 'GET':
        AuthMiddleware::authenticate();
        $controller = new InventoryController($database, $redis);
        $controller->performanceMetrics();
        break;
    
    // Health check
    case $path === '/api/health' && $requestMethod === 'GET':
        echo json_encode([
            'status' => 'healthy',
            'timestamp' => time(),
            'version' => '1.0.0',
            'database' => $database->isConnected(),
            'redis' => $redis->isConnected()
        ]);
        break;
        
    // API documentation
    case $path === '/api/docs' || $path === '/':
        include __DIR__ . '/../docs/api-docs.html';
        break;
        
    default:
        http_response_code(404);
        echo json_encode([
            'error' => 'Endpoint not found',
            'message' => 'The requested API endpoint does not exist',
            'available_endpoints' => [
                'GET /api/health',
                'POST /api/auth/login',
                'GET /api/inventory',
                'POST /api/inventory',
                'GET /api/docs'
            ]
        ]);
        break;
}

/**
 * Log API request for monitoring
 */
function logRequest(): void {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    error_log(json_encode($logData), 3, __DIR__ . '/../logs/api.log');
}

// Log this request
logRequest();