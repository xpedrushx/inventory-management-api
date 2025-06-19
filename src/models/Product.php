<?php

declare(strict_types=1);

namespace InventoryAPI\Models;

use InventoryAPI\Config\Database;
use InventoryAPI\Config\Redis;
use PDOException;

/**
 * Product Model
 * High-performance inventory management with intelligent caching
 */
class Product
{
    private Database $database;
    private Redis $cache;

    public function __construct(Database $database, Redis $cache)
    {
        $this->database = $database;
        $this->cache = $cache;
    }

    /**
     * Get all products with pagination and caching
     */
    public function getAll(int $page = 1, int $limit = 50, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;
        $cacheKey = "inventory:list:page_{$page}_limit_{$limit}_" . md5(serialize($filters));
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // Build dynamic WHERE clause
            $whereConditions = [];
            $params = [];
            
            if (!empty($filters['category'])) {
                $whereConditions[] = 'category = :category';
                $params['category'] = $filters['category'];
            }
            
            if (!empty($filters['status'])) {
                $whereConditions[] = 'status = :status';
                $params['status'] = $filters['status'];
            }
            
            if (isset($filters['min_stock'])) {
                $whereConditions[] = 'quantity >= :min_stock';
                $params['min_stock'] = $filters['min_stock'];
            }

            $whereClause = !empty($whereConditions) 
                ? 'WHERE ' . implode(' AND ', $whereConditions)
                : '';

            // Get total count for pagination
            $countSql = "SELECT COUNT(*) as total FROM products {$whereClause}";
            $totalResult = $this->database->query($countSql, $params);
            $total = $totalResult[0]['total'];

            // Get products with optimized query
            $sql = "
                SELECT 
                    id, name, sku, category, description, 
                    quantity, price, cost, status, 
                    created_at, updated_at,
                    CASE 
                        WHEN quantity <= 10 THEN 'low'
                        WHEN quantity <= 50 THEN 'medium'
                        ELSE 'high'
                    END as stock_level
                FROM products 
                {$whereClause}
                ORDER BY updated_at DESC 
                LIMIT :limit OFFSET :offset
            ";
            
            $params['limit'] = $limit;
            $params['offset'] = $offset;
            
            $products = $this->database->query($sql, $params);

            $result = [
                'data' => $products,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => (int)$total,
                    'total_pages' => ceil($total / $limit),
                    'has_next' => $page < ceil($total / $limit),
                    'has_prev' => $page > 1
                ]
            ];

            // Cache result for 5 minutes
            $this->cache->set($cacheKey, $result, Redis::CACHE_SHORT);
            
