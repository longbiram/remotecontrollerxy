<?php
/**
 * REST API: User Login
 * Authenticates user credentials and returns session token
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
    enforceApiProtection('/api/auth_login.php');
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

    $email = strtolower(trim($inputData['email'] ?? ''));
    $password = $inputData['password'] ?? '';

    // Basic validation
    if (empty($email) || empty($password)) {
        jsonResponse([
            'status' => 'error',
            'message' => 'Both email and password are required to sign in.',
        ], 422);
    }

    // Lookup user by email
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, avatar, status, token, created_at FROM app_users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse([
            'status' => 'error',
            'code' => 'USER_NOT_FOUND',
            'message' => 'No account found with this email. Please sign up to create one.',
        ], 404);
    }

    // Verify password hash
    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse([
            'status' => 'error',
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Incorrect password. Please verify and try again.',
        ], 401);
    }

    // Check account status
    if ($user['status'] === 'suspended') {
        jsonResponse([
            'status' => 'error',
            'code' => 'ACCOUNT_SUSPENDED',
            'message' => 'Your account has been suspended by administration.',
        ], 403);
    }

    // Reuse existing valid token if present so multiple devices and reopens are not invalidated
    $token = (!empty($user['token']) && strlen($user['token']) >= 16)
        ? $user['token']
        : bin2hex(random_bytes(32));
    $upStmt = $pdo->prepare("UPDATE app_users SET token = :token, last_login = NOW() WHERE id = :id");
    $upStmt->execute(['token' => $token, 'id' => $user['id']]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Logged in successfully! Welcome back.',
        'data' => [
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar' => $user['avatar'] ?? null,
                'status' => $user['status'],
                'created_at' => $user['created_at'],
            ],
            'token' => $token,
        ],
    ]);

} catch (Exception $e) {
    error_log("Login API Error: " . $e->getMessage());
    jsonResponse([
        'status' => 'error',
        'message' => 'Internal server error while logging in: ' . $e->getMessage(),
    ], 500);
}
