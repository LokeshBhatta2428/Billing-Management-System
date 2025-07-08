<?php
require_once 'config.php';

// Get the request method and endpoint
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';

// Handle different API endpoints
switch ($endpoint) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'products':
        handleProducts();
        break;
    case 'customers':
        handleCustomers();
        break;
    case 'bills':
        handleBills();
        break;
    case 'reports':
        handleReports();
        break;
    case 'settings':
        handleSettings();
        break;
    default:
        jsonResponse(['error' => 'Invalid endpoint'], 404);
}

// Authentication handlers
function handleLogin() {
    global $pdo, $method;
    
    if ($method !== 'POST') {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password required'], 400);
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            jsonResponse([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'full_name' => $user['full_name']
                ]
            ]);
        } else {
            jsonResponse(['error' => 'Invalid credentials'], 401);
        }
    } catch (PDOException $e) {
        handleError("Login error: " . $e->getMessage());
    }
}

function handleLogout() {
    session_destroy();
    jsonResponse(['success' => true]);
}

// Product handlers
function handleProducts() {
    global $pdo, $method;
    
    requireLogin();
    
    switch ($method) {
        case 'GET':
            getProducts();
            break;
        case 'POST':
            if (!hasPermission('manager')) {
                jsonResponse(['error' => 'Insufficient permissions'], 403);
            }
            createProduct();
            break;
        case 'PUT':
            if (!hasPermission('manager')) {
                jsonResponse(['error' => 'Insufficient permissions'], 403);
            }
            updateProduct();
            break;
        case 'DELETE':
            if (!hasPermission('manager')) {
                jsonResponse(['error' => 'Insufficient permissions'], 403);
            }
            deleteProduct();
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
}

function getProducts() {
    global $pdo;
    
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $sql = "SELECT * FROM products WHERE is_active = 1";
        $params = [];
        
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY name LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        jsonResponse(['products' => $products]);
    } catch (PDOException $e) {
        handleError("Error fetching products: " . $e->getMessage());
    }
}

function createProduct() {
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $