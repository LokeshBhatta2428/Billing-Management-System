<?php

require_once 'config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = sanitize($_GET['action'] ?? '');

// ─── ROUTER ───────────────────────────────────────────────────────────────────
switch ($action) {
    // Auth
    case 'login':   handleLogin();         break;
    case 'logout':  handleLogout();        break;
    case 'session': handleCheckSession();  break;

    // App data (login required — enforced inside each handler)
    case 'dashboard': handleDashboard();  break;
    case 'products':  handleProducts();   break;
    case 'customers': handleCustomers();  break;
    case 'bills':     handleBills();      break;
    case 'reports':   handleReports();    break;
    case 'settings':  handleSettings();   break;
    case 'users':     handleUsers();      break;

    default:
        jsonResponse(['success' => false, 'message' => 'Invalid endpoint'], 404);
}

// ═══════════════════════════════════════════════════════════════════════════════
// AUTH
// ═══════════════════════════════════════════════════════════════════════════════
function handleLogin(): void {
    global $method;
    if ($method !== 'POST') jsonResponse(['success' => false, 'message' => 'POST required'], 405);

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$password) {
        jsonResponse(['success' => false, 'message' => 'Username and password required'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && $password === $user['password']) {
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['user_role']     = $user['role'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['last_activity'] = time();

        // Update last login timestamp
        $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
           ->execute([$user['id']]);

        jsonResponse([
            'success'    => true,
            'message'    => 'Login successful',
            'user'       => [
                'id'        => $user['id'],
                'username'  => $user['username'],
                'role'      => $user['role'],
                'full_name' => $user['full_name'],
            ],
            'csrf_token' => generateCSRFToken(),
        ]);
    }

    jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
}

function handleLogout(): void {
    session_destroy();
    jsonResponse(['success' => true, 'message' => 'Logged out']);
}

function handleCheckSession(): void {
    if (isLoggedIn()) {
        jsonResponse([
            'success'    => true,
            'user'       => [
                'id'        => $_SESSION['user_id'],
                'username'  => $_SESSION['username'],
                'role'      => $_SESSION['user_role'],
                'full_name' => $_SESSION['full_name'],
            ],
            'csrf_token' => generateCSRFToken(),
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════════════════
function handleDashboard(): void {
    requireLogin();
    $db = getDB();

    // Today's sales
    $stmt = $db->query("SELECT COUNT(*) as bill_count, COALESCE(SUM(total_amount),0) as total_sales
                        FROM bills WHERE DATE(created_at) = CURDATE()");
    $todaySales = $stmt->fetch();

    // Monthly sales
    $stmt = $db->query("SELECT COUNT(*) as bill_count, COALESCE(SUM(total_amount),0) as total_sales
                        FROM bills
                        WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())");
    $monthlySales = $stmt->fetch();

    // Total customers
    $totalCustomers = $db->query("SELECT COUNT(*) FROM customers WHERE is_active=1")->fetchColumn();

    // Low-stock count (<=5)
    $lowStockCount = $db->query("SELECT COUNT(*) FROM products WHERE is_active=1 AND stock<=5")->fetchColumn();

    // Recent 5 bills
    $stmt = $db->query("SELECT b.id, b.bill_number, b.customer_name, b.total_amount,
                               b.payment_method, b.created_at, u.username AS cashier
                        FROM bills b LEFT JOIN users u ON b.created_by=u.id
                        ORDER BY b.created_at DESC LIMIT 5");
    $recentBills = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'data'    => [
            'today_sales'     => $todaySales,
            'monthly_sales'   => $monthlySales,
            'total_customers' => (int)$totalCustomers,
            'low_stock_count' => (int)$lowStockCount,
            'recent_bills'    => $recentBills,
        ],
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRODUCTS
// ═══════════════════════════════════════════════════════════════════════════════
function handleProducts(): void {
    requireLogin();
    global $method;

    switch ($method) {
        case 'GET':    getProducts();    break;
        case 'POST':   createProduct();  break;
        case 'PUT':    updateProduct();  break;
        case 'DELETE': deleteProduct();  break;
        default: jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function getProducts(): void {
    $db     = getDB();
    $search = sanitize($_GET['search'] ?? '');
    $id     = intval($_GET['id'] ?? 0);

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id=? AND is_active=1");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if ($p) jsonResponse(['success' => true, 'product' => $p]);
        jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
    }

    if ($search) {
        $stmt = $db->prepare("SELECT id,name,price,stock,category,barcode
                              FROM products WHERE is_active=1
                              AND (name LIKE ? OR barcode LIKE ?)
                              ORDER BY name LIMIT 50");
        $term = "%$search%";
        $stmt->execute([$term, $term]);
        jsonResponse(['success' => true, 'products' => $stmt->fetchAll()]);
    }

    $stmt = $db->query("SELECT id,name,price,stock,category,barcode
                        FROM products WHERE is_active=1 ORDER BY name");
    jsonResponse(['success' => true, 'products' => $stmt->fetchAll()]);
}

function createProduct(): void {
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $name     = sanitize($input['name']     ?? '');
    $price    = floatval($input['price']    ?? 0);
    $stock    = intval($input['stock']      ?? 0);
    $category = sanitize($input['category'] ?? 'Others');
    $desc     = sanitize($input['description'] ?? '');
    $barcode  = sanitize($input['barcode']  ?? '');

    if (!$name || $price <= 0) {
        jsonResponse(['success' => false, 'message' => 'Name and a valid price are required'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO products (name,price,stock,category,description,barcode)
                          VALUES (?,?,?,?,?,?)");
    $stmt->execute([$name, $price, $stock, $category, $desc, $barcode]);

    jsonResponse(['success' => true, 'message' => 'Product created', 'product_id' => $db->lastInsertId()]);
}

function updateProduct(): void {
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = intval($input['id']         ?? 0);
    $name     = sanitize($input['name']     ?? '');
    $price    = floatval($input['price']    ?? 0);
    $stock    = intval($input['stock']      ?? 0);
    $category = sanitize($input['category'] ?? 'Others');
    $desc     = sanitize($input['description'] ?? '');
    $barcode  = sanitize($input['barcode']  ?? '');

    if (!$id || !$name || $price <= 0) {
        jsonResponse(['success' => false, 'message' => 'ID, name, and valid price required'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("UPDATE products SET name=?,price=?,stock=?,category=?,description=?,barcode=?
                          WHERE id=? AND is_active=1");
    $stmt->execute([$name, $price, $stock, $category, $desc, $barcode, $id]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'Product updated']);
    jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
}

function deleteProduct(): void {
    if (!checkPermission('admin')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);

    $db   = getDB();
    $stmt = $db->prepare("UPDATE products SET is_active=0 WHERE id=?");
    $stmt->execute([$id]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'Product deleted']);
    jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
}

// ═══════════════════════════════════════════════════════════════════════════════
// CUSTOMERS
// ═══════════════════════════════════════════════════════════════════════════════
function handleCustomers(): void {
    requireLogin();
    global $method;

    switch ($method) {
        case 'GET':    getCustomers();    break;
        case 'POST':   createCustomer();  break;
        case 'PUT':    updateCustomer();  break;
        case 'DELETE': deleteCustomer();  break;
        default: jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function getCustomers(): void {
    $db     = getDB();
    $search = sanitize($_GET['search'] ?? '');
    $id     = intval($_GET['id'] ?? 0);

    if ($id) {
        $stmt = $db->prepare("SELECT * FROM customers WHERE id=? AND is_active=1");
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if ($c) {
            $orders = $db->prepare("SELECT id,bill_number,created_at,total_amount
                                    FROM bills WHERE customer_id=?
                                    ORDER BY created_at DESC LIMIT 10");
            $orders->execute([$id]);
            $c['orders'] = $orders->fetchAll();
            jsonResponse(['success' => true, 'customer' => $c]);
        }
        jsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
    }

    if ($search) {
        $term = "%$search%";
        $stmt = $db->prepare("SELECT * FROM customers WHERE is_active=1
                              AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
                              ORDER BY name LIMIT 20");
        $stmt->execute([$term, $term, $term]);
        jsonResponse(['success' => true, 'customers' => $stmt->fetchAll()]);
    }

    $stmt = $db->query("SELECT * FROM customers WHERE is_active=1 ORDER BY name");
    jsonResponse(['success' => true, 'customers' => $stmt->fetchAll()]);
}

function createCustomer(): void {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $name    = sanitize($input['name']    ?? '');
    $phone   = sanitize($input['phone']   ?? '');
    $email   = sanitize($input['email']   ?? '');
    $address = sanitize($input['address'] ?? '');

    if (!$name || !$phone) {
        jsonResponse(['success' => false, 'message' => 'Name and phone required'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO customers (name,phone,email,address) VALUES (?,?,?,?)");
    $stmt->execute([$name, $phone, $email, $address]);

    jsonResponse(['success' => true, 'message' => 'Customer created', 'customer_id' => $db->lastInsertId()]);
}

function updateCustomer(): void {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $id      = intval($input['id']      ?? 0);
    $name    = sanitize($input['name']    ?? '');
    $phone   = sanitize($input['phone']   ?? '');
    $email   = sanitize($input['email']   ?? '');
    $address = sanitize($input['address'] ?? '');

    if (!$id || !$name || !$phone) {
        jsonResponse(['success' => false, 'message' => 'ID, name, and phone required'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("UPDATE customers SET name=?,phone=?,email=?,address=? WHERE id=? AND is_active=1");
    $stmt->execute([$name, $phone, $email, $address, $id]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'Customer updated']);
    jsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
}

function deleteCustomer(): void {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);

    $db   = getDB();
    $stmt = $db->prepare("UPDATE customers SET is_active=0 WHERE id=?");
    $stmt->execute([$id]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'Customer deleted']);
    jsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
}

// ═══════════════════════════════════════════════════════════════════════════════
// BILLS
// ═══════════════════════════════════════════════════════════════════════════════
function handleBills(): void {
    requireLogin();
    global $method;

    switch ($method) {
        case 'GET':    getBills();    break;
        case 'POST':   createBill();  break;
        case 'PUT':    updateBill();  break;
        case 'DELETE': deleteBill();  break;
        default: jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function getBills(): void {
    $db   = getDB();
    $id   = intval($_GET['id'] ?? 0);

    // Single bill with items
    if ($id) {
        $stmt = $db->prepare("SELECT b.*, u.username AS cashier_name
                              FROM bills b LEFT JOIN users u ON b.created_by=u.id
                              WHERE b.id=?");
        $stmt->execute([$id]);
        $bill = $stmt->fetch();
        if (!$bill) jsonResponse(['success' => false, 'message' => 'Bill not found'], 404);

        $items = $db->prepare("SELECT bi.* FROM bill_items bi WHERE bi.bill_id=?");
        $items->execute([$id]);
        $bill['items'] = $items->fetchAll();
        jsonResponse(['success' => true, 'bill' => $bill]);
    }

    // List / search
    $search = sanitize($_GET['search'] ?? '');
    $sql    = "SELECT b.*, u.username AS cashier_name
               FROM bills b LEFT JOIN users u ON b.created_by=u.id WHERE 1=1";
    $params = [];

    if ($search) {
        $sql    .= " AND (b.bill_number LIKE ? OR b.customer_name LIKE ?)";
        $term    = "%$search%";
        $params  = [$term, $term];
    }
    $sql .= " ORDER BY b.created_at DESC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'bills' => $stmt->fetchAll()]);
}

function createBill(): void {
    if (!checkPermission('cashier')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $input         = json_decode(file_get_contents('php://input'), true) ?? [];
    $customerId    = intval($input['customer_id'] ?? 0) ?: null;
    $customerName  = sanitize($input['customer_name']    ?? 'Walk-in Customer');
    $customerPhone = sanitize($input['customer_phone']   ?? '');
    $customerEmail = sanitize($input['customer_email']   ?? '');
    $customerAddr  = sanitize($input['customer_address'] ?? '');
    $items         = $input['items'] ?? [];
    $subtotal      = floatval($input['subtotal']      ?? 0);
    $discountAmt   = floatval($input['discount_amount'] ?? 0);
    $taxAmt        = floatval($input['tax_amount']    ?? 0);
    $shippingCost  = floatval($input['shipping_cost'] ?? 0);
    $totalAmount   = floatval($input['total_amount']  ?? 0);
    $payMethod     = sanitize($input['payment_method'] ?? 'cash');
    $payStatus     = sanitize($input['payment_status'] ?? 'paid');
    $userId        = $_SESSION['user_id'];

    if (empty($items) || $totalAmount <= 0) {
        jsonResponse(['success' => false, 'message' => 'Bill must have items and a positive total'], 400);
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $billNumber = generateBillNumber('BILL');

        $stmt = $db->prepare("INSERT INTO bills
            (bill_number,customer_id,customer_name,customer_phone,customer_email,customer_address,
             subtotal,discount_amount,tax_amount,shipping_cost,total_amount,
             payment_method,payment_status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $billNumber, $customerId, $customerName, $customerPhone, $customerEmail, $customerAddr,
            $subtotal, $discountAmt, $taxAmt, $shippingCost, $totalAmount,
            $payMethod, $payStatus, $userId,
        ]);
        $billId = $db->lastInsertId();

        $itemStmt = $db->prepare("INSERT INTO bill_items
            (bill_id,product_id,product_name,quantity,unit_price,
             discount_percentage,discount_amount,subtotal)
            VALUES (?,?,?,?,?,?,?,?)");

        foreach ($items as $item) {
            $productId   = intval($item['product_id'] ?? 0) ?: null;
            $productName = sanitize($item['product_name']    ?? '');
            $qty         = intval($item['quantity']          ?? 0);
            $unitPrice   = floatval($item['unit_price']      ?? 0);
            $discPct     = floatval($item['discount_percentage'] ?? 0);
            $discAmt     = floatval($item['discount_amount'] ?? 0);
            $itemSub     = floatval($item['subtotal']        ?? 0);

            $itemStmt->execute([$billId, $productId, $productName, $qty, $unitPrice,
                                $discPct, $discAmt, $itemSub]);

            // Reduce stock
            if ($productId) {
                $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?")
                   ->execute([$qty, $productId, $qty]);
            }
        }

        // Update customer stats
        if ($customerId) {
            $db->prepare("UPDATE customers
                          SET total_orders = total_orders + 1, total_spent = total_spent + ?
                          WHERE id = ?")
               ->execute([$totalAmount, $customerId]);
        }

        $db->commit();
        jsonResponse([
            'success'     => true,
            'message'     => 'Bill created successfully',
            'bill_id'     => $billId,
            'bill_number' => $billNumber,
        ]);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("createBill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create bill'], 500);
    }
}

function updateBill(): void {
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $input         = json_decode(file_get_contents('php://input'), true) ?? [];
    $billId        = intval($input['bill_id']       ?? 0);
    $payMethod     = sanitize($input['payment_method'] ?? '');
    $payStatus     = sanitize($input['payment_status'] ?? '');
    $customerName  = sanitize($input['customer_name']  ?? '');

    if (!$billId) jsonResponse(['success' => false, 'message' => 'Bill ID required'], 400);

    $db   = getDB();
    $stmt = $db->prepare("UPDATE bills
                          SET customer_name=?, payment_method=?, payment_status=?
                          WHERE id=?");
    $stmt->execute([$customerName, $payMethod, $payStatus, $billId]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'Bill updated']);
    jsonResponse(['success' => false, 'message' => 'Bill not found'], 404);
}

function deleteBill(): void {
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $billId = intval($_GET['id'] ?? 0);
    if (!$billId) jsonResponse(['success' => false, 'message' => 'ID required'], 400);

    $db = getDB();
    $db->beginTransaction();
    try {
        // Restore stock for bill items (only non-return items)
        $items = $db->prepare("SELECT product_id, quantity, is_return
                               FROM bill_items WHERE bill_id=?");
        $items->execute([$billId]);
        foreach ($items->fetchAll() as $item) {
            if ($item['product_id'] && !$item['is_return']) {
                $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")
                   ->execute([$item['quantity'], $item['product_id']]);
            }
        }

        $db->prepare("DELETE FROM bill_items    WHERE bill_id=?")->execute([$billId]);
        $db->prepare("DELETE FROM bill_payments WHERE bill_id=?")->execute([$billId]);
        $db->prepare("DELETE FROM bills         WHERE id=?")->execute([$billId]);

        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Bill deleted']);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("deleteBill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete bill'], 500);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// REPORTS
// ═══════════════════════════════════════════════════════════════════════════════
function handleReports(): void {
    requireLogin();
    global $method;
    if ($method !== 'GET') jsonResponse(['success' => false, 'message' => 'GET required'], 405);

    $type = sanitize($_GET['type'] ?? 'sales');

    switch ($type) {
        case 'sales':     getSalesReport();     break;
        case 'inventory': getInventoryReport(); break;
        case 'customers': getCustomerReport();  break;
        case 'products':  getProductReport();   break;
        default: jsonResponse(['success' => false, 'message' => 'Invalid report type'], 400);
    }
}

function getSalesReport(): void {
    $db        = getDB();
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate   = sanitize($_GET['end_date']   ?? '');
    $groupBy   = sanitize($_GET['group_by']   ?? 'day');

    $params = [];
    $where  = '';
    if ($startDate && $endDate) {
        $where    = "WHERE DATE(created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }

    $groupExpr = match ($groupBy) {
        'week'  => "YEARWEEK(created_at, 1)",
        'month' => "DATE_FORMAT(created_at, '%Y-%m')",
        'year'  => "YEAR(created_at)",
        default => "DATE(created_at)",
    };
    $labelFmt = match ($groupBy) {
        'week'  => "%Y Week %v",
        'month' => "%Y-%m",
        'year'  => "%Y",
        default => "%Y-%m-%d",
    };

    $sql = "SELECT
                $groupExpr AS period,
                DATE_FORMAT(created_at, '$labelFmt') AS period_label,
                COUNT(*) AS bill_count,
                COALESCE(SUM(total_amount),0) AS total_sales,
                COALESCE(AVG(total_amount),0) AS avg_sale,
                COALESCE(SUM(CASE WHEN payment_method='cash'   THEN total_amount ELSE 0 END),0) AS cash_sales,
                COALESCE(SUM(CASE WHEN payment_method='card'   THEN total_amount ELSE 0 END),0) AS card_sales,
                COALESCE(SUM(CASE WHEN payment_method='upi'    THEN total_amount ELSE 0 END),0) AS upi_sales,
                COALESCE(SUM(CASE WHEN payment_method='credit' THEN total_amount ELSE 0 END),0) AS credit_sales
            FROM bills $where
            GROUP BY $groupExpr
            ORDER BY period";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'report' => $stmt->fetchAll(), 'group_by' => $groupBy]);
}

function getInventoryReport(): void {
    $db       = getDB();
    $category = sanitize($_GET['category']  ?? '');
    $lowStock = intval($_GET['low_stock']   ?? 0);

    $where  = "WHERE p.is_active=1";
    $params = [];
    if ($category) { $where .= " AND p.category=?"; $params[] = $category; }
    if ($lowStock)  { $where .= " AND p.stock<=?";   $params[] = $lowStock; }

    $sql = "SELECT p.id, p.name, p.price, p.stock, p.category,
                   COALESCE(COUNT(bi.id),0)     AS times_sold,
                   COALESCE(SUM(bi.quantity),0) AS total_qty_sold,
                   COALESCE(SUM(bi.subtotal),0) AS total_sales_amount
            FROM products p
            LEFT JOIN bill_items bi ON p.id=bi.product_id
            LEFT JOIN bills      b  ON bi.bill_id=b.id
            $where
            GROUP BY p.id
            ORDER BY p.stock ASC, p.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'report' => $stmt->fetchAll()]);
}

function getCustomerReport(): void {
    $db        = getDB();
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate   = sanitize($_GET['end_date']   ?? '');
    $minOrders = intval($_GET['min_orders']   ?? 1);

    $params = [];
    $dateCond = '';
    if ($startDate && $endDate) {
        $dateCond = "AND DATE(b.created_at) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    $params[] = $minOrders;

    $sql = "SELECT c.id, c.name, c.phone, c.email,
                   COUNT(b.id)              AS order_count,
                   COALESCE(SUM(b.total_amount),0) AS total_spent,
                   COALESCE(AVG(b.total_amount),0) AS avg_order_value,
                   MAX(b.created_at)        AS last_order_date
            FROM customers c
            JOIN bills b ON c.id=b.customer_id
            WHERE c.is_active=1 $dateCond
            GROUP BY c.id
            HAVING COUNT(b.id) >= ?
            ORDER BY total_spent DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'report' => $stmt->fetchAll()]);
}

function getProductReport(): void {
    $db        = getDB();
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate   = sanitize($_GET['end_date']   ?? '');
    $category  = sanitize($_GET['category']   ?? '');
    $limit     = intval($_GET['limit']        ?? 10);

    $params   = [];
    $dateCond = '';
    $catCond  = '';
    if ($startDate && $endDate) {
        $dateCond  = "AND DATE(b.created_at) BETWEEN ? AND ?";
        $params[]  = $startDate;
        $params[]  = $endDate;
    }
    if ($category) {
        $catCond  = "AND p.category=?";
        $params[] = $category;
    }
    $params[] = $limit;

    // BUG FIX: corrected parentheses in (SUM(bi.quantity) * p.price) and the division
    $sql = "SELECT p.id, p.name, p.category, p.price,
                   COUNT(bi.id)             AS times_sold,
                   COALESCE(SUM(bi.quantity),0) AS total_quantity,
                   COALESCE(SUM(bi.subtotal),0) AS total_sales,
                   (COALESCE(SUM(bi.quantity),0) * p.price) AS potential_sales,
                   CASE WHEN (COALESCE(SUM(bi.quantity),0) * p.price) > 0
                        THEN (COALESCE(SUM(bi.subtotal),0) / (COALESCE(SUM(bi.quantity),0) * p.price)) * 100
                        ELSE 100
                   END AS effective_pct
            FROM products p
            JOIN bill_items bi ON p.id=bi.product_id
            JOIN bills      b  ON bi.bill_id=b.id
            WHERE p.is_active=1 $dateCond $catCond
            GROUP BY p.id
            ORDER BY total_sales DESC
            LIMIT ?";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success' => true, 'report' => $stmt->fetchAll()]);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════════════════════
function handleSettings(): void {
    requireLogin();
    global $method;

    if ($method === 'GET') {
        $db   = getDB();
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $rows = $stmt->fetchAll();
        $out  = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        jsonResponse(['success' => true, 'settings' => $out]);
        return;
    }

    // POST/PUT — save settings (admin only)
    if (!checkPermission('admin')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }

    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $allowed = ['store_name','store_address','store_phone','store_email',
                'tax_rate','tax_name','currency_symbol','theme'];

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
                              VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $value = sanitize((string)$value);
            if ($key === 'tax_rate') {
                $v = floatval($value);
                if ($v < 0 || $v > 100) {
                    $db->rollBack();
                    jsonResponse(['success' => false, 'message' => 'Tax rate must be 0–100'], 400);
                }
                $value = (string)$v;
            }
            if ($key === 'store_email' && $value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $db->rollBack();
                jsonResponse(['success' => false, 'message' => 'Invalid email format'], 400);
            }
            $stmt->execute([$key, $value]);
        }
        $db->commit();
        jsonResponse(['success' => true, 'message' => 'Settings saved']);
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("saveSettings error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to save settings'], 500);
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// USERS  (admin only)
// ═══════════════════════════════════════════════════════════════════════════════
function handleUsers(): void {
    requireLogin();
    if (!checkPermission('admin')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    global $method;

    switch ($method) {
        case 'GET':    getUsers();    break;
        case 'POST':   createUser();  break;
        case 'PUT':    updateUser();  break;
        case 'DELETE': deleteUser();  break;
        default: jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function getUsers(): void {
    $db   = getDB();
    $id   = intval($_GET['id'] ?? 0);

    if ($id) {
        $stmt = $db->prepare("SELECT id,username,role,full_name,email,phone,is_active,last_login
                              FROM users WHERE id=?");
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if ($u) jsonResponse(['success' => true, 'user' => $u]);
        jsonResponse(['success' => false, 'message' => 'User not found'], 404);
    }

    $stmt = $db->query("SELECT id,username,role,full_name,email,phone,is_active,last_login
                        FROM users ORDER BY username");
    jsonResponse(['success' => true, 'users' => $stmt->fetchAll()]);
}

function createUser(): void {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = sanitize($input['username']  ?? '');
    $password = $input['password']           ?? '';
    $role     = sanitize($input['role']      ?? 'cashier');
    $fullName = sanitize($input['full_name'] ?? '');
    $email    = sanitize($input['email']     ?? '');
    $phone    = sanitize($input['phone']     ?? '');

    if (!$username || !$password || !$fullName) {
        jsonResponse(['success' => false, 'message' => 'Username, password, and full name required'], 400);
    }

    $db   = getDB();
    $dup  = $db->prepare("SELECT id FROM users WHERE username=?");
    $dup->execute([$username]);
    if ($dup->fetch()) jsonResponse(['success' => false, 'message' => 'Username already exists'], 400);

    $hash = $password;
    $stmt = $db->prepare("INSERT INTO users (username,password,role,full_name,email,phone)
                          VALUES (?,?,?,?,?,?)");
    $stmt->execute([$username, $hash, $role, $fullName, $email, $phone]);

    jsonResponse(['success' => true, 'message' => 'User created', 'user_id' => $db->lastInsertId()]);
}

function updateUser(): void {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = intval($input['id']        ?? 0);
    $username = sanitize($input['username']  ?? '');
    $role     = sanitize($input['role']      ?? '');
    $fullName = sanitize($input['full_name'] ?? '');
    $email    = sanitize($input['email']     ?? '');
    $phone    = sanitize($input['phone']     ?? '');
    $password = $input['password']           ?? '';
    $isActive = isset($input['is_active']) ? intval($input['is_active']) : null;

    if (!$id) jsonResponse(['success' => false, 'message' => 'User ID required'], 400);

    // Prevent deactivating own account
    if ($isActive === 0 && $id == $_SESSION['user_id']) {
        jsonResponse(['success' => false, 'message' => 'Cannot deactivate your own account'], 400);
    }

    $db = getDB();
    if ($username && $fullName) {
        // Check duplicate username
        $dup = $db->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $dup->execute([$username, $id]);
        if ($dup->fetch()) jsonResponse(['success' => false, 'message' => 'Username already taken'], 400);

        $db->prepare("UPDATE users SET username=?,role=?,full_name=?,email=?,phone=? WHERE id=?")
           ->execute([$username, $role, $fullName, $email, $phone, $id]);
    }

    if ($password) {
        $db->prepare("UPDATE users SET password=? WHERE id=?")
           ->execute([$password, $id]);
    }

    if ($isActive !== null) {
        $db->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$isActive, $id]);
    }

    jsonResponse(['success' => true, 'message' => 'User updated']);
}

function deleteUser(): void {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);
    if ($id == $_SESSION['user_id']) {
        jsonResponse(['success' => false, 'message' => 'Cannot delete your own account'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare("DELETE FROM users WHERE id=?");
    $stmt->execute([$id]);

    if ($stmt->rowCount()) jsonResponse(['success' => true, 'message' => 'User deleted']);
    jsonResponse(['success' => false, 'message' => 'User not found'], 404);
}
