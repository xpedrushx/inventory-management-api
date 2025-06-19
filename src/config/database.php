<?php

declare(strict_types=1);

namespace InventoryAPI\Config;

use PDO;
use PDOException;

/**
 * Database Configuration and Connection Manager
 * Optimized for high-performance with connection pooling
 */
class Database
{
    private ?PDO $connection = null;
    private array $config;
    private static ?self $instance = null;

    public function __construct()
    {
        $this->config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'inventory_api',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                PDO::ATTR_PERSISTENT => true, // Connection pooling
            ]
        ];
    }

    /**
     * Get singleton instance for connection pooling
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish database connection with retry logic
     */
    public function connect(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['database'],
            $this->config['charset']
        );

        $maxRetries = 3;
        $retryDelay = 1; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->connection = new PDO(
                    $dsn,
                    $this->config['username'],
                    $this->config['password'],
                    $this->config['options']
                );

                // Test connection
                $this->connection->query('SELECT 1');
                
                return $this->connection;
            } catch (PDOException $e) {
                if ($attempt === $maxRetries) {
                    throw new PDOException(
                        'Database connection failed after ' . $maxRetries . ' attempts: ' . $e->getMessage()
                    );
                }
                sleep($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }

        throw new PDOException('Unexpected database connection error');
    }

    /**
     * Get current connection
     */
    public function getConnection(): PDO
    {
        return $this->connect();
    }

    /**
     * Check if database is connected and responsive
     */
    public function isConnected(): bool
    {
        try {
            if ($this->connection === null) {
                $this->connect();
            }
            $this->connection->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Execute optimized query with caching considerations
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $startTime = microtime(true);
            
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            
            $executionTime = (microtime(true) - $startTime) * 1000; // ms
            
            // Log slow queries for optimization
            if ($executionTime > 100) {
                error_log("Slow query detected ({$executionTime}ms): " . $sql);
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log('Database query error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute INSERT/UPDATE/DELETE with affected rows count
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Database execute error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get last inserted ID
     */
    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->getConnection()->rollBack();
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        $this->connection = null;
    }

    /**
     * Get database statistics for monitoring
     */
    public function getStats(): array
    {
        try {
            $stats = $this->query('SHOW STATUS LIKE "Threads_connected"');
            $queries = $this->query('SHOW STATUS LIKE "Queries"');
            
            return [
                'connections' => $stats[0]['Value'] ?? 0,
                'total_queries' => $queries[0]['Value'] ?? 0,
                'is_connected' => $this->isConnected()
            ];
        } catch (PDOException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}