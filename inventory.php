// inventory.php
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
    default:
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

function handleGetRequests($action) {
    switch ($action) {
        case 'categories':
            getCategories();
            break;
        case 'stock':
            getStockLevels();
            break;
        case 'movements':
            getStockMovements();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'adjust':
            adjustStock();
            break;
        case 'transfer':
            transferStock();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update_category':
            updateProductCategory();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getCategories() {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT DISTINCT category FROM products WHERE is_active = 1 ORDER BY category");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        
        jsonResponse([
            'success' => true,
            'categories' => $categories
        ]);
    } catch (PDOException $e) {
        error_log("Get categories error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch categories'], 500);
    }
}

function getStockLevels() {
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
                    p.id, p.name, p.stock, p.category, 
                    COALESCE(SUM(bi.quantity), 0) as sold_last_month
                FROM products p
                LEFT JOIN bill_items bi ON p.id = bi.product_id
                LEFT JOIN bills b ON bi.bill_id = b.id AND b.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                $whereClause
                GROUP BY p.id
                ORDER BY p.stock ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'products' => $products,
            'category' => $category,
            'low_stock_threshold' => $lowStock
        ]);
    } catch (PDOException $e) {
        error_log("Get stock levels error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch stock levels'], 500);
    }
}

function getStockMovements() {
    global $db;
    
    $productId = intval($_GET['product_id'] ?? 0);
    $startDate = sanitize($_GET['start_date'] ?? '');
    $endDate = sanitize($_GET['end_date'] ?? '');
    $limit = intval($_GET['limit'] ?? 50);
    
    try {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        if ($productId > 0) {
            $whereClause .= " AND product_id = ?";
            $params[] = $productId;
        }
        
        if (!empty($startDate)) {
            $whereClause .= " AND DATE(created_at) >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $whereClause .= " AND DATE(created_at) <= ?";
            $params[] = $endDate;
        }
        
        $sql = "SELECT * FROM stock_movements 
                $whereClause
                ORDER BY created_at DESC
                LIMIT ?";
        
        $params[] = $limit;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $movements = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'movements' => $movements,
            'product_id' => $productId,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
    } catch (PDOException $e) {
        error_log("Get stock movements error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch stock movements'], 500);
    }
}

function adjustStock() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productId = intval($input['product_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);
    $type = sanitize($input['type'] ?? 'add'); // 'add' or 'subtract'
    $reason = sanitize($input['reason'] ?? 'Manual adjustment');
    $userId = $_SESSION['user_id'];
    
    if ($productId <= 0 || $quantity <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid product ID or quantity'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get current stock
        $stmt = $db->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $newStock = $product['stock'];
        
        if ($type === 'add') {
            $newStock += $quantity;
        } else {
            $newStock -= $quantity;
            if ($newStock < 0) {
                $db->rollback();
                jsonResponse(['success' => false, 'message' => 'Insufficient stock'], 400);
            }
        }
        
        // Update product stock
        $stmt = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->execute([$newStock, $productId]);
        
        // Record stock movement
        $stmt = $db->prepare("INSERT INTO stock_movements (
            product_id, quantity, movement_type, previous_stock, new_stock,
            reference, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $productId, 
            ($type === 'add' ? $quantity : -$quantity),
            'adjustment',
            $product['stock'],
            $newStock,
            'MANUAL',
            $reason,
            $userId
        ]);
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Stock adjusted successfully',
            'new_stock' => $newStock
        ]);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Adjust stock error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to adjust stock'], 500);
    }
}

function transferStock() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $fromProductId = intval($input['from_product_id'] ?? 0);
    $toProductId = intval($input['to_product_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);
    $reason = sanitize($input['reason'] ?? 'Stock transfer');
    $userId = $_SESSION['user_id'];
    
    if ($fromProductId <= 0 || $toProductId <= 0 || $quantity <= 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid product IDs or quantity'], 400);
    }
    
    if ($fromProductId === $toProductId) {
        jsonResponse(['success' => false, 'message' => 'Cannot transfer to the same product'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get from product stock
        $stmt = $db->prepare("SELECT id, name, stock FROM products WHERE id = ?");
        $stmt->execute([$fromProductId]);
        $fromProduct = $stmt->fetch();
        
        if (!$fromProduct) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Source product not found'], 404);
        }
        
        if ($fromProduct['stock'] < $quantity) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Insufficient stock in source product'], 400);
        }
        
        // Get to product stock
        $stmt = $db->prepare("SELECT id, name, stock FROM products WHERE id = ?");
        $stmt->execute([$toProductId]);
        $toProduct = $stmt->fetch();
        
        if (!$toProduct) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Destination product not found'], 404);
        }
        
        // Update from product stock
        $newFromStock = $fromProduct['stock'] - $quantity;
        $stmt = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->execute([$newFromStock, $fromProductId]);
        
        // Record stock movement for from product
        $stmt = $db->prepare("INSERT INTO stock_movements (
            product_id, quantity, movement_type, previous_stock, new_stock,
            reference, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $fromProductId, 
            -$quantity,
            'transfer_out',
            $fromProduct['stock'],
            $newFromStock,
            'TRANSFER',
            $reason . ' (To: ' . $toProduct['name'] . ')',
            $userId
        ]);
        
        // Update to product stock
        $newToStock = $toProduct['stock'] + $quantity;
        $stmt = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->execute([$newToStock, $toProductId]);
        
        // Record stock movement for to product
        $stmt = $db->prepare("INSERT INTO stock_movements (
            product_id, quantity, movement_type, previous_stock, new_stock,
            reference, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $toProductId, 
            $quantity,
            'transfer_in',
            $toProduct['stock'],
            $newToStock,
            'TRANSFER',
            $reason . ' (From: ' . $fromProduct['name'] . ')',
            $userId
        ]);
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Stock transferred successfully',
            'from_product' => [
                'id' => $fromProductId,
                'new_stock' => $newFromStock
            ],
            'to_product' => [
                'id' => $toProductId,
                'new_stock' => $newToStock
            ]
        ]);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Transfer stock error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to transfer stock'], 500);
    }
}

function updateProductCategory() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $productId = intval($input['product_id'] ?? 0);
    $category = sanitize($input['category'] ?? '');
    
    if ($productId <= 0 || empty($category)) {
        jsonResponse(['success' => false, 'message' => 'Product ID and category are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("UPDATE products SET category = ? WHERE id = ?");
        $stmt->execute([$category, $productId]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Product category updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Product not found or no changes made'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update product category error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update product category'], 500);
    }
}
?>