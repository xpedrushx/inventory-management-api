<?php

declare(strict_types=1);

namespace InventoryAPI\Config;

use Predis\Client;
use Predis\Connection\ConnectionException;

/**
 * Redis Configuration and Cache Manager
 * Optimized for high-performance caching with intelligent invalidation
 */
class Redis
{
    private ?Client $client = null;
    private array $config;
    private static ?self $instance = null;

    // Cache TTL constants (in seconds)
    public const CACHE_SHORT = 300;    // 5 minutes
    public const CACHE_MEDIUM = 1800;  // 30 minutes
    public const CACHE_LONG = 3600;    // 1 hour
    public const CACHE_DAILY = 86400;  // 24 hours

    public function __construct()
    {
        $this->config = [
            'scheme' => 'tcp',
            'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
            'port' => $_ENV['REDIS_PORT'] ?? 6379,
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => 0,
            'read_write_timeout' => 0,
            'persistent' => true,
        ];
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Connect to Redis with retry logic
     */
    public function connect(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $maxRetries = 3;
        $retryDelay = 1;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->client = new Client($this->config);
                
                // Test connection
                $this->client->ping();
                
                return $this->client;
            } catch (ConnectionException $e) {
                if ($attempt === $maxRetries) {
                    throw new ConnectionException(
                        'Redis connection failed after ' . $maxRetries . ' attempts: ' . $e->getMessage()
                    );
                }
                sleep($retryDelay);
                $retryDelay *= 2;
            }
        }

        throw new ConnectionException('Unexpected Redis connection error');
    }

    /**
     * Get current Redis client
     */
    public function getClient(): Client
    {
        return $this->connect();
    }

    /**
     * Check if Redis is connected and responsive
     */
    public function isConnected(): bool
    {
        try {
            if ($this->client === null) {
                $this->connect();
            }
            $response = $this->client->ping();
            return $response->getPayload() === 'PONG';
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * Set cache with automatic serialization
     */
    public function set(string $key, mixed $value, int $ttl = self::CACHE_MEDIUM): bool
    {
        try {
            $serializedValue = is_array($value) || is_object($value) 
                ? json_encode($value) 
                : (string)$value;
                
            $result = $this->getClient()->setex($key, $ttl, $serializedValue);
            
            // Log cache set for monitoring
            $this->logCacheOperation('SET', $key, strlen($serializedValue));
            
            return $result->getPayload() === 'OK';
        } catch (ConnectionException $e) {
            error_log('Redis SET error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cache with automatic deserialization
     */
    public function get(string $key): mixed
    {
        try {
            $value = $this->getClient()->get($key);
            
            if ($value === null) {
                $this->logCacheOperation('MISS', $key);
                return null;
            }
            
            $this->logCacheOperation('HIT', $key);
            
            // Try to decode JSON, return as string if not valid JSON
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        } catch (ConnectionException $e) {
            error_log('Redis GET error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete cache key(s)
     */
    public function delete(string|array $keys): int
    {
        try {
            $keys = is_array($keys) ? $keys : [$keys];
            $result = $this->getClient()->del($keys);
            
            foreach ($keys as $key) {
                $this->logCacheOperation('DEL', $key);
            }
            
            return $result;
        } catch (ConnectionException $e) {
            error_log('Redis DELETE error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check if key exists
     */
    public function exists(string $key): bool
    {
        try {
            return $this->getClient()->exists($key) > 0;
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * Increment counter (useful for rate limiting)
     */
    public function increment(string $key, int $by = 1): int
    {
        try {
            return $this->getClient()->incrby($key, $by);
        } catch (ConnectionException $e) {
            error_log('Redis INCREMENT error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Set expiration for key
     */
    public function expire(string $key, int $seconds): bool
    {
        try {
            return $this->getClient()->expire($key, $seconds) > 0;
        } catch (ConnectionException $e) {
            return false;
        }
    }

    /**
     * Flush all cache (use carefully!)
     */
    public function flushAll(): bool
    {
        try {
            $result = $this->getClient()->flushall();
            return $result->getPayload() === 'OK';
        } catch (ConnectionException $e) {
            error_log('Redis FLUSHALL error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        try {
            $info = $this->getClient()->info('stats');
            $memory = $this->getClient()->info('memory');
            
            return [
                'hits' => $info['keyspace_hits'] ?? 0,
                'misses' => $info['keyspace_misses'] ?? 0,
                'hit_rate' => $this->calculateHitRate($info),
                'memory_used' => $memory['used_memory_human'] ?? '0B',
                'connected_clients' => $info['connected_clients'] ?? 0,
                'is_connected' => $this->isConnected()
            ];
        } catch (ConnectionException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Cache invalidation patterns for inventory
     */
    public function invalidateInventoryCache(int $productId = null): void
    {
        $patterns = [
            'inventory:list:*',
            'inventory:search:*',
            'analytics:*'
        ];
        
        if ($productId !== null) {
            $patterns[] = "inventory:product:{$productId}";
        }
        
        foreach ($patterns as $pattern) {
            $keys = $this->getClient()->keys($pattern);
            if (!empty($keys)) {
                $this->delete($keys);
            }
        }
    }

    /**
     * Rate limiting helper
     */
    public function rateLimit(string $identifier, int $maxRequests, int $window): bool
    {
        $key = "rate_limit:{$identifier}";
        $current = $this->increment($key);
        
        if ($current === 1) {
            $this->expire($key, $window);
        }
        
        return $current <= $maxRequests;
    }

    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(array $stats): float
    {
        $hits = $stats['keyspace_hits'] ?? 0;
        $misses = $stats['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0.0;
    }

    /**
     * Log cache operations for monitoring
     */
    private function logCacheOperation(string $operation, string $key, int $size = 0): void
    {
        if ($_ENV['API_DEBUG'] ?? false) {
            $logData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'operation' => $operation,
                'key' => $key,
                'size' => $size
            ];
            error_log(json_encode($logData), 3, __DIR__ . '/../../logs/cache.log');
        }
    }
}