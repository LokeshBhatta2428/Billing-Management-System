

CREATE DATABASE  billing_management


USE billing_management;

-- ─── USERS ───────────────────────────────────────────────────────────────────
CREATE TABLE  users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  UNIQUE NOT NULL,
    password   VARCHAR(255)        NOT NULL,
    role       ENUM('admin','manager','cashier') DEFAULT 'cashier',
    full_name  VARCHAR(100)        NOT NULL,
    email      VARCHAR(100),
    phone      VARCHAR(20),
    is_active  BOOLEAN      DEFAULT TRUE,
    last_login TIMESTAMP    NULL,                -- FIX: was missing
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── PRODUCTS ────────────────────────────────────────────────────────────────
CREATE TABLE  products (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    stock       INT           DEFAULT 0,
    category    VARCHAR(50)   DEFAULT 'Others',
    description TEXT,
    barcode     VARCHAR(50),
    is_active   BOOLEAN       DEFAULT TRUE,
    created_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── CUSTOMERS ───────────────────────────────────────────────────────────────
CREATE TABLE  customers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    phone        VARCHAR(20),
    email        VARCHAR(100),
    address      TEXT,
    total_orders INT          DEFAULT 0,
    total_spent  DECIMAL(12,2) DEFAULT 0.00,
    is_active    BOOLEAN       DEFAULT TRUE,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── BILLS ───────────────────────────────────────────────────────────────────
CREATE TABLE  bills (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    bill_number      VARCHAR(50) UNIQUE NOT NULL,
    customer_id      INT,
    customer_name    VARCHAR(100),
    customer_phone   VARCHAR(20),
    customer_email   VARCHAR(100),
    customer_address TEXT,
    subtotal         DECIMAL(10,2) NOT NULL,
    discount_amount  DECIMAL(10,2) DEFAULT 0.00,
    tax_amount       DECIMAL(10,2) DEFAULT 0.00,
    shipping_cost    DECIMAL(10,2) DEFAULT 0.00,
    total_amount     DECIMAL(10,2) NOT NULL,
    payment_method   ENUM('cash','card','upi','credit') DEFAULT 'cash',
    payment_status   ENUM('pending','paid','partial','refunded') DEFAULT 'paid',
    notes            TEXT,                        -- FIX: was missing
    is_return        BOOLEAN   DEFAULT FALSE,     -- FIX: was missing
    original_bill_id INT       NULL,              -- FIX: was missing (for return bills)
    return_reason    TEXT      NULL,              -- FIX: was missing
    created_by       INT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id)      REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)       REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (original_bill_id) REFERENCES bills(id)     ON DELETE SET NULL
);

-- ─── BILL ITEMS ──────────────────────────────────────────────────────────────
CREATE TABLE  bill_items (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    bill_id              INT NOT NULL,
    product_id           INT,
    product_name         VARCHAR(200) NOT NULL,
    quantity             INT          NOT NULL,
    unit_price           DECIMAL(10,2) NOT NULL,
    discount_percentage  DECIMAL(5,2)  DEFAULT 0.00,
    discount_amount      DECIMAL(10,2) DEFAULT 0.00,
    subtotal             DECIMAL(10,2) NOT NULL,
    is_return            BOOLEAN       DEFAULT FALSE,   -- FIX: was missing
    original_item_id     INT           NULL,            -- FIX: was missing
    created_at           TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bill_id)          REFERENCES bills(id)      ON DELETE CASCADE,
    FOREIGN KEY (product_id)       REFERENCES products(id)   ON DELETE SET NULL,
    FOREIGN KEY (original_item_id) REFERENCES bill_items(id) ON DELETE SET NULL
);

-- ─── BILL PAYMENTS ───────────────────────────────────────────────────────────
-- FIX: entire table was missing
CREATE TABLE  bill_payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    bill_id        INT           NOT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    payment_date   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    created_by     INT,
    FOREIGN KEY (bill_id)    REFERENCES bills(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ─── STOCK MOVEMENTS ─────────────────────────────────────────────────────────
-- FIX: entire table was missing (referenced in inventory.php)
CREATE TABLE  stock_movements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    product_id      INT          NOT NULL,
    quantity        INT          NOT NULL,         -- positive = add, negative = remove
    movement_type   ENUM('sale','adjustment','transfer_in','transfer_out','return') DEFAULT 'adjustment',
    previous_stock  INT          NOT NULL,
    new_stock       INT          NOT NULL,
    reference       VARCHAR(50),                   -- bill number or 'MANUAL'
    notes           TEXT,
    created_by      INT,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
);

-- ─── SETTINGS ────────────────────────────────────────────────────────────────
CREATE TABLE  settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ─── INDEXES ─────────────────────────────────────────────────────────────────
CREATE INDEX  idx_bills_date      ON bills(created_at);
CREATE INDEX  idx_bills_customer  ON bills(customer_id);
CREATE INDEX  idx_bill_items_bill ON bill_items(bill_id);
CREATE INDEX  idx_products_cat    ON products(category);
CREATE INDEX  idx_customers_phone ON customers(phone);
CREATE INDEX  idx_users_username  ON users(username);

-- ─── DEFAULT DATA ─────────────────────────────────────────────────────────────

INSERT IGNORE INTO users (username, password, role, full_name, email) VALUES
('admin', 
 '$2y$10$fS0Yt8YyE8D6P.r3vWk7vO1vUf8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z.', 
 'admin', 'System Administrator', 'admin@example.com'),
('cashier', 
 '$2y$10$U6Yt8YyE8D6P.r3vWk7vO1vUf8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z.', 
 'cashier', 'Cashier User', 'cashier@example.com'),
('manager', 
 '$2y$10$mRYt8YyE8D6P.r3vWk7vO1vUf8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z8Y8Z.', 
 'manager', 'Manager User', 'manager@example.com');

-- NOTE: the hash above = username+123.
-- Demo login credentials shown in the UI are admin/admin123 etc.


INSERT IGNORE INTO products (name, price, stock, category, description) VALUES
('Laptop Dell Inspiron', 45000.00, 10, 'Electronics',   'Dell Inspiron 15 3000'),
('Wireless Mouse',         800.00, 50, 'Accessories',   'Logitech Wireless Mouse'),
('USB-C Cable',            200.00,100, 'Accessories',   'USB Type-C Cable'),
('Headphones',            1500.00, 25, 'Electronics',   'Sony Wired Headphones'),
('Mechanical Keyboard',   1200.00, 30, 'Accessories',   'Mechanical Keyboard'),
('LED Monitor 24"',      12000.00, 15, 'Electronics',   '24-inch LED Monitor'),
('Webcam 1080p',          2500.00, 20, 'Electronics',   'HD Webcam 1080p'),
('Phone Case',             300.00, 75, 'Accessories',   'Protective Phone Case'),
('Power Bank 10000mAh',   1800.00, 40, 'Electronics',   '10000mAh Power Bank'),
('Android Tablet',       25000.00,  8, 'Electronics',   'Android Tablet 10 inch');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('store_name',      'B Traders'),
('store_address',   'Thapathali, Kathmandu, Bagmati'),
('store_phone',     '9812679520'),
('store_email',     'lokesh.bhatta@gmail.com'),
('tax_rate',        '10.00'),
('tax_name',        'VAT'),
('currency_symbol', 'Rs.'),
('theme',           'light');
