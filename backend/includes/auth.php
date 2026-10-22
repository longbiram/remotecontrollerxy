<?php
/**
 * Authentication management for PikaShow Admin Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

class Auth {
    public static function isLoggedIn(): bool {
        return !empty($_SESSION['admin_user']) && is_array($_SESSION['admin_user']);
    }

    public static function user(): ?array {
        return $_SESSION['admin_user'] ?? null;
    }

    public static function requireAuth(): void {
        if (!Database::isInstalled()) {
            header('Location: ' . getBaseUrl() . '/install/install.php');
            exit;
        }

        if (!self::isLoggedIn()) {
            header('Location: ' . getBaseUrl() . '/login.php');
            exit;
        }
    }

    public static function login(string $usernameOrEmail, string $password): array {
        if (!Database::isInstalled()) {
            return ['success' => false, 'message' => 'Backend is not installed yet.'];
        }

        $pdo = Database::getInstance();
        if (!$pdo) {
            return ['success' => false, 'message' => 'Cannot connect to database.'];
        }

        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = :user OR email = :email LIMIT 1');
        $stmt->execute(['user' => $usernameOrEmail, 'email' => $usernameOrEmail]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Invalid username or password.'];
        }

        // Update last login
        $update = $pdo->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = :id');
        $update->execute(['id' => $user['id']]);

        // Clean user array for session
        unset($user['password_hash']);
        $_SESSION['admin_user'] = $user;

        return ['success' => true, 'user' => $user];
    }

    public static function logout(): void {
        unset($_SESSION['admin_user']);
        session_regenerate_id(true);
        header('Location: ' . getBaseUrl() . '/login.php');
        exit;
    }

    public static function createAdmin(PDO $pdo, string $username, string $email, string $password): bool {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role) VALUES (:user, :email, :hash, "admin")');
        return $stmt->execute([
            'user' => $username,
            'email' => $email,
            'hash' => $hash,
        ]);
    }
}
