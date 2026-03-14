<?php
session_start();


define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');       
define('DB_NAME', 'billing_management');

// ─── APP SETTINGS ─────────────────────────────────────────────────────────────
define('SESSION_TIMEOUT', 3600);  
define('CSRF_TOKEN_NAME', 'csrf_token');

// ─── DATABASE CONNECTION (singleton) ─────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // Return JSON error so the front-end gets a readable message
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed. Check DB_HOST / DB_USER / DB_PASS in config.php.',
                'detail'  => $e->getMessage()
            ]);
            exit;
        }
    }
    return $pdo;
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function sanitize(?string $data): string {
    return htmlspecialchars(strip_tags(trim($data ?? '')));
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
    }
}

/**
 * Check if the current user has AT LEAST the required role.
 * Hierarchy: admin(3) > manager(2) > cashier(1)
 */
function checkPermission(string $requiredRole): bool {
    if (!isLoggedIn()) return false;
    $hierarchy = ['admin' => 3, 'manager' => 2, 'cashier' => 1];
    $userLevel  = $hierarchy[$_SESSION['user_role'] ?? 'cashier'] ?? 0;
    $needLevel  = $hierarchy[$requiredRole] ?? 99;
    return $userLevel >= $needLevel;
}

function generateCSRFToken(): string {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Generate a unique bill number.
 * @param string $prefix  e.g. 'BILL' or 'RET'
 */
function generateBillNumber(string $prefix = 'BILL'): string {
    return $prefix . '-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ─── SESSION TIMEOUT CHECK ────────────────────────────────────────────────────
if (isLoggedIn() && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        jsonResponse(['success' => false, 'message' => 'Session expired'], 401);
    }
}
if (isLoggedIn()) {
    $_SESSION['last_activity'] = time();
}

date_default_timezone_set('Asia/Kathmandu');
