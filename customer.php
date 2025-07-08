// customers.php
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
            getCustomers();
            break;
        case 'search':
            searchCustomers();
            break;
        case 'get':
            getCustomer();
            break;
        case 'top':
            getTopCustomers();
            break;
        default:
            getCustomers();
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'create':
            createCustomer();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update':
            updateCustomer();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleDeleteRequests($action) {
    switch ($action) {
        case 'delete':
            deleteCustomer();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getCustomers() {
    global $db;
    
    try {
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->prepare("SELECT * FROM customers WHERE is_active = 1 ORDER BY name LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $customers = $stmt->fetchAll();
        
        // Get total count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM customers WHERE is_active = 1");
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        jsonResponse([
            'success' => true,
            'customers' => $customers,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Get customers error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch customers'], 500);
    }
}

function searchCustomers() {
    global $db;
    
    $query = sanitize($_GET['q'] ?? '');
    
    if (empty($query)) {
        getCustomers();
        return;
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM customers WHERE is_active = 1 AND (name LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY name LIMIT 20");
        $searchTerm = "%{$query}%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $customers = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'customers' => $customers
        ]);
    } catch (PDOException $e) {
        error_log("Search customers error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Search failed'], 500);
    }
}

function getCustomer() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Customer ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $customer = $stmt->fetch();
        
        if ($customer) {
            // Get customer's order history
            $stmt = $db->prepare("SELECT id, bill_number, created_at, total_amount FROM bills WHERE customer_id = ? ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$id]);
            $orders = $stmt->fetchAll();
            
            $customer['orders'] = $orders;
            
            jsonResponse(['success' => true, 'customer' => $customer]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Get customer error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch customer'], 500);
    }
}

function getTopCustomers() {
    global $db;
    
    $limit = intval($_GET['limit'] ?? 5);
    $period = sanitize($_GET['period'] ?? 'month'); // day, week, month, year
    
    try {
        $dateCondition = "";
        switch ($period) {
            case 'day':
                $dateCondition = "AND DATE(created_at) = CURDATE()";
                break;
            case 'week':
                $dateCondition = "AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
                break;
            case 'month':
                $dateCondition = "AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())";
                break;
            case 'year':
                $dateCondition = "AND YEAR(created_at) = YEAR(CURDATE())";
                break;
        }
        
        $sql = "SELECT c.id, c.name, c.phone, SUM(b.total_amount) as total_spent 
                FROM customers c 
                JOIN bills b ON c.id = b.customer_id 
                WHERE c.is_active = 1 $dateCondition
                GROUP BY c.id 
                ORDER BY total_spent DESC 
                LIMIT ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$limit]);
        $customers = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'customers' => $customers,
            'period' => $period
        ]);
    } catch (PDOException $e) {
        error_log("Get top customers error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch top customers'], 500);
    }
}

function createCustomer() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = sanitize($input['name'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $address = sanitize($input['address'] ?? '');
    
    if (empty($name) || empty($phone)) {
        jsonResponse(['success' => false, 'message' => 'Name and phone are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO customers (name, phone, email, address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $phone, $email, $address]);
        
        $customerId = $db->lastInsertId();
        
        jsonResponse([
            'success' => true,
            'message' => 'Customer created successfully',
            'customer_id' => $customerId
        ]);
    } catch (PDOException $e) {
        error_log("Create customer error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create customer'], 500);
    }
}

function updateCustomer() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $address = sanitize($input['address'] ?? '');
    
    if (!$id || empty($name) || empty($phone)) {
        jsonResponse(['success' => false, 'message' => 'Customer ID, name and phone are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, email = ?, address = ? WHERE id = ? AND is_active = 1");
        $stmt->execute([$name, $phone, $email, $address, $id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Customer updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Customer not found or no changes made'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update customer error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update customer'], 500);
    }
}

function deleteCustomer() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Customer ID required'], 400);
    }
    
    try {
        // Soft delete
        $stmt = $db->prepare("UPDATE customers SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Customer deleted successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Customer not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Delete customer error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete customer'], 500);
    }
}
?>