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
        case 'get':
            getSettings();
            break;
        default:
            getSettings();
    }
}

function handlePostRequests($action) {
    switch ($action) {
        case 'update':
            updateSettings();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function handlePutRequests($action) {
    switch ($action) {
        case 'update':
            updateSettings();
            break;
        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
}

function getSettings() {
    global $db;
    
    try {
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $settingsData = $stmt->fetchAll();
        
        $settings = [];
        foreach ($settingsData as $setting) {
            $settings[$setting['setting_key']] = $setting['setting_value'];
        }
        
        jsonResponse([
            'success' => true,
            'settings' => $settings
        ]);
    } catch (PDOException $e) {
        error_log("Get settings error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to fetch settings'], 500);
    }
}

function updateSettings() {
    global $db;
    
    if (!checkPermission('admin')) {
        jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($input)) {
        jsonResponse(['success' => false, 'message' => 'Invalid input format'], 400);
    }
    
    $allowedSettings = [
        'store_name',
        'store_address',
        'store_phone',
        'store_email',
        'tax_rate',
        'tax_name',
        'currency_symbol',
        'theme'
    ];
    
    try {
        $db->beginTransaction();
        
        foreach ($input as $key => $value) {
            if (!in_array($key, $allowedSettings)) {
                continue;
            }
            
            $value = sanitize($value);
            
            // Validate specific settings
            if ($key === 'tax_rate') {
                $value = floatval($value);
                if ($value < 0 || $value > 100) {
                    $db->rollback();
                    jsonResponse(['success' => false, 'message' => 'Tax rate must be between 0 and 100'], 400);
                }
            }
            
            if ($key === 'store_email' && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $db->rollback();
                jsonResponse(['success' => false, 'message' => 'Invalid email format'], 400);
            }
            
            // Update or insert setting
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $db->commit();
        
        jsonResponse(['success' => true, 'message' => 'Settings updated successfully']);
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Update settings error: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Failed to update settings'], 500);
    }
}
?>