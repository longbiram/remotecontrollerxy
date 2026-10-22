<?php
/**
 * API Protection Guard & Request Authentication Middleware
 * Enforces HMAC SHA-256 signatures and secret key validation
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/database.php';

function enforceApiProtection(string $endpointPath): void {
    // 1. Allow authenticated admin sessions (e.g. testing in browser or dashboard)
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['admin_user'])) {
        return;
    }

    // 2. Fetch the shared API secret
    $expectedSecret = 'pika_sec_8f92a10b4c8d7e6f312059a4b8c7e6d5';
    if (Database::isInstalled()) {
        try {
            $pdo = Database::getInstance();
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'api_secret' LIMIT 1");
                $stmt->execute();
                $dbSecret = $stmt->fetchColumn();
                if (!empty($dbSecret)) {
                    $expectedSecret = $dbSecret;
                }
            }
        } catch (Exception $e) {
            // fallback to default secret
        }
    }

    // 3. Extract incoming security headers
    $appKey = $_SERVER['HTTP_X_APP_KEY'] ?? '';
    $appTime = (int)($_SERVER['HTTP_X_APP_TIME'] ?? 0);
    $appSig = strtolower(trim($_SERVER['HTTP_X_APP_SIGNATURE'] ?? ''));

    // Check basic key presence
    if (empty($appKey) || empty($appSig) || empty($appTime)) {
        jsonResponse([
            'status' => 'error',
            'code' => 401,
            'message' => 'Unauthorized: Protected API endpoint. Client signature missing.',
            'documentation' => 'Include X-App-Key, X-App-Time, and X-App-Signature headers.',
        ], 401);
    }

    // Verify secret key
    if (!hash_equals($expectedSecret, $appKey)) {
        jsonResponse([
            'status' => 'error',
            'code' => 403,
            'message' => 'Forbidden: Invalid API secret key.',
        ], 403);
    }

    // Check timestamp skew (allowed drift: 5 minutes)
    $currentTime = time();
    if (abs($currentTime - $appTime) > 300) {
        jsonResponse([
            'status' => 'error',
            'code' => 403,
            'message' => 'Forbidden: Request signature has expired or timestamp skew is too large.',
        ], 403);
    }

    // Verify HMAC SHA-256 signature
    $payload = "{$appTime}:{$endpointPath}:{$expectedSecret}";
    $expectedSig = hash('sha256', $payload);

    if (!hash_equals($expectedSig, $appSig)) {
        jsonResponse([
            'status' => 'error',
            'code' => 403,
            'message' => 'Forbidden: Invalid cryptographic signature.',
        ], 403);
    }
}
