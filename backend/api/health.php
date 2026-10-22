<?php
/**
 * REST API: System Health Check
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$installed = Database::isInstalled();
$dbConnected = false;
$itemCount = 0;

if ($installed) {
    try {
        $pdo = Database::getInstance();
        if ($pdo) {
            $dbConnected = true;
            $cnt = $pdo->query("SELECT COUNT(*) as c FROM content_items")->fetch();
            $itemCount = (int)($cnt['c'] ?? 0);
        }
    } catch (Exception $e) {
        $dbConnected = false;
    }
}

jsonResponse([
    'status' => 'ok',
    'service' => 'PikaShow Backend API',
    'installed' => $installed,
    'database_connected' => $dbConnected,
    'total_content' => $itemCount,
    'php_version' => PHP_VERSION,
    'timestamp' => date('c'),
]);
