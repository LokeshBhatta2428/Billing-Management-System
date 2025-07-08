// bills.php
<?php
require_once 'config.php';

header('Content-Type: application/json');

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET':
        handleGetRequests($action);
        break;
    case 'POST':
        handlePostRequests($action);
        break;
    case 'PUT':
        handlePutRequests($action);
        break;
    case 'DELETE':
        handleDeleteRequests($action);
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

function handleGetRequests($action) {
    switch ($action) {
        case 'list':
            getBills();
            break;
        case 'search':
            searchBills();
            break;
        case 'get':
            getBill();
            break;
        case 'stats':
            getBillStats();
            break;
        case 'daily':
            getDailySales();
            break;
        default:
            getBills();
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'create':
            createBill();
            break;
        case 'return':
            createReturnBill();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update':
            updateBill();
            break;
        case 'payment':
            updatePaymentStatus();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleDeleteRequests($action) {
    switch ($action) {
        case 'delete':
            deleteBill();
            break;
        case 'item':
            deleteBillItem();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getBills() {
    global $db;
    
    try {
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT b.*, u.username as cashier_name 
                FROM bills b 
                LEFT JOIN users u ON b.created_by = u.id 
                ORDER BY b.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $bills = $stmt->fetchAll();
        
        // Get total count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM bills");
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        jsonResponse([
            'success' => true,
            'bills' => $bills,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Get bills error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch bills'], 500);
    }
}

function searchBills() {
    global $db;
    
    $query = sanitize($_GET['q'] ?? '');
    $date = sanitize($_GET['date'] ?? '');
    $customerId = intval($_GET['customer_id'] ?? 0);
    
    try {
        $sql = "SELECT b.*, u.username as cashier_name 
                FROM bills b 
                LEFT JOIN users u ON b.created_by = u.id 
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($query)) {
            $sql .= " AND (b.bill_number LIKE ? OR b.customer_name LIKE ?)";
            $searchTerm = "%{$query}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (!empty($date)) {
            $sql .= " AND DATE(b.created_at) = ?";
            $params[] = $date;
        }
        
        if ($customerId > 0) {
            $sql .= " AND b.customer_id = ?";
            $params[] = $customerId;
        }
        
        $sql .= " ORDER BY b.created_at DESC LIMIT 50";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $bills = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'bills' => $bills
        ]);
    } catch (PDOException $e) {
        error_log("Search bills error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Search failed'], 500);
    }
}

function getBill() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Bill ID required'], 400);
    }
    
    try {
        // Get bill header
        $stmt = $db->prepare("SELECT b.*, u.username as cashier_name FROM bills b LEFT JOIN users u ON b.created_by = u.id WHERE b.id = ?");
        $stmt->execute([$id]);
        $bill = $stmt->fetch();
        
        if (!$bill) {
            jsonResponse(['success' => false, 'message' => 'Bill not found'], 404);
        }
        
        // Get bill items
        $stmt = $db->prepare("SELECT bi.*, p.name as product_name, p.barcode 
                             FROM bill_items bi 
                             LEFT JOIN products p ON bi.product_id = p.id 
                             WHERE bi.bill_id = ?");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();
        
        $bill['items'] = $items;
        
        jsonResponse(['success' => true, 'bill' => $bill]);
    } catch (PDOException $e) {
        error_log("Get bill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch bill'], 500);
    }
}

function getBillStats() {
    global $db;
    
    $period = sanitize($_GET['period'] ?? 'day'); // day, week, month, year
    
    try {
        $dateCondition = "";
        switch ($period) {
            case 'day':
                $dateCondition = "DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $dateCondition = "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
                break;
            case 'month':
                $dateCondition = "YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())";
                break;
            case 'year':
                $dateCondition = "YEAR(created_at) = YEAR(CURDATE())";
                break;
        }
        
        // Total sales
        $stmt = $db->prepare("SELECT COUNT(*) as count, SUM(total_amount) as total FROM bills WHERE $dateCondition");
        $stmt->execute();
        $sales = $stmt->fetch();
        
        // Sales by payment method
        $stmt = $db->prepare("SELECT payment_method, COUNT(*) as count, SUM(total_amount) as total FROM bills WHERE $dateCondition GROUP BY payment_method");
        $stmt->execute();
        $paymentMethods = $stmt->fetchAll();
        
        // Top products
        $stmt = $db->prepare("SELECT p.name, SUM(bi.quantity) as total_quantity, SUM(bi.subtotal) as total_amount 
                              FROM bill_items bi 
                              JOIN bills b ON bi.bill_id = b.id 
                              JOIN products p ON bi.product_id = p.id 
                              WHERE $dateCondition 
                              GROUP BY bi.product_id 
                              ORDER BY total_quantity DESC 
                              LIMIT 5");
        $stmt->execute();
        $topProducts = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'stats' => [
                'total_bills' => $sales['count'] ?? 0,
                'total_sales' => $sales['total'] ?? 0,
                'payment_methods' => $paymentMethods,
                'top_products' => $topProducts
            ],
            'period' => $period
        ]);
    } catch (PDOException $e) {
        error_log("Get bill stats error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch stats'], 500);
    }
}

function getDailySales() {
    global $db;
    
    $days = intval($_GET['days'] ?? 7);
    
    try {
        $stmt = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total 
                              FROM bills 
                              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY) 
                              GROUP BY DATE(created_at) 
                              ORDER BY date");
        $stmt->execute([$days]);
        $dailySales = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'daily_sales' => $dailySales,
            'days' => $days
        ]);
    } catch (PDOException $e) {
        error_log("Get daily sales error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch daily sales'], 500);
    }
}

function createBill() {
    global $db;
    
    if (!checkPermission('cashier')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $customerId = intval($input['customer_id'] ?? 0);
    $customerName = sanitize($input['customer_name'] ?? 'Walk-in Customer');
    $customerPhone = sanitize($input['customer_phone'] ?? '');
    $customerEmail = sanitize($input['customer_email'] ?? '');
    $customerAddress = sanitize($input['customer_address'] ?? '');
    $items = $input['items'] ?? [];
    $subtotal = floatval($input['subtotal'] ?? 0);
    $discountAmount = floatval($input['discount_amount'] ?? 0);
    $taxAmount = floatval($input['tax_amount'] ?? 0);
    $shippingCost = floatval($input['shipping_cost'] ?? 0);
    $totalAmount = floatval($input['total_amount'] ?? 0);
    $paymentMethod = sanitize($input['payment_method'] ?? 'cash');
    $paymentStatus = sanitize($input['payment_status'] ?? 'paid');
    $userId = $_SESSION['user_id'];
    
    if (empty($items) || $totalAmount <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid bill data'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Generate bill number
        $billNumber = generateBillNumber();
        
        // Create bill header
        $stmt = $db->prepare("INSERT INTO bills (
            bill_number, customer_id, customer_name, customer_phone, customer_email, customer_address,
            subtotal, discount_amount, tax_amount, shipping_cost, total_amount,
            payment_method, payment_status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $billNumber, $customerId, $customerName, $customerPhone, $customerEmail, $customerAddress,
            $subtotal, $discountAmount, $taxAmount, $shippingCost, $totalAmount,
            $paymentMethod, $paymentStatus, $userId
        ]);
        
        $billId = $db->lastInsertId();
        
        // Add bill items
        foreach ($items as $item) {
            $productId = intval($item['product_id'] ?? null);
            $productName = sanitize($item['product_name'] ?? '');
            $quantity = intval($item['quantity'] ?? 0);
            $unitPrice = floatval($item['unit_price'] ?? 0);
            $discountPercentage = floatval($item['discount_percentage'] ?? 0);
            $discountAmount = floatval($item['discount_amount'] ?? 0);
            $subtotal = floatval($item['subtotal'] ?? 0);
            
            $stmt = $db->prepare("INSERT INTO bill_items (
                bill_id, product_id, product_name, quantity, unit_price,
                discount_percentage, discount_amount, subtotal
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->execute([
                $billId, $productId, $productName, $quantity, $unitPrice,
                $discountPercentage, $discountAmount, $subtotal
            ]);
            
            // Update product stock if product exists
            if ($productId) {
                $stmt = $db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$quantity, $productId]);
            }
        }
        
        // Update customer stats if customer exists
        if ($customerId) {
            $stmt = $db->prepare("UPDATE customers SET total_orders = total_orders + 1, total_spent = total_spent + ? WHERE id = ?");
            $stmt->execute([$totalAmount, $customerId]);
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Bill created successfully',
            'bill_id' => $billId,
            'bill_number' => $billNumber
        ]);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Create bill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create bill'], 500);
    }
}

