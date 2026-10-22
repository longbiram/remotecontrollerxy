<?php
/**
 * Global Admin Dashboard Header
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

Auth::requireAuth();

$currentUser = Auth::user();
$pageTitle = $pageTitle ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - PikaShow Admin</title>
    <!-- Modern Styling -->
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <!-- HLS.js for Live TV / M3U8 stream preview -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
</head>
<body>

<div class="app-container">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <?php 
        $flash = getFlash();
        if ($flash): 
        ?>
            <div class="toast-container">
                <div class="toast" style="border-left: 4px solid <?= $flash['type'] === 'success' ? '#10b981' : '#ef4444' ?>;">
                    <svg style="width: 20px; height: 20px; fill: <?= $flash['type'] === 'success' ? '#10b981' : '#ef4444' ?>;" viewBox="0 0 24 24">
                        <?php if ($flash['type'] === 'success'): ?>
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        <?php else: ?>
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                        <?php endif; ?>
                    </svg>
                    <span><?= htmlspecialchars($flash['message']) ?></span>
                </div>
            </div>
        <?php endif; ?>
