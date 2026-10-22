<?php
/**
 * REST API: Subscription Plans Catalog
 * Returns list of active subscription plans configured by Admin
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Key, X-App-Time, X-App-Signature');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

if (!Database::isInstalled()) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Backend is not configured yet.',
    ], 503);
}

try {
    $pdo = Database::getInstance();
    ensureDatabaseTables($pdo);

    $stmt = $pdo->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price ASC");
    $plans = [];

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $features = json_decode($r['features'] ?? '[]', true);
        if (!is_array($features)) {
            $features = array_filter(array_map('trim', explode("\n", $r['features'] ?? '')));
        }

        $plans[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'currency' => $r['currency'] ?? 'INR',
            'durationDays' => (int)$r['duration_days'],
            'description' => (string)($r['description'] ?? ''),
            'features' => array_values($features),
            'isFeatured' => (bool)$r['is_featured'],
            'status' => $r['status'],
        ];
    }

    jsonResponse([
        'status' => 'success',
        'count' => count($plans),
        'data' => $plans,
    ]);

} catch (Exception $e) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Failed to load subscription plans: ' . $e->getMessage(),
    ], 500);
}