function createReturnBill() {
    global $db;
    
    if (!checkPermission('cashier')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $originalBillId = intval($input['original_bill_id'] ?? 0);
    $reason = sanitize($input['reason'] ?? '');
    $items = $input['items'] ?? [];
    $userId = $_SESSION['user_id'];
    
    if ($originalBillId <= 0 || empty($items)) {
        jsonResponse(['success' => false, 'message' => 'Invalid return data'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get original bill
        $stmt = $db->prepare("SELECT * FROM bills WHERE id = ?");
        $stmt->execute([$originalBillId]);
        $originalBill = $stmt->fetch();
        
        if (!$originalBill) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Original bill not found'], 404);
        }
        
        // Calculate return amounts
        $subtotal = 0;
        $taxAmount = 0;
        $totalAmount = 0;
        
        foreach ($items as $item) {
            $originalItemId = intval($item['original_item_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            
            if ($originalItemId <= 0 || $quantity <= 0) {
                continue;
            }
            
            // Get original item details
            $stmt = $db->prepare("SELECT * FROM bill_items WHERE id = ? AND bill_id = ?");
            $stmt->execute([$originalItemId, $originalBillId]);
            $originalItem = $stmt->fetch();
            
            if (!$originalItem) {
                continue;
            }
            
            $returnAmount = ($originalItem['subtotal'] / $originalItem['quantity']) * $quantity;
            $subtotal += $returnAmount;
        }
        
        $taxAmount = ($subtotal * ($originalBill['tax_amount'] / $originalBill['subtotal']));
        $totalAmount = $subtotal + $taxAmount;
        
        // Generate return bill number
        $billNumber = generateBillNumber('RET-');
        
        // Create return bill
        $stmt = $db->prepare("INSERT INTO bills (
            bill_number, customer_id, customer_name, customer_phone, customer_email, customer_address,
            subtotal, discount_amount, tax_amount, shipping_cost, total_amount,
            payment_method, payment_status, created_by, is_return, original_bill_id, return_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
        
        $stmt->execute([
            $billNumber, $originalBill['customer_id'], $originalBill['customer_name'], 
            $originalBill['customer_phone'], $originalBill['customer_email'], $originalBill['customer_address'],
            $subtotal, 0, $taxAmount, 0, $totalAmount,
            $originalBill['payment_method'], 'refunded', $userId,
            $originalBillId, $reason
        ]);
        
        $returnBillId = $db->lastInsertId();
        
        // Add return items
        foreach ($items as $item) {
            $originalItemId = intval($item['original_item_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            
            if ($originalItemId <= 0 || $quantity <= 0) {
                continue;
            }
            
            // Get original item details
            $stmt = $db->prepare("SELECT * FROM bill_items WHERE id = ? AND bill_id = ?");
            $stmt->execute([$originalItemId, $originalBillId]);
            $originalItem = $stmt->fetch();
            
            if (!$originalItem) {
                continue;
            }
            
            $returnAmount = ($originalItem['subtotal'] / $originalItem['quantity']) * $quantity;
            
            $stmt = $db->prepare("INSERT INTO bill_items (
                bill_id, product_id, product_name, quantity, unit_price,
                discount_percentage, discount_amount, subtotal, is_return, original_item_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
            
            $stmt->execute([
                $returnBillId, $originalItem['product_id'], $originalItem['product_name'], 
                $quantity, $originalItem['unit_price'],
                $originalItem['discount_percentage'], $originalItem['discount_amount'], $returnAmount,
                $originalItemId
            ]);
            
            // Update product stock if product exists
            if ($originalItem['product_id']) {
                $stmt = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->execute([$quantity, $originalItem['product_id']]);
            }
        }
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Return bill created successfully',
            'bill_id' => $returnBillId,
            'bill_number' => $billNumber
        ]);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Create return bill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create return bill'], 500);
    }
}

function updateBill() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $billId = intval($input['bill_id'] ?? 0);
    $customerId = intval($input['customer_id'] ?? 0);
    $customerName = sanitize($input['customer_name'] ?? '');
    $customerPhone = sanitize($input['customer_phone'] ?? '');
    $customerEmail = sanitize($input['customer_email'] ?? '');
    $customerAddress = sanitize($input['customer_address'] ?? '');
    $paymentMethod = sanitize($input['payment_method'] ?? '');
    $paymentStatus = sanitize($input['payment_status'] ?? '');
    $notes = sanitize($input['notes'] ?? '');
    
    if ($billId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Bill ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("UPDATE bills SET 
            customer_id = ?, customer_name = ?, customer_phone = ?, customer_email = ?, customer_address = ?,
            payment_method = ?, payment_status = ?, notes = ?
            WHERE id = ?");
        
        $stmt->execute([
            $customerId, $customerName, $customerPhone, $customerEmail, $customerAddress,
            $paymentMethod, $paymentStatus, $notes,
            $billId
        ]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Bill updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Bill not found or no changes made'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update bill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update bill'], 500);
    }
}

function updatePaymentStatus() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $billId = intval($input['bill_id'] ?? 0);
    $paymentStatus = sanitize($input['payment_status'] ?? '');
    $amountPaid = floatval($input['amount_paid'] ?? 0);
    
    if ($billId <= 0 || empty($paymentStatus)) {
        jsonResponse(['success' => false, 'message' => 'Bill ID and payment status required'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get current bill
        $stmt = $db->prepare("SELECT * FROM bills WHERE id = ?");
        $stmt->execute([$billId]);
        $bill = $stmt->fetch();
        
        if (!$bill) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Bill not found'], 404);
        }
        
        // Update payment status
        $stmt = $db->prepare("UPDATE bills SET payment_status = ? WHERE id = ?");
        $stmt->execute([$paymentStatus, $billId]);
        
        // If payment is partial, record the payment
        if ($paymentStatus === 'partial' && $amountPaid > 0) {
            $stmt = $db->prepare("INSERT INTO bill_payments (bill_id, amount, payment_method, payment_date) 
                                 VALUES (?, ?, ?, NOW())");
            $stmt->execute([$billId, $amountPaid, $bill['payment_method']]);
        }
        
        $db->commit();
        
        jsonResponse(['success' => true, 'message' => 'Payment status updated successfully']);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Update payment status error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update payment status'], 500);
    }
}

function deleteBill() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $billId = intval($_GET['id'] ?? 0);
    
    if ($billId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Bill ID required'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get bill items to restore stock
        $stmt = $db->prepare("SELECT product_id, quantity FROM bill_items WHERE bill_id = ? AND is_return = 0");
        $stmt->execute([$billId]);
        $items = $stmt->fetchAll();
        
        // Restore product stock
        foreach ($items as $item) {
            if ($item['product_id']) {
                $stmt = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }
        }
        
        // Delete bill items
        $stmt = $db->prepare("DELETE FROM bill_items WHERE bill_id = ?");
        $stmt->execute([$billId]);
        
        // Delete bill payments
        $stmt = $db->prepare("DELETE FROM bill_payments WHERE bill_id = ?");
        $stmt->execute([$billId]);
        
        // Delete bill
        $stmt = $db->prepare("DELETE FROM bills WHERE id = ?");
        $stmt->execute([$billId]);
        
        $db->commit();
        
        jsonResponse(['success' => true, 'message' => 'Bill deleted successfully']);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Delete bill error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete bill'], 500);
    }
}

function deleteBillItem() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $itemId = intval($_GET['id'] ?? 0);
    
    if ($itemId <= 0) {
        jsonResponse(['success' => false, 'message' => 'Item ID required'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get item details
        $stmt = $db->prepare("SELECT bi.*, b.payment_status FROM bill_items bi JOIN bills b ON bi.bill_id = b.id WHERE bi.id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        
        if (!$item) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Item not found'], 404);
        }
        
        if ($item['payment_status'] !== 'pending') {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Cannot delete item from paid bill'], 400);
        }
        
        // Restore product stock if not a return item
        if ($item['product_id'] && !$item['is_return']) {
            $stmt = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $stmt->execute([$item['quantity'], $item['product_id']]);
        }
        
        // Delete the item
        $stmt = $db->prepare("DELETE FROM bill_items WHERE id = ?");
        $stmt->execute([$itemId]);
        
        // Recalculate bill totals
        $stmt = $db->prepare("SELECT SUM(subtotal) as subtotal FROM bill_items WHERE bill_id = ?");
        $stmt->execute([$item['bill_id']]);
        $result = $stmt->fetch();
        
        $subtotal = $result['subtotal'] ?? 0;
        $taxAmount = ($subtotal * ($item['tax_amount'] / $item['subtotal']));
        $totalAmount = $subtotal + $taxAmount;
        
        $stmt = $db->prepare("UPDATE bills SET subtotal = ?, tax_amount = ?, total_amount = ? WHERE id = ?");
        $stmt->execute([$subtotal, $taxAmount, $totalAmount, $item['bill_id']]);
        
        $db->commit();
        
        jsonResponse(['success' => true, 'message' => 'Item deleted successfully']);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Delete bill item error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete item'], 500);
    }
}
?>