<?php
/**
 * REST API: Check for App Updates (Self-Hosted / Website Distribution)
 * Compares client's current version code with latest release in backend settings.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

// Allow CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Key, X-App-Time, X-App-Signature');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

// Client info passed via GET or POST
$clientVersionCode = (int)($_GET['version_code'] ?? ($_POST['version_code'] ?? 1));
$clientVersionName = trim($_GET['version_name'] ?? ($_POST['version_name'] ?? '1.0.0'));
$clientPlatform    = strtolower(trim($_GET['platform'] ?? ($_POST['platform'] ?? 'android')));

// Defaults
$latestVersionCode = 1;
$latestVersionName = '1.0.0';
$apkUrl = '';
$websiteUrl = getBaseUrl();
$forceUpdate = false;
$releaseNotes = "New features, speed optimizations, and stability improvements.";
$minSupportedVersionCode = 1;

try {
    $pdo = Database::getInstance();
    if ($pdo) {
        // Ensure settings table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key` VARCHAR(64) PRIMARY KEY,
            `setting_value` TEXT,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Fetch update settings
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN (
            'app_latest_version_code',
            'app_latest_version_name',
            'app_apk_url',
            'app_website_url',
            'app_force_update',
            'app_release_notes',
            'app_min_supported_version_code'
        )");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        if (!empty($settings['app_latest_version_code'])) {
            $latestVersionCode = (int)$settings['app_latest_version_code'];
        }
        if (!empty($settings['app_latest_version_name'])) {
            $latestVersionName = trim($settings['app_latest_version_name']);
        }
        if (!empty($settings['app_apk_url'])) {
            $apkUrl = trim($settings['app_apk_url']);
        }
        if (!empty($settings['app_website_url'])) {
            $websiteUrl = trim($settings['app_website_url']);
        }
        if (isset($settings['app_force_update'])) {
            $forceUpdate = ($settings['app_force_update'] === '1' || $settings['app_force_update'] === 'true');
        }
        if (!empty($settings['app_release_notes'])) {
            $releaseNotes = trim($settings['app_release_notes']);
        }
        if (!empty($settings['app_min_supported_version_code'])) {
            $minSupportedVersionCode = (int)$settings['app_min_supported_version_code'];
        }
    }
} catch (Exception $e) {
    // Fail gracefully with default values
}

// Fallback APK URL if not set in DB
if (empty($apkUrl)) {
    $apkUrl = getBaseUrl() . '/uploads/pikashow_latest.apk';
}

$hasUpdate = ($latestVersionCode > $clientVersionCode);

// If user's installed version is below minimum supported, force update regardless
if ($clientVersionCode < $minSupportedVersionCode) {
    $forceUpdate = true;
}

jsonResponse([
    'status' => 'success',
    'has_update' => $hasUpdate,
    'current_version_code' => $clientVersionCode,
    'latest_version_code' => $latestVersionCode,
    'latest_version_name' => $latestVersionName,
    'apk_url' => $apkUrl,
    'website_url' => $websiteUrl,
    'force_update' => $forceUpdate,
    'release_notes' => $releaseNotes,
    'checked_at' => date('c'),
]);
