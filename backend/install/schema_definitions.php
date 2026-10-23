<?php
/**
 * PikaShow Schema Definitions and Database Inspection Helper
 * Defines all required tables, SQL schemas, and utilities to detect
 * existing vs missing tables so the installer never overwrites existing databases.
 */

class SchemaManager {
    /**
     * Complete registry of all backend database tables and their CREATE TABLE statements.
     * All statements are safe (CREATE TABLE IF NOT EXISTS).
     */
    public static function getTables(): array {
        return [
            'admin_users' => [
                'name' => 'admin_users',
                'title' => 'Admin Users',
                'category' => 'Core Auth',
                'description' => 'Admin dashboard user accounts, authentication credentials, and permissions.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(32) NOT NULL DEFAULT 'admin',
  `last_login` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'app_users' => [
                'name' => 'app_users',
                'title' => 'Mobile App Users',
                'category' => 'Mobile App',
                'description' => 'User profiles, auth tokens, device login records, and account statuses.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `app_users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'content_items' => [
                'name' => 'content_items',
                'title' => 'Content Catalog',
                'category' => 'Streaming',
                'description' => 'Movies (Bollywood & Hollywood), Web Series, and Live TV stream channels.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `content_items` (
  `id` VARCHAR(64) PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `type` ENUM('bollywood', 'hollywood', 'series', 'liveTv') NOT NULL,
  `poster_url` TEXT NOT NULL,
  `banner_url` TEXT NOT NULL,
  `rating` DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  `year` VARCHAR(32) NOT NULL DEFAULT '',
  `duration` VARCHAR(64) NOT NULL DEFAULT '',
  `synopsis` TEXT,
  `genres` TEXT COMMENT 'JSON array of genres',
  `languages` TEXT COMMENT 'JSON array of languages',
  `available_qualities` TEXT COMMENT 'JSON array of qualities',
  `stream_url` TEXT NOT NULL,
  `is_promoted` TINYINT(1) NOT NULL DEFAULT 0,
  `is_trending` TINYINT(1) NOT NULL DEFAULT 0,
  `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `channel_category` VARCHAR(64) NOT NULL DEFAULT '',
  `views` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_type` (`type`),
  INDEX `idx_promoted` (`is_promoted`),
  INDEX `idx_trending` (`is_trending`),
  INDEX `idx_premium` (`is_premium`),
  INDEX `idx_category` (`channel_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'seasons' => [
                'name' => 'seasons',
                'title' => 'Web Series Seasons',
                'category' => 'Streaming',
                'description' => 'Season groupings and metadata linked to web series content items.',
                'dependencies' => ['content_items'],
                'sql' => "CREATE TABLE IF NOT EXISTS `seasons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `content_id` VARCHAR(64) NOT NULL,
  `season_number` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_seasons_content` FOREIGN KEY (`content_id`) REFERENCES `content_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'episodes' => [
                'name' => 'episodes',
                'title' => 'Web Series Episodes',
                'category' => 'Streaming',
                'description' => 'Episode metadata, video streams, thumbnails, durations, and numbers.',
                'dependencies' => ['content_items', 'seasons'],
                'sql' => "CREATE TABLE IF NOT EXISTS `episodes` (
  `id` VARCHAR(64) PRIMARY KEY,
  `season_id` INT NOT NULL,
  `content_id` VARCHAR(64) NOT NULL,
  `episode_number` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `duration` VARCHAR(64) NOT NULL,
  `description` TEXT,
  `thumbnail` TEXT NOT NULL,
  `stream_url` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_episodes_season` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_episodes_content` FOREIGN KEY (`content_id`) REFERENCES `content_items` (`id`) ON DELETE CASCADE,
  INDEX `idx_season` (`season_id`),
  INDEX `idx_episodes_content` (`content_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'settings' => [
                'name' => 'settings',
                'title' => 'System Settings',
                'category' => 'Core Config',
                'description' => 'Application name, maintenance mode flags, and API security secrets.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(64) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'subscription_plans' => [
                'name' => 'subscription_plans',
                'title' => 'Subscription Plans',
                'category' => 'Monetization',
                'description' => 'VIP streaming tier plans, pricing, durations, and feature lists.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `subscription_plans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'user_subscriptions' => [
                'name' => 'user_subscriptions',
                'title' => 'User Subscriptions',
                'category' => 'Monetization',
                'description' => 'User purchase records, payment proof/links, and validity expiration.',
                'dependencies' => ['app_users', 'subscription_plans'],
                'sql' => "CREATE TABLE IF NOT EXISTS `user_subscriptions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'home_categories' => [
                'name' => 'home_categories',
                'title' => 'Home Categories & Shelf Ordering',
                'category' => 'Catalog Layout',
                'description' => 'Dynamic home screen rows, filters, placement order, and display toggles.',
                'dependencies' => [],
                'sql' => "CREATE TABLE IF NOT EXISTS `home_categories` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],
            'watch_progress' => [
                'name' => 'watch_progress',
                'title' => 'Watch Progress & Continue Watching',
                'category' => 'User State',
                'description' => 'User playback positions, continue watching queue, and resume state.',
                'dependencies' => ['app_users'],
                'is_core' => true,
                'sql' => "CREATE TABLE IF NOT EXISTS `watch_progress` (
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
  INDEX `idx_user_progress` (`user_id`, `updated_at`),
  CONSTRAINT `fk_watch_progress_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'user_favourites' => [
                'name' => 'user_favourites',
                'title' => 'User Saved Favourites',
                'category' => 'User State',
                'description' => 'User bookmarked and saved titles for quick access across devices.',
                'dependencies' => ['app_users', 'content_items'],
                'is_core' => true,
                'sql' => "CREATE TABLE IF NOT EXISTS `user_favourites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `content_id` VARCHAR(64) NOT NULL,
  `item_json` LONGTEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_favourite` (`user_id`, `content_id`),
  INDEX `idx_user_fav_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_user_fav_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],

            'user_history' => [
                'name' => 'user_history',
                'title' => 'User Watch History',
                'category' => 'User State',
                'description' => 'User playback watch history records and timestamps.',
                'dependencies' => ['app_users', 'content_items'],
                'is_core' => true,
                'sql' => "CREATE TABLE IF NOT EXISTS `user_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `content_id` VARCHAR(64) NOT NULL,
  `item_json` LONGTEXT NULL,
  `watched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_history` (`user_id`, `content_id`),
  INDEX `idx_user_hist_watched` (`user_id`, `watched_at`),
  CONSTRAINT `fk_user_hist_user` FOREIGN KEY (`user_id`) REFERENCES `app_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
            ],
        ];
    }


    /**
     * Inspect a MySQL server and inspect whether a database exists,
     * which tables are present, and which tables are missing.
     */
    public static function inspectDatabase(PDO $pdo, string $dbName): array {
        $tables = self::getTables();
        $allRequired = array_keys($tables);

        // Check if database exists
        $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname");
        $stmt->execute(['dbname' => $dbName]);
        $dbExists = (bool)$stmt->fetchColumn();

        if (!$dbExists) {
            return [
                'db_exists' => false,
                'database' => $dbName,
                'existing_tables' => [],
                'missing_tables' => $allRequired,
                'extra_tables' => [],
                'all_exist' => false,
                'has_missing' => true,
                'admin_count' => 0,
                'content_count' => 0,
            ];
        }

        // Database exists: find all tables inside it
        $tblStmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :dbname");
        $tblStmt->execute(['dbname' => $dbName]);
        $foundTables = $tblStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $existing = array_values(array_intersect($allRequired, $foundTables));
        $missing = array_values(array_diff($allRequired, $foundTables));
        $extra = array_values(array_diff($foundTables, $allRequired));

        // Count admin users if table exists
        $adminCount = 0;
        if (in_array('admin_users', $foundTables, true)) {
            try {
                $c = $pdo->query("SELECT COUNT(*) FROM `{$dbName}`.`admin_users`");
                $adminCount = (int)$c->fetchColumn();
            } catch (Exception $e) {
                $adminCount = 0;
            }
        }

        // Count content items if table exists
        $contentCount = 0;
        if (in_array('content_items', $foundTables, true)) {
            try {
                $c = $pdo->query("SELECT COUNT(*) FROM `{$dbName}`.`content_items`");
                $contentCount = (int)$c->fetchColumn();
            } catch (Exception $e) {
                $contentCount = 0;
            }
        }

        return [
            'db_exists' => true,
            'database' => $dbName,
            'existing_tables' => $existing,
            'missing_tables' => $missing,
            'extra_tables' => $extra,
            'all_exist' => empty($missing),
            'has_missing' => !empty($missing),
            'admin_count' => $adminCount,
            'content_count' => $contentCount,
        ];
    }

    /**
     * Install only the specified list of tables into the target database.
     * Guaranteed safe: Never drops or overwrites existing tables.
     */
    public static function installTables(PDO $pdo, string $dbName, array $tableNames): array {
        $registry = self::getTables();
        $installed = [];
        $errors = [];

        // Select the database
        $pdo->exec("USE `{$dbName}`;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

        // Maintain proper dependency order
        foreach ($registry as $tblKey => $tblDef) {
            if (!in_array($tblKey, $tableNames, true)) {
                continue;
            }

            try {
                $pdo->exec($tblDef['sql']);
                $installed[] = $tblKey;

                // Extra setup for newly installed tables
                if ($tblKey === 'subscription_plans') {
                    self::seedDefaultSubscriptionPlans($pdo);
                }
            } catch (Exception $e) {
                $errors[$tblKey] = $e->getMessage();
            }
        }

        // Ensure is_premium column on content_items if content_items is present
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM `content_items` LIKE 'is_premium'");
            if (!$colCheck->fetch()) {
                $pdo->exec("ALTER TABLE `content_items` ADD COLUMN `is_premium` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_trending`");
            }
        } catch (Exception $e) {
            // content_items might not exist yet or column already present
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

        return [
            'success' => empty($errors),
            'installed' => $installed,
            'errors' => $errors,
        ];
    }

    /**
     * Seed default subscription plans if table is empty
     */
    public static function seedDefaultSubscriptionPlans(PDO $pdo): void {
        try {
            $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `subscription_plans`")->fetchColumn();
            if ($cnt === 0) {
                $starterPlans = [
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

                $ins = $pdo->prepare("INSERT INTO subscription_plans (name, price, currency, duration_days, description, features, is_featured, status) VALUES (:name, :price, :curr, :dur, :desc, :feat, :feat_flag, 'active')");
                foreach ($starterPlans as $p) {
                    $ins->execute([
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
        } catch (Exception $e) {
            // Ignore seed error
        }
    }
}
