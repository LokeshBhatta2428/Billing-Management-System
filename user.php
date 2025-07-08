// users.php
<?php
require_once 'config.php';

header('Content-Type: application/json');

requireLogin();

if (!checkPermission('admin')) {
    jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
}

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
            getUsers();
            break;
        case 'get':
            getUser();
            break;
        case 'roles':
            getUserRoles();
            break;
        default:
            getUsers();
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'create':
            createUser();
            break;
        case 'reset_password':
            resetPassword();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update':
            updateUser();
            break;
        case 'update_status':
            updateUserStatus();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handleDeleteRequests($action) {
    switch ($action) {
        case 'delete':
            deleteUser();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getUsers() {
    global $db;
    
    try {
        $page = intval($_GET['page'] ?? 1);
        $limit = intval($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $stmt = $db->prepare("SELECT id, username, role, full_name, email, phone, is_active, last_login FROM users ORDER BY username LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $users = $stmt->fetchAll();
        
        // Get total count
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users");
        $stmt->execute();
        $total = $stmt->fetch()['total'];
        
        jsonResponse([
            'success' => true,
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Get users error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch users'], 500);
    }
}

function getUser() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'User ID required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT id, username, role, full_name, email, phone, is_active, last_login FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        
        if ($user) {
            jsonResponse(['success' => true, 'user' => $user]);
        } else {
            jsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Get user error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch user'], 500);
    }
}

function getUserRoles() {
    $roles = [
        'admin' => 'Administrator',
        'manager' => 'Manager',
        'cashier' => 'Cashier'
    ];
    
    jsonResponse([
        'success' => true,
        'roles' => $roles
    ]);
}

function createUser() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitize($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role = sanitize($input['role'] ?? 'cashier');
    $fullName = sanitize($input['full_name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    
    if (empty($username) || empty($password) || empty($fullName)) {
        jsonResponse(['success' => false, 'message' => 'Username, password and full name are required'], 400);
    }
    
    try {
        // Check if username exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Username already exists'], 400);
        }
        
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        // Create user
        $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $hashedPassword, $role, $fullName, $email, $phone]);
        
        $userId = $db->lastInsertId();
        
        jsonResponse([
            'success' => true,
            'message' => 'User created successfully',
            'user_id' => $userId
        ]);
    } catch (PDOException $e) {
        error_log("Create user error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to create user'], 500);
    }
}

function updateUser() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $username = sanitize($input['username'] ?? '');
    $role = sanitize($input['role'] ?? '');
    $fullName = sanitize($input['full_name'] ?? '');
    $email = sanitize($input['email'] ?? '');
    $phone = sanitize($input['phone'] ?? '');
    
    if (!$id || empty($username) || empty($fullName)) {
        jsonResponse(['success' => false, 'message' => 'User ID, username and full name are required'], 400);
    }
    
    try {
        // Check if username exists for another user
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        
        if ($stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Username already exists'], 400);
        }
        
        // Update user
        $stmt = $db->prepare("UPDATE users SET username = ?, role = ?, full_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->execute([$username, $role, $fullName, $email, $phone, $id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'User updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'User not found or no changes made'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update user error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update user'], 500);
    }
}

function updateUserStatus() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $isActive = intval($input['is_active'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'User ID required'], 400);
    }
    
    try {
        // Prevent deactivating own account
        if ($id == $_SESSION['user_id']) {
            jsonResponse(['success' => false, 'message' => 'Cannot deactivate your own account'], 400);
        }
        
        $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->execute([$isActive, $id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'User status updated successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Update user status error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update user status'], 500);
    }
}

function resetPassword() {
    global $db;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = intval($input['id'] ?? 0);
    $password = $input['password'] ?? '';
    
    if (!$id || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'User ID and password are required'], 400);
    }
    
    try {
        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Password reset successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Reset password error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to reset password'], 500);
    }
}

function deleteUser() {
    global $db;
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        jsonResponse(['success' => false, 'message' => 'User ID required'], 400);
    }
    
    // Prevent deleting own account
    if ($id == $_SESSION['user_id']) {
        jsonResponse(['success' => false, 'message' => 'Cannot delete your own account'], 400);
    }
    
    try {
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            jsonResponse(['success' => false, 'message' => 'User not found'], 404);
        }
    } catch (PDOException $e) {
        error_log("Delete user error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to delete user'], 500);
    }
}
?>