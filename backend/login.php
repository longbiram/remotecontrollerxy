<?php
/**
 * Admin Login Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// First-time install check:
if (!Database::isInstalled()) {
    header('Location: install/install.php');
    exit;
}

// If already logged in, forward to dashboard:
if (Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

// Brute-force throttling
$failedAttempts = (int)($_SESSION['login_attempts'] ?? 0);
$lastAttemptTime = (int)($_SESSION['last_attempt_time'] ?? 0);
$isLockedOut = ($failedAttempts >= 5) && ((time() - $lastAttemptTime) < 300);

if ($isLockedOut) {
    $remaining = 300 - (time() - $lastAttemptTime);
    $error = "Too many failed login attempts. Please wait {$remaining} seconds before trying again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($usernameOrEmail) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        $result = Auth::login($usernameOrEmail, $password);
        if ($result['success']) {
            unset($_SESSION['login_attempts'], $_SESSION['last_attempt_time']);
            header('Location: index.php');
            exit;
        } else {
            $_SESSION['login_attempts'] = $failedAttempts + 1;
            $_SESSION['last_attempt_time'] = time();
            $error = $result['message'];
        }
    }
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - PikaShow</title>
    <link rel="stylesheet" href="assets/css/installer.css">
</head>
<body>

<div class="installer-card" style="max-width: 440px;">
    <div class="brand-header">
        <div class="brand-badge">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
            PikaShow
        </div>
        <h1 class="installer-title">Admin Sign In</h1>
        <p class="installer-subtitle">Enter your credentials to manage streaming content</p>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <div class="form-group">
            <label class="form-label" for="username">Username or Email</label>
            <input type="text" id="username" name="username" class="form-input" placeholder="admin or admin@pikashow.com" required autofocus>
        </div>

        <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <input type="password" id="password" name="password" class="form-input" placeholder="••••••••" required>
        </div>

        <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                Sign In to Dashboard
            </button>
        </div>
    </form>

    <div style="margin-top: 1.5rem; text-align: center; font-size: 0.8rem; color: var(--text-muted);">
        PikaShow Streaming Media Platform &copy; <?= date('Y') ?>
    </div>
</div>

</body>
</html>
