-- Create database
CREATE DATABASE billing_management;
USE billing_management;

-- Users table for authentication
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'cashier', 'manager') DEFAULT 'cashier',
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Products table
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT DEFAULT 0,
    category VARCHAR(50) DEFAULT 'Others',
    description TEXT,
    barcode VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Customers table
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    total_orders INT DEFAULT 0,
    total_spent DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Bills table
CREATE TABLE bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_number VARCHAR(50) UNIQUE NOT NULL,
    customer_id INT,
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    customer_address TEXT,
    subtotal DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    tax_amount DECIMAL(10,2) DEFAULT 0.00,
    shipping_cost DECIMAL(10,2) DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'upi', 'credit') DEFAULT 'cash',
    payment_status ENUM('pending', 'paid', 'partial') DEFAULT 'paid',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Bill items table
CREATE TABLE bill_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(200) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(10,2) DEFAULT 0.00,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Settings table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-Insert default users
INSERT INTO users (username, password, role, full_name, email) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', 'admin@example.com'),
('cashier', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'cashier', 'Cashier User', 'cashier@example.com'),
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 'Manager User', 'manager@example.com');

-- Insert sample products
INSERT INTO products (name, price, stock, category, description) VALUES
('Laptop Dell Inspiron', 45000.00, 10, 'Electronics', 'Dell Inspiron 15 3000 Series'),
('Wireless Mouse', 800.00, 50, 'Accessories', 'Logitech Wireless Mouse'),
('USB Cable', 200.00, 100, 'Accessories', 'USB Type-C Cable'),
('Headphones', 1500.00, 25, 'Electronics', 'Sony Wired Headphones'),
('Keyboard', 1200.00, 30, 'Accessories', 'Mechanical Keyboard'),
('Monitor', 12000.00, 15, 'Electronics', '24 inch LED Monitor'),
('Webcam', 2500.00, 20, 'Electronics', 'HD Webcam 1080p'),
('Phone Case', 300.00, 75, 'Accessories', 'Protective Phone Case'),
('Power Bank', 1800.00, 40, 'Electronics', '10000mAh Power Bank'),
('Tablet', 25000.00, 8, 'Electronics', 'Android Tablet 10 inch');

-- Insert default settings
INSERT INTO settings (setting_key, setting_value) VALUES
('store_name', 'My Store'),
('store_address', '123 Main Street, City, State'),
('store_phone', '+1234567890'),
('store_email', 'store@example.com'),
('tax_rate', '10.00'),
('tax_name', 'VAT'),
('currency_symbol', 'Rs.'),
('theme', 'light');

-- Create indexes for better performance
CREATE INDEX idx_bills_date ON bills(created_at);
CREATE INDEX idx_bills_customer ON bills(customer_id);
CREATE INDEX idx_bill_items_bill ON bill_items(bill_id);
CREATE INDEX idx_products_category ON products(category);
CREATE INDEX idx_customers_phone ON customers(phone);
CREATE INDEX idx_users_username ON users(username);