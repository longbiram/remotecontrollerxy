<?php
/**
 * Content List & Management View
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();

// Handle deletion
if (isset($_GET['delete'])) {
    if (!verifyCsrfToken($_GET['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: content_list.php');
        exit;
    }
    $deleteId = trim($_GET['delete']);
    try {
        $del = $pdo->prepare("DELETE FROM content_items WHERE id = :id");
        $del->execute(['id' => $deleteId]);
        setFlash('success', "Item #{$deleteId} successfully removed.");
    } catch (Exception $e) {
        setFlash('danger', 'Failed to delete item: ' . $e->getMessage());
    }
    header('Location: content_list.php' . (!empty($_GET['type']) ? '?type=' . urlencode($_GET['type']) : ''));
    exit;
}

// Filters
$typeFilter = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM content_items WHERE 1=1";
$params = [];

if (!empty($typeFilter)) {
    $sql .= " AND type = :type";
    $params['type'] = $typeFilter;
}

if (!empty($search)) {
    $sql .= " AND (title LIKE :q OR synopsis LIKE :q OR genres LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$pageTitle = match($typeFilter) {
    'bollywood' => 'Bollywood Movies',
    'hollywood' => 'Hollywood Movies',
    'series' => 'Web Series Catalog',
    'liveTv' => 'Live TV Channels',
    default => 'All Catalog Content'
};

require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle">Showing <?= count($items) ?> content title(s)</p>
    </div>
    <div class="topbar-actions">
        <a href="content_form.php<?= !empty($typeFilter) ? '?type=' . urlencode($typeFilter) : '' ?>" class="btn btn-primary">
            <svg style="width: 18px; height: 18px; fill: currentColor;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add New Item
        </a>
    </div>
</div>

<div class="content-card">
    <div class="card-header">
        <!-- Filter Tabs -->
        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
            <a href="content_list.php" class="btn btn-secondary btn-sm <?= empty($typeFilter) ? 'active' : '' ?>">All</a>
            <a href="content_list.php?type=bollywood" class="btn btn-secondary btn-sm <?= $typeFilter === 'bollywood' ? 'active' : '' ?>">Bollywood</a>
            <a href="content_list.php?type=hollywood" class="btn btn-secondary btn-sm <?= $typeFilter === 'hollywood' ? 'active' : '' ?>">Hollywood</a>
            <a href="content_list.php?type=series" class="btn btn-secondary btn-sm <?= $typeFilter === 'series' ? 'active' : '' ?>">Web Series</a>
            <a href="content_list.php?type=liveTv" class="btn btn-secondary btn-sm <?= $typeFilter === 'liveTv' ? 'active' : '' ?>">Live TV</a>
        </div>

        <!-- Search Bar -->
        <form method="GET" action="content_list.php" style="display: flex; gap: 0.5rem;">
            <?php if (!empty($typeFilter)): ?>
                <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
            <?php endif; ?>
            <input type="text" name="q" class="form-input" style="padding: 0.45rem 0.9rem; font-size: 0.85rem; width: 220px;" placeholder="Search title, genre..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if (!empty($search)): ?>
                <a href="content_list.php<?= !empty($typeFilter) ? '?type=' . urlencode($typeFilter) : '' ?>" class="btn btn-secondary btn-sm">&times;</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title & Media</th>
                    <th>Type</th>
                    <th>Rating</th>
                    <th>Languages</th>
                    <th>Duration</th>
                    <th>Qualities</th>
                    <th>Tags</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-dim);">
                            No items found matching your filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php 
                        $genres = json_decode($item['genres'] ?? '[]', true) ?: [];
                        $languages = json_decode($item['languages'] ?? '[]', true) ?: [];
                        $qualities = json_decode($item['available_qualities'] ?? '[]', true) ?: [];
                        ?>
                        <tr>
                            <td>
                                <div class="table-media-cell">
                                    <img src="<?= htmlspecialchars($item['poster_url']) ?>" alt="poster" class="table-poster" onerror="this.src='https://via.placeholder.com/44x62/1e2433/ffffff?text=Poster'">
                                    <div>
                                        <span class="table-title"><?= htmlspecialchars($item['title']) ?></span>
                                        <div class="table-meta">
                                            ID: <code><?= htmlspecialchars($item['id']) ?></code> &bull; <?= htmlspecialchars($item['year']) ?>
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
                                <span style="font-size: 0.85rem; color: var(--text-muted);"><?= htmlspecialchars(implode(', ', array_slice($languages, 0, 2))) ?></span>
                            </td>
                            <td>
                                <span style="font-size: 0.85rem;"><?= htmlspecialchars($item['duration']) ?></span>
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
                                <?php if (!empty($item['is_premium'])): ?>
                                    <span class="badge" style="background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); font-size: 0.65rem; padding: 2px 6px;">VIP</span>
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
                                    <a href="content_list.php?delete=<?= urlencode($item['id']) ?>&csrf=<?= generateCsrfToken() ?><?= !empty($typeFilter) ? '&type=' . urlencode($typeFilter) : '' ?>" class="btn-icon delete" data-confirm="Are you sure you want to delete '<?= htmlspecialchars(addslashes($item['title'])) ?>'?" title="Delete Item">
                                        <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
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
