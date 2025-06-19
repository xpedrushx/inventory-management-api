-- Inventory Management API Database Schema
-- Optimized for high-performance operations

USE inventory_api;

-- Products table with optimized indexes
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    category VARCHAR(100) DEFAULT 'general',
    description TEXT,
    quantity INT DEFAULT 0,
    price DECIMAL(10,2) DEFAULT 0.00,
    cost DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active', 'inactive', 'deleted') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes for performance
    INDEX idx_category (category),
    INDEX idx_status (status),
    INDEX idx_quantity (quantity),
    INDEX idx_updated (updated_at),
    INDEX idx_category_status (category, status),
    
    -- Full-text search index
    FULLTEXT idx_search (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table for authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager', 'employee') DEFAULT 'employee',
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock movements table for audit trail
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT,
    movement_type ENUM('in', 'out', 'adjustment') NOT NULL,
    quantity INT NOT NULL,
    previous_quantity INT NOT NULL,
    new_quantity INT NOT NULL,
    reason VARCHAR(255),
    reference VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_product (product_id),
    INDEX idx_movement_type (movement_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API logs table for monitoring
CREATE TABLE api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    response_code INT,
    response_time DECIMAL(8,3),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_endpoint (endpoint),
    INDEX idx_response_code (response_code),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample data
INSERT INTO users (username, email, password_hash, role) VALUES
('admin', 'admin@inventory.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('manager', 'manager@inventory.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager'),
('employee', 'employee@inventory.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee');

-- Insert sample products
INSERT INTO products (name, sku, category, description, quantity, price, cost) VALUES
('Laptop Dell XPS 13', 'DELL-XPS13-001', 'electronics', 'High-performance ultrabook with Intel i7 processor', 25, 1299.99, 899.99),
('iPhone 15 Pro', 'APPLE-IP15P-128', 'electronics', 'Latest iPhone with A17 Pro chip and titanium design', 50, 999.99, 699.99),
('Samsung Galaxy S24', 'SAMSUNG-GS24-256', 'electronics', 'Android flagship with AI features', 30, 899.99, 599.99),
('MacBook Pro 14"', 'APPLE-MBP14-M3', 'electronics', 'Professional laptop with M3 chip', 15, 1999.99, 1399.99),
('Sony WH-1000XM5', 'SONY-WH1000XM5', 'audio', 'Premium noise-cancelling headphones', 75, 399.99, 199.99),
('iPad Air', 'APPLE-IPAD-AIR-64', 'tablets', 'Versatile tablet for work and creativity', 40, 599.99, 399.99),
('Gaming Mouse Logitech', 'LOGI-GMX-RGB', 'accessories', 'High-precision gaming mouse with RGB lighting', 100, 79.99, 39.99),
('USB-C Hub', 'GENERIC-USBC-HUB', 'accessories', 'Multi-port USB-C hub for connectivity', 200, 49.99, 19.99),
('Wireless Charger', 'GENERIC-QI-CHARGE', 'accessories', 'Fast wireless charging pad', 150, 29.99, 12.99),
('Bluetooth Speaker', 'JBL-FLIP6-BLU', 'audio', 'Portable waterproof speaker', 60, 129.99, 69.99);

-- Insert sample stock movements
INSERT INTO stock_movements (product_id, movement_type, quantity, previous_quantity, new_quantity, reason) VALUES
(1, 'in', 25, 0, 25, 'Initial stock'),
(2, 'in', 50, 0, 50, 'Initial stock'),
(3, 'in', 30, 0, 30, 'Initial stock'),
(4, 'in', 15, 0, 15, 'Initial stock'),
(5, 'in', 75, 0, 75, 'Initial stock');

-- Create performance optimization procedures
DELIMITER //

CREATE PROCEDURE GetInventoryMetrics()
BEGIN
    SELECT 
        'total_products' as metric, COUNT(*) as value FROM products WHERE status != 'deleted'
    UNION ALL
    SELECT 
        'low_stock_products' as metric, COUNT(*) as value FROM products WHERE quantity <= 10 AND status = 'active'
    UNION ALL
    SELECT 
        'total_value' as metric, SUM(quantity * cost) as value FROM products WHERE status != 'deleted';
END //

CREATE PROCEDURE GetTopCategories()
BEGIN
    SELECT 
        category,
        COUNT(*) as product_count,
        SUM(quantity) as total_stock,
        SUM(quantity * cost) as total_value
    FROM products 
    WHERE status != 'deleted'
    GROUP BY category
    ORDER BY total_value DESC
    LIMIT 10;
END //

DELIMITER ;

-- Create views for common queries
CREATE VIEW low_stock_view AS
SELECT 
    id, name, sku, category, quantity, price,
    CASE 
        WHEN quantity = 0 THEN 'out_of_stock'
        WHEN quantity <= 5 THEN 'critical'
        WHEN quantity <= 10 THEN 'low'
        ELSE 'normal'
    END as stock_status
FROM products 
WHERE status = 'active' AND quantity <= 10;

CREATE VIEW inventory_summary AS
SELECT 
    category,
    COUNT(*) as total_products,
    SUM(quantity) as total_stock,
    AVG(quantity) as avg_stock,
    SUM(quantity * cost) as inventory_value,
    COUNT(CASE WHEN quantity <= 10 THEN 1 END) as low_stock_count
FROM products 
WHERE status != 'deleted'
GROUP BY category;