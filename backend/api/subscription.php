<?php
/**
 * REST API: User Subscription Management & Approval Workflow
 * Handles selecting plans, fetching subscription state, receiving payment links,
 * submitting transaction IDs, and cancellation.
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
        'message' => 'Backend is not configured yet.',
    ], 503);
}

// 1. Authenticate user from Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';
if (empty($token) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token) && !empty($_REQUEST['token'])) {
    $token = trim($_REQUEST['token']);
}

if (empty($token) || $token === 'guest_token' || $token === 'guest_session') {
    jsonResponse([
        'status' => 'error',
        'code' => 'LOGIN_REQUIRED',
        'message' => 'Guest users cannot subscribe to plans. Please log in or create an account.',
    ], 401);
}

try {
    $pdo = Database::getInstance();
    ensureDatabaseTables($pdo);

    // Fetch user by token
    $uStmt = $pdo->prepare("SELECT id, name, email, status FROM app_users WHERE token = :token LIMIT 1");
    $uStmt->execute(['token' => $token]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse([
            'status' => 'error',
            'code' => 'INVALID_TOKEN',
            'message' => 'User session expired or invalid. Please sign in again.',
        ], 401);
    }

    $userId = (int)$user['id'];

    // -------------------------------------------------------------
    // GET: Check User Subscription Status & Details
    // -------------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        // Auto-expire any past active subscriptions
        $pdo->prepare("UPDATE user_subscriptions SET status = 'expired' WHERE user_id = :uid AND status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()")->execute(['uid' => $userId]);

        // Fetch current active or latest pending subscription
        $subStmt = $pdo->prepare("
            SELECT * FROM user_subscriptions 
            WHERE user_id = :uid 
            ORDER BY 
                CASE 
                    WHEN status = 'active' THEN 1
                    WHEN status = 'payment_submitted' THEN 2
                    WHEN status = 'payment_link_sent' THEN 3
                    WHEN status = 'pending_approval' THEN 4
                    ELSE 5
                END ASC, 
                id DESC 
            LIMIT 1
        ");
        $subStmt->execute(['uid' => $userId]);
        $currentSub = $subStmt->fetch(PDO::FETCH_ASSOC);

        $hasActive = false;
        $formattedSub = null;

        if ($currentSub) {
            $status = $currentSub['status'];
            $expiresAt = $currentSub['expires_at'];
            $daysLeft = 0;

            if ($status === 'active' && !empty($expiresAt)) {
                $expTimestamp = strtotime($expiresAt);
                $now = time();
                if ($expTimestamp > $now) {
                    $hasActive = true;
                    $daysLeft = (int)ceil(($expTimestamp - $now) / 86400);
                }
            }

            $formattedSub = [
                'id' => (int)$currentSub['id'],
                'planId' => (int)$currentSub['plan_id'],
                'planName' => $currentSub['plan_name'],
                'amount' => (float)$currentSub['amount'],
                'currency' => $currentSub['currency'],
                'durationDays' => (int)$currentSub['duration_days'],
                'status' => $currentSub['status'],
                'paymentLink' => $currentSub['payment_link'] ?? '',
                'paymentNotes' => $currentSub['payment_notes'] ?? '',
                'transactionId' => $currentSub['transaction_id'] ?? '',
                'activatedAt' => $currentSub['activated_at'],
                'expiresAt' => $currentSub['expires_at'],
                'daysLeft' => $daysLeft,
                'createdAt' => $currentSub['created_at'],
            ];
        }

        // Fetch history (last 5)
        $hStmt = $pdo->prepare("SELECT id, plan_name, amount, currency, status, created_at, expires_at FROM user_subscriptions WHERE user_id = :uid ORDER BY id DESC LIMIT 5");
        $hStmt->execute(['uid' => $userId]);
        $history = $hStmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'status' => 'success',
            'data' => [
                'hasActiveSubscription' => $hasActive,
                'subscription' => $formattedSub,
                'history' => $history,
            ],
        ]);
    }

    // -------------------------------------------------------------
    // POST: User Action (subscribe, submit_transaction, cancel)
    // -------------------------------------------------------------
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $subAction = trim($payload['action'] ?? '');

    // 1. SELECT & SUBSCRIBE TO A PLAN -> creates request with 'pending_approval'
    if ($subAction === 'subscribe') {
        $planId = (int)($payload['plan_id'] ?? 0);
        if ($planId <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'Valid plan ID is required.'], 422);
        }

        // Fetch plan
        $pStmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE id = :id AND status = 'active' LIMIT 1");
        $pStmt->execute(['id' => $planId]);
        $plan = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$plan) {
            jsonResponse(['status' => 'error', 'message' => 'The selected subscription plan does not exist or is inactive.'], 404);
        }

        // Cancel previous pending requests if any
        $pdo->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE user_id = :uid AND status IN ('pending_approval', 'payment_link_sent')")->execute(['uid' => $userId]);

        // Insert new subscription request
        $ins = $pdo->prepare("
            INSERT INTO user_subscriptions 
            (user_id, plan_id, plan_name, amount, currency, duration_days, status)
            VALUES (:uid, :pid, :pname, :amount, :curr, :dur, 'pending_approval')
        ");
        $ins->execute([
            'uid' => $userId,
            'pid' => $plan['id'],
            'pname' => $plan['name'],
            'amount' => $plan['price'],
            'curr' => $plan['currency'] ?? 'INR',
            'dur' => $plan['duration_days'],
        ]);

        $subId = (int)$pdo->lastInsertId();

        jsonResponse([
            'status' => 'success',
            'message' => 'Subscription request submitted! Please wait for admin approval and payment link.',
            'data' => [
                'subscriptionId' => $subId,
                'planName' => $plan['name'],
                'status' => 'pending_approval',
            ],
        ], 201);
    }

    // 2. SUBMIT TRANSACTION ID (after user completes payment via payment link)
    if ($subAction === 'submit_transaction') {
        $subscriptionId = (int)($payload['subscription_id'] ?? 0);
        $transactionId = trim($payload['transaction_id'] ?? '');

        if (empty($transactionId)) {
            jsonResponse(['status' => 'error', 'message' => 'Please enter a valid Transaction / Reference ID.'], 422);
        }

        // Fetch subscription
        $sStmt = $pdo->prepare("SELECT id, status FROM user_subscriptions WHERE id = :id AND user_id = :uid LIMIT 1");
        $sStmt->execute(['id' => $subscriptionId, 'uid' => $userId]);
        $sub = $sStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sub) {
            jsonResponse(['status' => 'error', 'message' => 'Subscription record not found.'], 404);
        }

        // Update transaction ID and set status to payment_submitted
        $up = $pdo->prepare("UPDATE user_subscriptions SET transaction_id = :tx, status = 'payment_submitted' WHERE id = :id");
        $up->execute([
            'tx' => $transactionId,
            'id' => $sub['id'],
        ]);

        jsonResponse([
            'status' => 'success',
            'message' => 'Transaction ID submitted successfully! The admin team will verify payment and activate your plan.',
            'data' => [
                'subscriptionId' => $sub['id'],
                'status' => 'payment_submitted',
                'transactionId' => $transactionId,
            ],
        ]);
    }

    // 3. CANCEL SUBSCRIPTION
    if ($subAction === 'cancel') {
        $subscriptionId = (int)($payload['subscription_id'] ?? 0);
        $clause = $subscriptionId > 0 ? "AND id = " . $pdo->quote((string)$subscriptionId) : "";

        $up = $pdo->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE user_id = :uid $clause AND status IN ('pending_approval', 'payment_link_sent', 'payment_submitted', 'active')");
        $up->execute(['uid' => $userId]);

        jsonResponse([
            'status' => 'success',
            'message' => 'Subscription cancelled. You can select a new plan anytime.',
        ]);
    }

    jsonResponse(['status' => 'error', 'message' => 'Invalid action.'], 400);

} catch (Exception $e) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Server error in subscription API: ' . $e->getMessage(),
    ], 500);
}
