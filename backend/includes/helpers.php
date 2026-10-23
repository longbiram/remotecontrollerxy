<?php
/**
 * Helper utility functions for PikaShow Backend
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get the base URL for the backend automatically
 */
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Calculate backend root path from script name
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    // If inside a subfolder (like /install or /api), strip it
    $scriptDir = preg_replace('#/(install|api|includes|assets|uploads).*$#i', '', $scriptDir);
    $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');

    return $protocol . $host . $scriptDir;
}

/**
 * Format JSON response for APIs
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE, PUT');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        exit(0);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * CSRF token helpers
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Flash messaging for user feedback
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message,
    ];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Sanitize input string
 */
function sanitize(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate a slug or unique ID for content items
 */
function generateContentId(string $title, string $type): string {
    $prefix = match($type) {
        'bollywood' => 'bolly_',
        'hollywood' => 'holly_',
        'series' => 'series_',
        'liveTv' => 'tv_',
        default => 'item_'
    };
    $clean = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($title));
    $clean = trim($clean, '_');
    $short = substr($clean, 0, 15);
    return $prefix . ($short ? $short . '_' : '') . substr(bin2hex(random_bytes(3)), 0, 4);
}

/**
 * Handle file upload for poster, banner, video, or thumb
 */
function handleFileUpload(array $file, string $type): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Uploaded file exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE  => 'Uploaded file exceeds MAX_FILE_SIZE in HTML form',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload',
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Unknown upload error'];
    }

    $folder = match($type) {
        'poster' => 'posters',
        'banner' => 'banners',
        'video'  => 'videos',
        'thumb'  => 'thumbs',
        default  => 'misc',
    };

    $targetDir = dirname(__DIR__) . '/uploads/' . $folder . '/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate allowed extensions
    $allowed = match($type) {
        'video' => ['mp4', 'mkv', 'webm', 'm4v', 'mov', 'ts', 'm3u8'],
        default => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
    };

    if (!in_array($ext, $allowed, true)) {
        return ['success' => false, 'error' => "Invalid file extension '.$ext'. Allowed: " . implode(', ', $allowed)];
    }

    // Generate safe random file name
    $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $targetDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $publicUrl = getBaseUrl() . '/uploads/' . $folder . '/' . $fileName;
        return [
            'success' => true,
            'url' => $publicUrl,
            'fileName' => $fileName,
            'relativePath' => 'uploads/' . $folder . '/' . $fileName,
        ];
    }

    return ['success' => false, 'error' => 'Failed to move uploaded file to destination directory'];
}

/**
 * Format bytes into human readable size
 */
function formatBytes(int $bytes, int $precision = 2): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Ensure all required tables and columns exist automatically
 */
