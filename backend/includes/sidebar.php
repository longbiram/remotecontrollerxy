<?php
/**
 * Admin Dashboard Sidebar Navigation
 */

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentType = $_GET['type'] ?? '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-icon">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
        </div>
        <div class="brand-title">Pika<span>Show</span></div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="index.php" class="nav-item <?= $currentPage === 'index.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
            <span>Dashboard</span>
        </a>

        <div class="nav-section-title">Catalog Management</div>
        <a href="content_list.php?type=bollywood" class="nav-item <?= $currentPage === 'content_list.php' && $currentType === 'bollywood' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>
            <span>Bollywood Movies</span>
        </a>
        <a href="content_list.php?type=hollywood" class="nav-item <?= $currentPage === 'content_list.php' && $currentType === 'hollywood' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM8 15c0-1.66 1.34-3 3-3 .35 0 .69.07 1 .18V6h5v2h-3v7c0 1.66-1.34 3-3 3s-3-1.34-3-3z"/></svg>
            <span>Hollywood Movies</span>
        </a>
        <a href="content_list.php?type=series" class="nav-item <?= $currentPage === 'content_list.php' && $currentType === 'series' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
            <span>Web Series</span>
        </a>
        <a href="content_list.php?type=liveTv" class="nav-item <?= $currentPage === 'content_list.php' && $currentType === 'liveTv' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
            <span>Live TV Channels</span>
        </a>
        <a href="categories.php" class="nav-item <?= $currentPage === 'categories.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>
            <span>Home Categories & Placing</span>
        </a>

        <div class="nav-section-title">User Management</div>
        <a href="users.php" class="nav-item <?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            <span>App Users</span>
        </a>

        <div class="nav-section-title">Actions</div>
        <a href="content_form.php" class="nav-item <?= $currentPage === 'content_form.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            <span>Add New Content</span>
        </a>
        <a href="subscriptions.php" class="nav-item <?= $currentPage === 'subscriptions.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            <span>Subscriptions & Plans</span>
        </a>
        <a href="database_manager.php" class="nav-item <?= $currentPage === 'database_manager.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7c0-2.21-3.58-4-8-4zm0 2c3.87 0 6 1.5 6 2s-2.13 2-6 2-6-1.5-6-2 2.13-2 6-2zm6 5c0 .5-2.13 2-6 2s-6-1.5-6-2V8.77C7.38 9.53 9.5 10 12 10s4.62-.47 6-1.23V10zm0 5c0 .5-2.13 2-6 2s-6-1.5-6-2v-1.23c1.38.76 3.5 1.23 6 1.23s4.62-.47 6-1.23V15zm0 2c0 .5-2.13 2-6 2s-6-1.5-6-2v-1.23c1.38.76 3.5 1.23 6 1.23s4.62-.47 6-1.23V17z"/></svg>
            <span>Database Studio</span>
        </a>
        <a href="backup.php" class="nav-item <?= $currentPage === 'backup.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
            <span>Backup & Restore</span>
        </a>
        <a href="settings.php" class="nav-item <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
            <span>Settings & API</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-profile">
            <div class="user-avatar"><?= strtoupper(substr($currentUser['username'] ?? 'A', 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($currentUser['username'] ?? 'Admin') ?></div>
                <div class="role"><?= htmlspecialchars($currentUser['role'] ?? 'admin') ?></div>
            </div>
        </div>
        <a href="logout.php" class="btn-icon" title="Logout">
            <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        </a>
    </div>
</aside>
