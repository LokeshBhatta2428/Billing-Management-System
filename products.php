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
            getProducts();
            break;
        case 'search':
            searchProducts();
            break;
        case 'get':
            getProduct();
            break;
        default:
            getProducts();
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'create':
            createProduct();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update':
            updateProduct();
            break;
        case 'update_stock':
            updateStock();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleDeleteRequests($action) {
    switch ($action) {
        case 'delete':
            deleteProduct();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getProducts() {
    global $db;
    
    try {
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->prepare("SELECT id, name, price, stock, category, barcode, created_at, updated_at FROM products WHERE is_active = 1 ORDER BY name LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $products = $stmt->fetchAll();
        
        // Get total count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM products WHERE is_active = 1");
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        jsonResponse([
            'success' => true,
            'products' => $products,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Get products error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch products'], 500);
    }
}

function searchProducts() {
    global $db;
    
    $query = sanitize($_GET['q'] ?? '');
    
    if (empty($query)) {
        getProducts();
        return;
    }
    
    try {
        $stmt = $db->prepare("SELECT id, name, price, stock, category, barcode FROM products WHERE is_active = 1 AND (name LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 20");
        $searchTerm = "%{$query}%";
        $stmt->execute([$searchTerm, $searchTerm]);
        $products = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'products' => $products
        ]);
    } catch (PDOException $e) {
        error_log("Search products error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Search failed'], 500);
    }
}

function getProduct() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        
        if ($product) {
            jsonResponse(['success' => true, 'product' => $product]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Get product error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch product'], 500);
    }
}

function createProduct() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = sanitize($input['name'] ?? '');
    $price = floatval($input['price'] ?? 0);
    $stock = intval($input['stock'] ?? 0);
    $category = sanitize($input['category'] ?? 'Others');
    $description = sanitize($input['description'] ?? '');
    $barcode = sanitize($input['barcode'] ?? '');
    
    if (empty($name) || $price <= 0) {
        jsonResponse(['success' => false, 'message' => 'Product name and valid price are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("INSERT INTO products (name, price, stock, category, description, barcode) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $price, $stock, $category, $description, $barcode]);
        
        $productId = $db->lastInsertId();
        
        jsonResponse([
            'success' => true,
            'message' => 'Product created successfully',
            'product_id' => $productId
        ]);
    } catch (PDOException $e) {
        error_log("Create product error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create product'], 500);
    }
}

function updateProduct() {
    global $db;
    
    if (!checkPermission('manager')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $name = sanitize($input['name'] ?? '');
    $price = floatval($input['price'] ?? 0);
    $stock = intval($input['stock'] ?? 0);
    $category = sanitize($input['category'] ?? 'Others');
    $description = sanitize($input['description'] ?? '');
    $barcode = sanitize($input['barcode'] ?? '');
    
    if (!$id || empty($name) || $price <= 0) {
        jsonResponse(['success' => false, 'message' => 'Product ID, name and valid price are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("UPDATE products SET name = ?, price = ?, stock = ?, category = ?, description = ?, barcode = ? WHERE id = ? AND is_active = 1");
        $stmt->execute([$name, $price, $stock, $category, $description, $barcode, $id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Product updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Product not found or no changes made'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update product error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update product'], 500);
    }
}

function updateStock() {
    global $db;
    
    if (!checkPermission('cashier')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);
    $operation = $input['operation'] ?? 'reduce'; // 'reduce' or 'add'
    
    if (!$id || $quantity <= 0) {
        jsonResponse(['success' => false, 'message' => 'Product ID and valid quantity are required'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get current stock
        $stmt = $db->prepare("SELECT stock FROM products WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            $db->rollback();
            jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
        }
        
        $newStock = $product['stock'];
        
        if ($operation === 'reduce') {
            $newStock -= $quantity;
            if ($newStock < 0) {
                $db->rollback();
                jsonResponse(['success' => false, 'message' => 'Insufficient stock'], 400);
            }
        } else {
            $newStock += $quantity;
        }
        
        // Update stock
        $stmt = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->execute([$newStock, $id]);
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'message' => 'Stock updated successfully',
            'new_stock' => $newStock
        ]);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Update stock error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update stock'], 500);
    }
}

function deleteProduct() {
    global $db;
    
    if (!checkPermission('admin')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'Product ID required'], 400);
    }
    
    try {
        // Soft delete
        $stmt = $db->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Product deleted successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Product not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Delete product error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete product'], 500);
    }
}
?>