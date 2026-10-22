<?php
/**
 * Main Admin Dashboard Overview
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

// First-time install check
if (!Database::isInstalled()) {
    header('Location: install/install.php');
    exit;
}

Auth::requireAuth();

$pdo = Database::getInstance();
ensureDatabaseTables($pdo);

// Gather catalog statistics
$stats = [
    'total' => 0,
    'bollywood' => 0,
    'hollywood' => 0,
    'series' => 0,
    'liveTv' => 0,
    'views' => 0,
    'episodes' => 0,
    'users' => 0,
    'active_users' => 0,
];

try {
    $stmt = $pdo->query("SELECT type, COUNT(*) as cnt, SUM(views) as total_views FROM content_items GROUP BY type");
    while ($row = $stmt->fetch()) {
        $type = $row['type'];
        $cnt = (int)$row['cnt'];
        $stats[$type] = $cnt;
        $stats['total'] += $cnt;
        $stats['views'] += (int)$row['total_views'];
    }

    $epStmt = $pdo->query("SELECT COUNT(*) as ep_cnt FROM episodes");
    $stats['episodes'] = (int)($epStmt->fetch()['ep_cnt'] ?? 0);

    // Fetch app users statistics
    try {
        $userStmt = $pdo->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users FROM app_users");
        if ($uRow = $userStmt->fetch()) {
            $stats['users'] = (int)$uRow['total_users'];
            $stats['active_users'] = (int)($uRow['active_users'] ?? 0);
        }
    } catch (Exception $e) {}

    // Fetch recent items
    $recentStmt = $pdo->query("SELECT * FROM content_items ORDER BY created_at DESC LIMIT 8");
    $recentItems = $recentStmt->fetchAll();

    // Fetch spotlight items
    $spotlightStmt = $pdo->query("SELECT * FROM content_items WHERE is_promoted = 1 OR is_trending = 1 ORDER BY rating DESC LIMIT 4");
    $spotlightItems = $spotlightStmt->fetchAll();

} catch (Exception $e) {
    setFlash('danger', 'Error loading statistics: ' . $e->getMessage());
    $recentItems = [];
    $spotlightItems = [];
}

$pageTitle = 'Dashboard Overview';
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Dashboard Overview</h1>
        <p class="page-subtitle">Welcome back, <?= htmlspecialchars($currentUser['username']) ?>! Here's your catalog status.</p>
    </div>
    <div class="topbar-actions">
        <a href="content_form.php" class="btn btn-primary">
            <svg style="width: 18px; height: 18px; fill: currentColor;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add New Content
        </a>
    </div>
</div>

<!-- Stats Counter Grid -->
<div class="stats-grid">
    <!-- Clickable App Users Stat Card -->
    <a href="users.php" class="stat-card stat-card-link" title="Click to view App Users list (10 per page)">
        <div>
            <div class="stat-label" style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                <span>App Users</span>
                <span style="font-size: 0.65rem; background: rgba(6, 182, 212, 0.2); color: #38bdf8; padding: 2px 6px; border-radius: 999px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.04em;">View All &rarr;</span>
            </div>
            <div class="stat-value"><?= number_format($stats['users']) ?></div>
            <span style="font-size: 0.75rem; color: #34d399; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; margin-top: 2px;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399;"></span>
                <?= number_format($stats['active_users']) ?> Active
            </span>
        </div>
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        </div>
    </a>

    <div class="stat-card">
        <div>
            <div class="stat-label">Total Items</div>
            <div class="stat-value"><?= number_format($stats['total']) ?></div>
        </div>
        <div class="stat-icon red">
            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Bollywood Movies</div>
            <div class="stat-value"><?= number_format($stats['bollywood']) ?></div>
        </div>
        <div class="stat-icon red">
            <svg viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Hollywood Movies</div>
            <div class="stat-value"><?= number_format($stats['hollywood']) ?></div>
        </div>
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Web Series</div>
            <div class="stat-value"><?= number_format($stats['series']) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);"><?= $stats['episodes'] ?> Episodes</span>
        </div>
        <div class="stat-icon purple">
            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Live TV Streams</div>
            <div class="stat-value"><?= number_format($stats['liveTv']) ?></div>
        </div>
        <div class="stat-icon emerald">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9v-2h2v2zm0-4H9V7h2v5zm4 4h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
        </div>
    </div>
</div>

<!-- Spotlight & Featured Highlights -->
<?php if (!empty($spotlightItems)): ?>
<div class="content-card">
    <div class="card-header">
        <h2 class="card-title">Promoted & Trending Spotlight</h2>
        <span style="font-size: 0.85rem; color: var(--text-muted);">Featured on app hero banner & trending carousels</span>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
        <?php foreach ($spotlightItems as $item): ?>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 14px; padding: 1rem; display: flex; gap: 1rem; align-items: center;">
                <img src="<?= htmlspecialchars($item['poster_url']) ?>" alt="poster" style="width: 55px; height: 75px; object-fit: cover; border-radius: 8px;">
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; gap: 0.4rem; margin-bottom: 0.3rem;">
                        <?php if ($item['is_promoted']): ?>
                            <span class="badge-promoted">Hero</span>
                        <?php endif; ?>
                        <?php if ($item['is_trending']): ?>
                            <span class="badge-trending">Trending</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-weight: 700; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff;">
                        <?= htmlspecialchars($item['title']) ?>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim); margin-top: 0.2rem;">
                        ★ <?= number_format($item['rating'], 1) ?> &bull; <?= htmlspecialchars($item['year']) ?> &bull; <?= htmlspecialchars($item['duration']) ?>
                    </div>
                </div>
                <div>
                    <button type="button" class="btn-icon play" onclick="openStreamPreview('<?= htmlspecialchars($item['stream_url']) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')" title="Test Stream">
                        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Content Table -->
<div class="content-card">
    <div class="card-header">
        <h2 class="card-title">Recent Catalog Items</h2>
        <a href="content_list.php" class="btn btn-secondary btn-sm">View All Content &rarr;</a>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title & Media</th>
                    <th>Type</th>
                    <th>Rating</th>
                    <th>Qualities</th>
                    <th>Tags</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentItems)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-dim);">
                            No content items found in catalog. <a href="content_form.php" style="color: var(--accent-red);">Add your first item</a>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentItems as $item): ?>
                        <?php 
                        $genres = json_decode($item['genres'] ?? '[]', true) ?: [];
                        $qualities = json_decode($item['available_qualities'] ?? '[]', true) ?: [];
                        ?>
                        <tr>
                            <td>
                                <div class="table-media-cell">
                                    <img src="<?= htmlspecialchars($item['poster_url']) ?>" alt="poster" class="table-poster" onerror="this.src='https://via.placeholder.com/44x62/1e2433/ffffff?text=Poster'">
                                    <div>
                                        <span class="table-title"><?= htmlspecialchars($item['title']) ?></span>
                                        <div class="table-meta">
                                            <?= htmlspecialchars($item['year']) ?> &bull; <?= htmlspecialchars($item['duration']) ?>
                                            <?php if (!empty($item['channel_category'])): ?>
                                                &bull; <?= htmlspecialchars($item['channel_category']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-type badge-<?= htmlspecialchars($item['type']) ?>">
                                    <?= htmlspecialchars($item['type']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 700; color: #fbbf24;">★ <?= number_format($item['rating'], 1) ?></span>
                            </td>
                            <td>
                                <?php foreach (array_slice($qualities, 0, 2) as $q): ?>
                                    <span class="badge-tag"><?= htmlspecialchars($q) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($item['is_promoted']): ?>
                                    <span class="badge-promoted">Hero</span>
                                <?php endif; ?>
                                <?php if ($item['is_trending']): ?>
                                    <span class="badge-trending">Trending</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.4rem;">
                                    <button type="button" class="btn-icon play" onclick="openStreamPreview('<?= htmlspecialchars($item['stream_url']) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')" title="Play Stream">
                                        <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </button>
                                    <?php if ($item['type'] === 'series'): ?>
                                        <a href="series_manage.php?id=<?= urlencode($item['id']) ?>" class="btn-icon" title="Manage Episodes">
                                            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                                        </a>
                                    <?php endif; ?>
                                    <a href="content_form.php?id=<?= urlencode($item['id']) ?>" class="btn-icon" title="Edit Item">
                                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
