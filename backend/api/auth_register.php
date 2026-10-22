<?php
/**
 * REST API: User Registration
 * Registers a new mobile application user
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Key, X-App-Time, X-App-Signature, X-User-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed. Only POST is accepted.',
    ], 405);
}

// Check database installation
if (!Database::isInstalled()) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Database is not configured yet.',
        'installed' => false,
    ], 503);
}

// Verify client HMAC signature if client headers are present
if (!empty($_SERVER['HTTP_X_APP_KEY']) || !empty($_SERVER['HTTP_X_APP_SIGNATURE'])) {
    enforceApiProtection('/api/auth_register.php');
}

// Helper: Ensure app_users table exists
function ensureAppUsersTable(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS `app_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(191) NOT NULL UNIQUE,
        `password_hash` VARCHAR(255) NOT NULL,
        `avatar` TEXT NULL,
        `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
        `token` VARCHAR(255) NULL,
        `last_login` DATETIME NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_email` (`email`),
        INDEX `idx_token` (`token`),
        INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql);
}

try {
    $pdo = Database::getInstance();
    if (!$pdo) {
        throw new Exception("Unable to connect to database");
    }

    ensureAppUsersTable($pdo);

    // Read payload from JSON body or POST form data
    $rawInput = file_get_contents('php://input');
    $inputData = json_decode($rawInput, true);
    if (!is_array($inputData)) {
        $inputData = $_POST;
    }

    $name = trim($inputData['name'] ?? '');
    $email = strtolower(trim($inputData['email'] ?? ''));
    $password = $inputData['password'] ?? '';
    $avatar = trim($inputData['avatar'] ?? '');

    // Validation
    $errors = [];
    if (empty($name)) {
        $errors[] = 'Full name is required.';
    } elseif (strlen($name) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    }

    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if (!empty($errors)) {
        jsonResponse([
            'status' => 'error',
            'message' => implode(' ', $errors),
            'errors' => $errors,
        ], 422);
    }

    // Check if email already exists
    $checkStmt = $pdo->prepare("SELECT id FROM app_users WHERE email = :email LIMIT 1");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        jsonResponse([
            'status' => 'error',
            'code' => 'EMAIL_EXISTS',
            'message' => 'An account with this email address already exists. Please log in instead.',
        ], 409);
    }

    // Hash password & generate session token
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $token = bin2hex(random_bytes(32));

    $insertStmt = $pdo->prepare("
        INSERT INTO app_users (name, email, password_hash, avatar, status, token, last_login)
        VALUES (:name, :email, :password_hash, :avatar, 'active', :token, NOW())
    ");

    $insertStmt->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $passwordHash,
        'avatar' => !empty($avatar) ? $avatar : null,
        'token' => $token,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

    jsonResponse([
        'status' => 'success',
        'message' => 'Account registered successfully! Welcome to PikaShow.',
        'data' => [
            'user' => [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
                'avatar' => !empty($avatar) ? $avatar : null,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            'token' => $token,
        ],
    ], 201);

} catch (Exception $e) {
    error_log("Registration API Error: " . $e->getMessage());
    jsonResponse([
        'status' => 'error',
        'message' => 'Internal server error while registering user: ' . $e->getMessage(),
    ], 500);
}