            return $result;
        } catch (PDOException $e) {
            throw new \Exception('Failed to retrieve products: ' . $e->getMessage());
        }
    }

    /**
     * Get single product by ID with caching
     */
    public function getById(int $id): ?array
    {
        $cacheKey = "inventory:product:{$id}";
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $sql = "
                SELECT 
                    id, name, sku, category, description, 
                    quantity, price, cost, status, 
                    created_at, updated_at,
                    CASE 
                        WHEN quantity <= 10 THEN 'low'
                        WHEN quantity <= 50 THEN 'medium'
                        ELSE 'high'
                    END as stock_level
                FROM products 
                WHERE id = :id
            ";
            
            $result = $this->database->query($sql, ['id' => $id]);
            $product = $result[0] ?? null;

            if ($product) {
                // Cache for 30 minutes
                $this->cache->set($cacheKey, $product, Redis::CACHE_MEDIUM);
            }

            return $product;
        } catch (PDOException $e) {
            throw new \Exception('Failed to retrieve product: ' . $e->getMessage());
        }
    }

    /**
     * Create new product
     */
    public function create(array $data): array
    {
        try {
            // Validate required fields
            $this->validateProductData($data);
            
            $sql = "
                INSERT INTO products (name, sku, category, description, quantity, price, cost, status)
                VALUES (:name, :sku, :category, :description, :quantity, :price, :cost, :status)
            ";
            
            $params = [
                'name' => $data['name'],
                'sku' => $data['sku'],
                'category' => $data['category'] ?? 'general',
                'description' => $data['description'] ?? '',
                'quantity' => $data['quantity'] ?? 0,
                'price' => $data['price'] ?? 0.00,
                'cost' => $data['cost'] ?? 0.00,
                'status' => $data['status'] ?? 'active'
            ];

            $this->database->execute($sql, $params);
            $productId = (int)$this->database->lastInsertId();

            // Invalidate relevant caches
            $this->cache->invalidateInventoryCache();

            // Return created product
            return $this->getById($productId);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // Duplicate entry
                throw new \Exception('Product SKU already exists');
            }
            throw new \Exception('Failed to create product: ' . $e->getMessage());
        }
    }

    /**
     * Update product
     */
    public function update(int $id, array $data): ?array
    {
        try {
            // Check if product exists
            $existing = $this->getById($id);
            if (!$existing) {
                return null;
            }

            // Build dynamic UPDATE query
            $updates = [];
            $params = ['id' => $id];
            
            $allowedFields = ['name', 'sku', 'category', 'description', 'quantity', 'price', 'cost', 'status'];
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[] = "{$field} = :{$field}";
                    $params[$field] = $data[$field];
                }
            }

            if (empty($updates)) {
                return $existing; // No changes
            }

            $sql = "UPDATE products SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id";
            
            $this->database->execute($sql, $params);

            // Invalidate caches
            $this->cache->invalidateInventoryCache($id);

            // Return updated product
            return $this->getById($id);
        } catch (PDOException $e) {
            throw new \Exception('Failed to update product: ' . $e->getMessage());
        }
    }

    /**
     * Delete product (soft delete)
     */
    public function delete(int $id): bool
    {
        try {
            // Soft delete by setting status to 'deleted'
            $sql = "UPDATE products SET status = 'deleted', updated_at = NOW() WHERE id = :id";
            $affected = $this->database->execute($sql, ['id' => $id]);

            if ($affected > 0) {
                // Invalidate caches
                $this->cache->invalidateInventoryCache($id);
                return true;
            }

            return false;
        } catch (PDOException $e) {
            throw new \Exception('Failed to delete product: ' . $e->getMessage());
        }
    }

    /**
     * Search products with full-text search
     */
    public function search(string $query, int $limit = 20): array
    {
        $cacheKey = "inventory:search:" . md5($query . $limit);
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $sql = "
                SELECT 
                    id, name, sku, category, description, 
                    quantity, price, status,
                    MATCH(name, description) AGAINST(:query IN NATURAL LANGUAGE MODE) as relevance
                FROM products 
                WHERE MATCH(name, description) AGAINST(:query IN NATURAL LANGUAGE MODE)
                   OR name LIKE :like_query 
                   OR sku LIKE :like_query
                   AND status != 'deleted'
                ORDER BY relevance DESC, name ASC
                LIMIT :limit
            ";
            
            $params = [
                'query' => $query,
                'like_query' => "%{$query}%",
                'limit' => $limit
            ];
            
            $results = $this->database->query($sql, $params);

            // Cache for 5 minutes
            $this->cache->set($cacheKey, $results, Redis::CACHE_SHORT);

            return $results;
        } catch (PDOException $e) {
            throw new \Exception('Search failed: ' . $e->getMessage());
        }
    }

    /**
     * Get low stock products
     */
    public function getLowStock(int $threshold = 10): array
    {
        $cacheKey = "inventory:low_stock:{$threshold}";
        
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $sql = "
                SELECT id, name, sku, quantity, category
                FROM products 
                WHERE quantity <= :threshold AND status = 'active'
                ORDER BY quantity ASC, name ASC
            ";
            
            $results = $this->database->query($sql, ['threshold' => $threshold]);

            // Cache for 10 minutes (stock changes frequently)
            $this->cache->set($cacheKey, $results, 600);

            return $results;
        } catch (PDOException $e) {
            throw new \Exception('Failed to get low stock products: ' . $e->getMessage());
        }
    }

    /**
     * Get inventory analytics
     */
    public function getAnalytics(): array
    {
        $cacheKey = "analytics:inventory_overview";
        
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_products,
                    SUM(quantity) as total_stock,
                    SUM(quantity * cost) as total_value,
                    AVG(quantity) as avg_stock_per_product,
                    COUNT(CASE WHEN quantity <= 10 THEN 1 END) as low_stock_count,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_products
                FROM products 
                WHERE status != 'deleted'
            ";
            
            $overview = $this->database->query($sql)[0];

            // Category breakdown
            $categorySql = "
                SELECT 
                    category,
                    COUNT(*) as product_count,
                    SUM(quantity) as total_stock,
                    SUM(quantity * cost) as category_value
                FROM products 
                WHERE status != 'deleted'
                GROUP BY category
                ORDER BY category_value DESC
            ";
            
            $categories = $this->database->query($categorySql);

            $analytics = [
                'overview' => $overview,
                'categories' => $categories,
                'generated_at' => date('Y-m-d H:i:s')
            ];

            // Cache for 30 minutes
            $this->cache->set($cacheKey, $analytics, Redis::CACHE_MEDIUM);

            return $analytics;
        } catch (PDOException $e) {
            throw new \Exception('Failed to generate analytics: ' . $e->getMessage());
        }
    }

    /**
     * Validate product data
     */
    private function validateProductData(array $data): void
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Product name is required');
        }

        if (empty($data['sku'])) {
            throw new \InvalidArgumentException('Product SKU is required');
        }

        if (isset($data['quantity']) && $data['quantity'] < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative');
        }

        if (isset($data['price']) && $data['price'] < 0) {
            throw new \InvalidArgumentException('Price cannot be negative');
        }
    }
}