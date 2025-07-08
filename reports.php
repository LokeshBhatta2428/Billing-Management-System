// reports.php
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
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

function handleGetRequests($action) {
    switch ($action) {
        case 'sales':
            getSalesReport();
            break;
        case 'inventory':
            getInventoryReport();
            break;
        case 'customer':
            getCustomerReport();
            break;
        case 'product':
            getProductReport();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getSalesReport() {
    global $db;
    
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate = sanitize($_GET['end_date'] ?? '');
    $groupBy = sanitize($_GET['group_by'] ?? 'day'); // day, week, month, year
    
    try {
        $dateCondition = "";
        $groupByClause = "";
        
        if (!empty($startDate) && !empty($endDate)) {
            $dateCondition = "WHERE DATE(created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
        } else {
            $params = [];
        }
        
        switch ($groupBy) {
            case 'day':
                $groupByClause = "DATE(created_at)";
                $dateFormat = "%Y-%m-%d";
                break;
            case 'week':
                $groupByClause = "YEARWEEK(created_at, 1)";
                $dateFormat = "%Y Week %v";
                break;
            case 'month':
                $groupByClause = "DATE_FORMAT(created_at, '%Y-%m')";
                $dateFormat = "%Y-%m";
                break;
            case 'year':
                $groupByClause = "YEAR(created_at)";
                $dateFormat = "%Y";
                break;
            default:
                $groupByClause = "DATE(created_at)";
                $dateFormat = "%Y-%m-%d";
        }
        
        $sql = "SELECT 
                    $groupByClause as period,
                    DATE_FORMAT(created_at, '$dateFormat') as period_label,
                    COUNT(*) as bill_count,
                    SUM(total_amount) as total_sales,
                    AVG(total_amount) as avg_sale,
                    SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
                    SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END) as card_sales,
                    SUM(CASE WHEN payment_method = 'upi' THEN total_amount ELSE 0 END) as upi_sales,
                    SUM(CASE WHEN payment_method = 'credit' THEN total_amount ELSE 0 END) as credit_sales
                FROM bills
                $dateCondition
                GROUP BY $groupByClause
                ORDER BY period";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'report' => $reportData,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'group_by' => $groupBy
        ]);
    } catch (PDOException $e) {
        error_log("Get sales report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to generate sales report'], 500);
    }
}

function getInventoryReport() {
    global $db;
    
    $category = sanitize($_GET['category'] ?? '');
    $lowStock = intval($_GET['low_stock'] ?? 0);
    
    try {
        $whereClause = "WHERE p.is_active = 1";
        $params = [];
        
        if (!empty($category)) {
            $whereClause .= " AND p.category = ?";
            $params[] = $category;
        }
        
        if ($lowStock > 0) {
            $whereClause .= " AND p.stock <= ?";
            $params[] = $lowStock;
        }
        
        $sql = "SELECT 
                    p.id, p.name, p.price, p.stock, p.category,
                    COUNT(bi.id) as times_sold,
                    SUM(bi.quantity) as total_quantity_sold,
                    SUM(bi.subtotal) as total_sales_amount
                FROM products p
                LEFT JOIN bill_items bi ON p.id = bi.product_id
                LEFT JOIN bills b ON bi.bill_id = b.id
                $whereClause
                GROUP BY p.id
                ORDER BY p.stock ASC, p.name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'report' => $reportData,
            'category' => $category,
            'low_stock_threshold' => $lowStock
        ]);
    } catch (PDOException $e) {
        error_log("Get inventory report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to generate inventory report'], 500);
    }
}

function getCustomerReport() {
    global $db;
    
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate = sanitize($_GET['end_date'] ?? '');
    $minOrders = intval($_GET['min_orders'] ?? 1);
    
    try {
        $dateCondition = "";
        $params = [];
        
        if (!empty($startDate) && !empty($endDate)) {
            $dateCondition = "AND DATE(b.created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
        }
        
        $sql = "SELECT 
                    c.id, c.name, c.phone, c.email,
                    COUNT(b.id) as order_count,
                    SUM(b.total_amount) as total_spent,
                    AVG(b.total_amount) as avg_order_value,
                    MAX(b.created_at) as last_order_date
                FROM customers c
                JOIN bills b ON c.id = b.customer_id
                WHERE c.is_active = 1 $dateCondition
                GROUP BY c.id
                HAVING COUNT(b.id) >= ?
                ORDER BY total_spent DESC";
        
        $params[] = $minOrders;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'report' => $reportData,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'min_orders' => $minOrders
        ]);
    } catch (PDOException $e) {
        error_log("Get customer report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to generate customer report'], 500);
    }
}

function getProductReport() {
    global $db;
    
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate = sanitize($_GET['end_date'] ?? '');
    $category = sanitize($_GET['category'] ?? '');
    $limit = intval($_GET['limit'] ?? 10);
    
    try {
        $dateCondition = "";
        $categoryCondition = "";
        $params = [];
        
        if (!empty($startDate) && !empty($endDate)) {
            $dateCondition = "AND DATE(b.created_at) BETWEEN ? AND ?";
            $params = [$startDate, $endDate];
        }
        
        if (!empty($category)) {
            $categoryCondition = "AND p.category = ?";
            $params[] = $category;
        }
        
        $sql = "SELECT 
                    p.id, p.name, p.category, p.price,
                    COUNT(bi.id) as times_sold,
                    SUM(bi.quantity) as total_quantity,
                    SUM(bi.subtotal) as total_sales,
                    (SUM(bi.quantity) * p.price as potential_sales,
                    (SUM(bi.subtotal) / (SUM(bi.quantity) * p.price) * 100 as discount_percentage
                FROM products p
                JOIN bill_items bi ON p.id = bi.product_id
                JOIN bills b ON bi.bill_id = b.id
                WHERE p.is_active = 1 $dateCondition $categoryCondition
                GROUP BY p.id
                ORDER BY total_sales DESC
                LIMIT ?";
        
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reportData = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'report' => $reportData,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'category' => $category,
            'limit' => $limit
        ]);
    } catch (PDOException $e) {
        error_log("Get product report error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to generate product report'], 500);
    }
}
?>