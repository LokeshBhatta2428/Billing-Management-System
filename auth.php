<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check_session':
        handleCheckSession();
        break;
    default:
        jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function handleLogin() {
    global $db;
    
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Username and password are required'], 400);
    }
    
    try {
        $stmt = $db->prepare("SELECT id, username, password, role, full_name, email, is_active FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['last_activity'] = time();
            
            // Generate CSRF token
            generateCSRFToken();
            
            jsonResponse([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email']
                ],
                'csrf_token' => $_SESSION[CSRF_TOKEN_NAME]
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => 'Invalid credentials'], 401);
        }
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Login failed'], 500);
    }
}

function handleLogout() {
    session_destroy();
    jsonResponse(['success' => true, 'message' => 'Logout successful']);
}

function handleCheckSession() {
    if (isLoggedIn()) {
        jsonResponse([
            'success' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role' => $_SESSION['user_role'],
                'full_name' => $_SESSION['full_name'],
                'email' => $_SESSION['email']
            ],
            'csrf_token' => generateCSRFToken()
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
    }
}
?>