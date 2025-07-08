// dashboard.php
<?php
require_once 'config.php';

header('Content-Type: application/json');

requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    global $db;
    
    // Today's sales
    $stmt = $db->prepare("SELECT 
                            COUNT(*) as bill_count, 
                            SUM(total_amount) as total_sales 
                          FROM bills 
                          WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $todaySales = $stmt->fetch();
    
    // Monthly sales
    $stmt = $db->prepare("SELECT 
                            COUNT(*) as bill_count, 
                            SUM(total_amount) as total_sales 
                          FROM bills 
                          WHERE YEAR(created_at) = YEAR(CURDATE()) 
                          AND MONTH(created_at) = MONTH(CURDATE())");
    $stmt->execute();
    $monthlySales = $stmt->fetch();
    
    // Total customers
    $stmt = $db->prepare("SELECT COUNT(*) as total_customers FROM customers WHERE is_active = 1");
    $stmt->execute();
    $totalCustomers = $stmt->fetch()['total_customers'];
    
    // Low stock products
    $stmt = $db->prepare("SELECT COUNT(*) as low_stock_count FROM products WHERE is_active = 1 AND stock <= 5");
    $stmt->execute();
    $lowStockCount = $stmt->fetch()['low_stock_count'];
    
    // Recent bills
    $stmt = $db->prepare("SELECT b.id, b.bill_number, b.customer_name, b.total_amount, b.created_at, u.username as cashier 
                          FROM bills b 
                          LEFT JOIN users u ON b.created_by = u.id 
                          ORDER BY b.created_at DESC 
                          LIMIT 5");
    $stmt->execute();
    $recentBills = $stmt->fetchAll();
    
    // Top products
    $stmt = $db->prepare("SELECT p.name, SUM(bi.quantity) as total_quantity 
                          FROM bill_items bi 
                          JOIN products p ON bi.product_id = p.id 
                          JOIN bills b ON bi.bill_id = b.id 
                          WHERE DATE(b.created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE() 
                          GROUP BY p.id 
                          ORDER BY total_quantity DESC 
                          LIMIT 5");
    $stmt->execute();
    $topProducts = $stmt->fetchAll();
    
    // Weekly sales
    $stmt = $db->prepare("SELECT 
                            DATE(created_at) as date, 
                            COUNT(*) as bill_count, 
                            SUM(total_amount) as total_sales 
                          FROM bills 
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                          GROUP BY DATE(created_at) 
                          ORDER BY date");
    $stmt->execute();
    $weeklySales = $stmt->fetchAll();
    
    jsonResponse([
        'success' => true,
        'data' => [
            'today_sales' => $todaySales,
            'monthly_sales' => $monthlySales,
            'total_customers' => $totalCustomers,
            'low_stock_count' => $lowStockCount,
            'recent_bills' => $recentBills,
            'top_products' => $topProducts,
            'weekly_sales' => $weeklySales
        ]
    ]);
} catch (PDOException $e) {
    error_log("Dashboard data error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Failed to fetch dashboard data'], 500);
}
?>