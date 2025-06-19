<?php

declare(strict_types=1);

namespace InventoryAPI\Controllers;

use InventoryAPI\Config\Database;
use InventoryAPI\Config\Redis;
use InventoryAPI\Models\Product;

/**
 * Inventory Controller
 * Handles all inventory-related API endpoints with high performance
 */
class InventoryController
{
    private Database $database;
    private Redis $cache;
    private Product $productModel;

    public function __construct(Database $database, Redis $cache)
    {
        $this->database = $database;
        $this->cache = $cache;
        $this->productModel = new Product($database, $cache);
    }

    /**
     * GET /api/inventory - List all products with pagination
     */
    public function index(): void
    {
        $startTime = microtime(true);
        
        try {
            // Get query parameters
            $page = (int)($_GET['page'] ?? 1);
            $limit = min((int)($_GET['limit'] ?? 50), 100); // Max 100 per page
            
            // Filters
            $filters = [
                'category' => $_GET['category'] ?? null,
                'status' => $_GET['status'] ?? 'active',
                'min_stock' => isset($_GET['min_stock']) ? (int)$_GET['min_stock'] : null
            ];
            
            // Remove null filters
            $filters = array_filter($filters, fn($value) => $value !== null);

            $result = $this->productModel->getAll($page, $limit, $filters);
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->sendResponse([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'meta' => [
                    'total_results' => count($result['data']),
                    'response_time_ms' => $responseTime,
                    'cached' => false // You could detect this from cache
                ]
            ]);
        } catch (\Exception $e) {
            $this->sendError('Failed to retrieve inventory', 500, $e->getMessage());
        }
    }

    /**
     * GET /api/inventory/{id} - Get single product
     */
    public function show(int $id): void
    {
        $startTime = microtime(true);
        
        try {
            $product = $this->productModel->getById($id);
            
            if (!$product) {
                $this->sendError('Product not found', 404);
                return;
            }

            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->sendResponse([
                'success' => true,
                'data' => $product,
                'meta' => [
                    'response_time_ms' => $responseTime
                ]
            ]);
        } catch (\Exception $e) {
            $this->sendError('Failed to retrieve product', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/inventory - Create new product
     */
    public function store(): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (!$input) {
                $this->sendError('Invalid JSON input', 400);
                return;
            }

            $product = $this->productModel->create($input);

            $this->sendResponse([
                'success' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);
        } catch (\InvalidArgumentException $e) {
            $this->sendError('Validation error', 400, $e->getMessage());
        } catch (\Exception $e) {
            $this->sendError('Failed to create product', 500, $e->getMessage());
        }
    }

    /**
     * PUT /api/inventory/{id} - Update product
     */
    public function update(int $id): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (!$input) {
                $this->sendError('Invalid JSON input', 400);
                return;
            }

            $product = $this->productModel->update($id, $input);

            if (!$product) {
                $this->sendError('Product not found', 404);
                return;
            }

            $this->sendResponse([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->sendError('Validation error', 400, $e->getMessage());
        } catch (\Exception $e) {
            $this->sendError('Failed to update product', 500, $e->getMessage());
        }
    }

    /**
     * DELETE /api/inventory/{id} - Delete product
     */
    public function delete(int $id): void
    {
        try {
            $success = $this->productModel->delete($id);

            if (!$success) {
                $this->sendError('Product not found', 404);
                return;
            }

            $this->sendResponse([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            $this->sendError('Failed to delete product', 500, $e->getMessage());
        }
    }

    /**
     * GET /api/inventory/search - Search products
     */
    public function search(): void
    {
        $startTime = microtime(true);
        
        try {
            $query = $_GET['q'] ?? '';
            $limit = min((int)($_GET['limit'] ?? 20), 50);

            if (strlen($query) < 2) {
                $this->sendError('Search query must be at least 2 characters', 400);
                return;
            }

            $results = $this->productModel->search($query, $limit);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->sendResponse([
                'success' => true,
                'data' => $results,
                'meta' => [
                    'query' => $query,
                    'total_results' => count($results),
                    'response_time_ms' => $responseTime
                ]
            ]);
        } catch (\Exception $e) {
            $this->sendError('Search failed', 500, $e->getMessage());
        }
    }

    /**
     * POST /api/inventory/bulk - Bulk update products
     */
    public function bulkUpdate(): void
    {
        try {
            $input = $this->getJsonInput();
            
            if (!isset($input['products']) || !is_array($input['products'])) {
                $this->sendError('Invalid bulk update format. Expected: {"products": [...]}', 400);
                return;
            }

            $results = [];
            $errors = [];

            $this->database->beginTransaction();

            try {
                foreach ($input['products'] as $index => $productData) {
                    if (!isset($productData['id'])) {
                        $errors[] = "Product at index {$index}: ID is required";
                        continue;
                    }

                    $id = (int)$productData['id'];
                    unset($productData['id']);

                    $updated = $this->productModel->update($id, $productData);
                    
                    if ($updated) {
                        $results[] = $updated;
                    } else {
                        $errors[] = "Product with ID {$id}: Not found";
                    }
                }

                $this->database->commit();

                $this->sendResponse([
                    'success' => true,
                    'message' => 'Bulk update completed',
                    'data' => [
                        'updated_count' => count($results),
                        'error_count' => count($errors),
                        'updated_products' => $results,
                        'errors' => $errors
                    ]
                ]);
            } catch (\Exception $e) {
                $this->database->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            $this->sendError('Bulk update failed', 500, $e->getMessage());
        }
    }

    /**
     * GET /api/analytics/performance - Get API performance metrics
     */
    public function performanceMetrics(): void
    {
        try {
            // Get database stats
            $dbStats = $this->database->getStats();
            
            // Get Redis stats
            $cacheStats = $this->cache->getStats();
            
            // Get inventory analytics
            $inventoryStats = $this->productModel->getAnalytics();
            
            // Get low stock products
            $lowStock = $this->productModel->getLowStock();

            $this->sendResponse([
                'success' => true,
                'data' => [
                    'database' => $dbStats,
                    'cache' => $cacheStats,
                    'inventory' => $inventoryStats,
                    'alerts' => [
                        'low_stock_products' => count($lowStock),
                        'low_stock_list' => array_slice($lowStock, 0, 5) // Top 5
                    ],
                    'api_health' => [
                        'status' => 'healthy',
                        'uptime' => $this->getUptime(),
                        'memory_usage' => $this->getMemoryUsage()
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->sendError('Failed to get performance metrics', 500, $e->getMessage());
        }
    }

    /**
     * Get JSON input from request body
     */
    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }

    /**
     * Send JSON response
     */
    private function sendResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send error response
     */
    private function sendError(string $message, int $statusCode = 400, string $details = null): void
    {
        $error = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $statusCode
            ]
        ];

        if ($details && ($_ENV['API_DEBUG'] ?? false)) {
            $error['error']['details'] = $details;
        }

        http_response_code($statusCode);
        echo json_encode($error, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Get API uptime (simplified)
     */
    private function getUptime(): string
    {
        // This is a simplified version. In production, you'd track actual start time
        $uptime = time() - strtotime('today');
        return gmdate('H:i:s', $uptime);
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(): array
    {
        return [
            'current' => round(memory_get_usage(true) / 1024 / 1024, 2) . 'MB',
            'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB'
        ];
    }
}