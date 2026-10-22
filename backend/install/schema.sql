-- PikaShow Database Schema
-- Compatible with MySQL 5.7+ / 8.0+ and MariaDB 10.3+
-- Safe execution: All tables use CREATE TABLE IF NOT EXISTS (No DROP TABLE)

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Admin Users Table
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` VARCHAR(32) NOT NULL DEFAULT 'admin',
  `last_login` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Mobile App Users Table
CREATE TABLE IF NOT EXISTS `app_users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Content Items Table (Bollywood, Hollywood, Series, Live TV)
CREATE TABLE IF NOT EXISTS `content_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Web Series Seasons Table
CREATE TABLE IF NOT EXISTS `seasons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `content_id` VARCHAR(64) NOT NULL,
  `season_number` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_seasons_content` FOREIGN KEY (`content_id`) REFERENCES `content_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Episodes Table
CREATE TABLE IF NOT EXISTS `episodes` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. System Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(64) PRIMARY KEY,
  `setting_value` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Subscription Plans Table
CREATE TABLE IF NOT EXISTS `subscription_plans` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. User Subscriptions Table
CREATE TABLE IF NOT EXISTS `user_subscriptions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Home Categories & Shelf Ordering Table
CREATE TABLE IF NOT EXISTS `home_categories` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
