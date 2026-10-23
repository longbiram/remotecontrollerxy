<?php
/**
 * REST API: User Profile Check & Update
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Key, X-App-Time, X-App-Signature, X-User-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

if (!Database::isInstalled()) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Database is not configured yet.',
    ], 503);
}

// Extract token from Bearer auth or custom headers
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';
if (empty($token) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token) && !empty($_GET['token'])) {
    $token = trim($_GET['token']);
}
if (empty($token) && !empty($_REQUEST['token'])) {
    $token = trim($_REQUEST['token']);
}

if (empty($token)) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Missing authorization user token.',
    ], 401);
}

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id, name, email, avatar, status, created_at, last_login FROM app_users WHERE token = :token LIMIT 1");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse([
            'status' => 'error',
            'code' => 'INVALID_TOKEN',
            'message' => 'User session expired or invalid token. Please log in again.',
        ], 401);
    }

    if ($user['status'] === 'suspended') {
        jsonResponse([
            'status' => 'error',
            'message' => 'Account suspended.',
        ], 403);
    }

    // Refresh last_login timestamp on verified session
    try {
        $upLast = $pdo->prepare("UPDATE app_users SET last_login = NOW() WHERE id = :id");
        $upLast->execute(['id' => $user['id']]);
    } catch (_) {}

    // If POST, handle profile update (e.g. name or avatar update)
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $newName = trim($data['name'] ?? $user['name']);
        $newAvatar = trim($data['avatar'] ?? ($user['avatar'] ?? ''));

        $up = $pdo->prepare("UPDATE app_users SET name = :name, avatar = :avatar WHERE id = :id");
        $up->execute([
            'name' => $newName,
            'avatar' => !empty($newAvatar) ? $newAvatar : null,
            'id' => $user['id'],
        ]);

        $user['name'] = $newName;
        $user['avatar'] = !empty($newAvatar) ? $newAvatar : null;
    }

    jsonResponse([
        'status' => 'success',
        'data' => [
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'avatar' => $user['avatar'] ?? null,
                'status' => $user['status'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login'] ?? null,
            ],
        ],
    ]);

} catch (Exception $e) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ], 500);
}