function ensureDatabaseTables(PDO $pdo, bool $force = false): void {
    static $alreadyRun = false;
    if ($alreadyRun && !$force) return;
    $alreadyRun = true;

    try {
        // 1. app_users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `app_users` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Ensure token, avatar, and last_login columns exist if table predated these fields
        $checkColToken = $pdo->query("SHOW COLUMNS FROM `app_users` LIKE 'token'");
        if (!$checkColToken->fetch()) {
            $pdo->exec("ALTER TABLE `app_users` ADD COLUMN `token` VARCHAR(255) NULL AFTER `status`, ADD INDEX `idx_token` (`token`)");
        }
        $checkColAvatar = $pdo->query("SHOW COLUMNS FROM `app_users` LIKE 'avatar'");
        if (!$checkColAvatar->fetch()) {
            $pdo->exec("ALTER TABLE `app_users` ADD COLUMN `avatar` TEXT NULL AFTER `password_hash`");
        }
        $checkColLogin = $pdo->query("SHOW COLUMNS FROM `app_users` LIKE 'last_login'");
        if (!$checkColLogin->fetch()) {
            $pdo->exec("ALTER TABLE `app_users` ADD COLUMN `last_login` DATETIME NULL AFTER `token`");
        }

        // 2. is_premium column on content_items
        $checkCol = $pdo->query("SHOW COLUMNS FROM `content_items` LIKE 'is_premium'");
        if (!$checkCol->fetch()) {
            $pdo->exec("ALTER TABLE `content_items` ADD COLUMN `is_premium` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_trending`");
        }

        // 3. subscription_plans table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `subscription_plans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `price` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `duration_days` INT NOT NULL DEFAULT 30,
            `description` TEXT NULL,
            `features` TEXT NULL,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed starter plans if empty
        $planCount = $pdo->query("SELECT COUNT(*) FROM `subscription_plans`")->fetchColumn();
        if ($planCount == 0) {
            $seedPlans = [
                [
                    'name' => 'Monthly VIP Pass',
                    'price' => 199.00,
                    'currency' => 'INR',
                    'duration_days' => 30,
                    'description' => 'Perfect for monthly streaming of latest movies & live TV',
                    'features' => json_encode(['All Premium Movies & Series', 'Full HD 1080p Streaming', 'Live Sports & TV Channels', 'Ad-Free Experience', 'Multi-device Cast']),
                    'is_featured' => 0,
                ],
                [
                    'name' => 'Quarterly Cinephile',
                    'price' => 499.00,
                    'currency' => 'INR',
                    'duration_days' => 90,
                    'description' => 'Best value! 3 months of uninterrupted 4K Ultra HD access',
                    'features' => json_encode(['Everything in Monthly VIP', '4K Ultra HD & Dolby Atmos', 'Priority Streaming Servers', 'Download for Offline Viewing', 'Cancel Anytime']),
                    'is_featured' => 1,
                ],
                [
                    'name' => 'Annual Ultra VIP',
                    'price' => 1499.00,
                    'currency' => 'INR',
                    'duration_days' => 365,
                    'description' => 'Save 37% with full year access to the entire entertainment catalog',
                    'features' => json_encode(['Full 12 Months Access', 'VIP Early Releases', 'Simultaneous Multi-Screen Cast', 'Ultra HD Streaming', 'Dedicated 24/7 VIP Support']),
                    'is_featured' => 0,
                ],
            ];

            $insertPlan = $pdo->prepare("INSERT INTO subscription_plans (name, price, currency, duration_days, description, features, is_featured, status) VALUES (:name, :price, :curr, :dur, :desc, :feat, :feat_flag, 'active')");
            foreach ($seedPlans as $p) {
                $insertPlan->execute([
                    'name' => $p['name'],
                    'price' => $p['price'],
                    'curr' => $p['currency'],
                    'dur' => $p['duration_days'],
                    'desc' => $p['description'],
                    'feat' => $p['features'],
                    'feat_flag' => $p['is_featured'],
                ]);
            }
        }

        // 4. user_subscriptions table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_subscriptions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `plan_id` INT NOT NULL,
            `plan_name` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `duration_days` INT NOT NULL DEFAULT 30,
            `status` ENUM('pending_approval', 'payment_link_sent', 'payment_submitted', 'active', 'expired', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending_approval',
            `payment_link` TEXT NULL,
            `payment_notes` TEXT NULL,
            `transaction_id` VARCHAR(191) NULL,
            `activated_at` DATETIME NULL,
            `expires_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_user` (`user_id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. home_categories table (admin customizable categories & placing)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `home_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(100) NOT NULL,
            `slug` VARCHAR(100) NOT NULL UNIQUE,
            `subtitle` VARCHAR(255) NULL,
            `content_type` ENUM('all', 'movies', 'bollywood', 'hollywood', 'series', 'liveTv', 'genre') NOT NULL DEFAULT 'movies',
            `filter_genre` VARCHAR(100) NULL DEFAULT '',
            `sort_by` VARCHAR(50) NOT NULL DEFAULT 'latest',
            `display_order` INT NOT NULL DEFAULT 0,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_display_order` (`display_order`),
            INDEX `idx_is_enabled` (`is_enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // Seed default home categories if empty
        $catCount = $pdo->query("SELECT COUNT(*) FROM `home_categories`")->fetchColumn();
        if ($catCount == 0) {
            $seedCats = [
                [
                    'title' => 'Latest Movies',
                    'slug' => 'movies',
                    'subtitle' => 'Fresh releases & cinema blockbusters (Latest first)',
                    'content_type' => 'movies',
                    'filter_genre' => '',
                    'sort_by' => 'latest',
                    'display_order' => 1,
                    'is_enabled' => 1,
                ],
                [
                    'title' => 'Bollywood Blockbusters',
                    'slug' => 'bollywood',
                    'subtitle' => 'Trending Hindi cinema & premieres (Latest first)',
                    'content_type' => 'bollywood',
                    'filter_genre' => '',
                    'sort_by' => 'latest',
                    'display_order' => 2,
                    'is_enabled' => 1,
                ],
                [
                    'title' => 'Hollywood Hits',
                    'slug' => 'hollywood',
                    'subtitle' => 'English & Hindi Dubbed blockbusters (Latest first)',
                    'content_type' => 'hollywood',
                    'filter_genre' => '',
                    'sort_by' => 'latest',
                    'display_order' => 3,
                    'is_enabled' => 1,
                ],
                [
                    'title' => 'Binge-Worthy Web Series',
                    'slug' => 'series',
                    'subtitle' => 'Full seasons and latest episodes',
                    'content_type' => 'series',
                    'filter_genre' => '',
                    'sort_by' => 'latest',
                    'display_order' => 4,
                    'is_enabled' => 1,
                ],
                [
                    'title' => 'Live TV Channels',
                    'slug' => 'liveTv',
                    'subtitle' => 'Cricket tournaments, 24/7 news & entertainment',
                    'content_type' => 'liveTv',
                    'filter_genre' => '',
                    'sort_by' => 'latest',
                    'display_order' => 5,
                    'is_enabled' => 1,
                ],
            ];

            $insCat = $pdo->prepare("INSERT INTO home_categories (title, slug, subtitle, content_type, filter_genre, sort_by, display_order, is_enabled) VALUES (:title, :slug, :subtitle, :type, :genre, :sort, :ord, :en)");
            foreach ($seedCats as $c) {
                $insCat->execute([
                    'title' => $c['title'],
                    'slug' => $c['slug'],
                    'subtitle' => $c['subtitle'],
                    'type' => $c['content_type'],
                    'genre' => $c['filter_genre'],
                    'sort' => $c['sort_by'],
                    'ord' => $c['display_order'],
                    'en' => $c['is_enabled'],
                ]);
            }
        }

        // 6. watch_progress table (User Continue Watching state)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `watch_progress` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `content_id` VARCHAR(64) NOT NULL,
            `episode_id` VARCHAR(64) NULL DEFAULT NULL,
            `position_seconds` INT NOT NULL DEFAULT 0,
            `duration_seconds` INT NOT NULL DEFAULT 0,
            `item_json` LONGTEXT NULL,
            `episode_json` LONGTEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_content_ep` (`user_id`, `content_id`, `episode_id`),
            INDEX `idx_user_progress` (`user_id`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. user_favourites table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_favourites` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `content_id` VARCHAR(64) NOT NULL,
            `item_json` LONGTEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_favourite` (`user_id`, `content_id`),
            INDEX `idx_user_fav_created` (`user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. user_history table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_history` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `content_id` VARCHAR(64) NOT NULL,
            `item_json` LONGTEXT NULL,
            `watched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_user_history` (`user_id`, `content_id`),
            INDEX `idx_user_hist_watched` (`user_id`, `watched_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    } catch (Exception $e) {
        error_log("ensureDatabaseTables error: " . $e->getMessage());
    }
}


