<?php
/**
 * Web Series Season & Episode Management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();
$seriesId = trim($_GET['id'] ?? '');

if (empty($seriesId)) {
    setFlash('danger', 'Series ID is required.');
    header('Location: content_list.php?type=series');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM content_items WHERE id = :id AND type = 'series' LIMIT 1");
$stmt->execute(['id' => $seriesId]);
$series = $stmt->fetch();

if (!$series) {
    setFlash('danger', "Series '{$seriesId}' not found.");
    header('Location: content_list.php?type=series');
    exit;
}

// Handle Add Season
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_season') {
    $snum = (int)($_POST['season_number'] ?? 1);
    $stitle = trim($_POST['season_title'] ?? "Season {$snum}");

    try {
        $sStmt = $pdo->prepare("INSERT INTO seasons (content_id, season_number, title) VALUES (:cid, :num, :title)");
        $sStmt->execute(['cid' => $seriesId, 'num' => $snum, 'title' => $stitle]);
        setFlash('success', "Season {$snum} added successfully.");
    } catch (Exception $e) {
        setFlash('danger', 'Error adding season: ' . $e->getMessage());
    }
    header('Location: series_manage.php?id=' . urlencode($seriesId));
    exit;
}

// Handle Add Episode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_episode') {
    $seasonId = (int)($_POST['season_id'] ?? 0);
    $epNum = (int)($_POST['episode_number'] ?? 1);
    $epTitle = trim($_POST['episode_title'] ?? '');
    $epDuration = trim($_POST['episode_duration'] ?? '45m');
    $epDesc = trim($_POST['episode_desc'] ?? '');
    
    // Thumbnail: Upload or URL
    $thumbUrl = trim($_POST['episode_thumb_url'] ?? '');
    if (!empty($_FILES['episode_thumb_file']['name'])) {
        $up = handleFileUpload($_FILES['episode_thumb_file'], 'thumb');
        if ($up['success']) $thumbUrl = $up['url'];
    }
    if (empty($thumbUrl)) {
        $thumbUrl = $series['poster_url']; // fallback to series poster
    }

    // Video Stream: Upload or URL
    $epStreamUrl = trim($_POST['episode_stream_url'] ?? '');
    if (!empty($_FILES['episode_stream_file']['name'])) {
        $up = handleFileUpload($_FILES['episode_stream_file'], 'video');
        if ($up['success']) $epStreamUrl = $up['url'];
    }

    $epId = $seriesId . '_s' . $seasonId . '_e' . $epNum . '_' . substr(bin2hex(random_bytes(2)), 0, 4);

    try {
        $epStmt = $pdo->prepare("INSERT INTO episodes (id, season_id, content_id, episode_number, title, duration, description, thumbnail, stream_url) 
            VALUES (:id, :sid, :cid, :num, :title, :dur, :desc, :thumb, :stream)");
        $epStmt->execute([
            'id' => $epId,
            'sid' => $seasonId,
            'cid' => $seriesId,
            'num' => $epNum,
            'title' => $epTitle ?: "Episode {$epNum}",
            'dur' => $epDuration,
            'desc' => $epDesc,
            'thumb' => $thumbUrl,
            'stream' => $epStreamUrl,
        ]);
        setFlash('success', "Episode #{$epNum} added.");
    } catch (Exception $e) {
        setFlash('danger', 'Error adding episode: ' . $e->getMessage());
    }
    header('Location: series_manage.php?id=' . urlencode($seriesId));
    exit;
}

// Handle Delete Episode
if (isset($_GET['delete_episode'])) {
    if (!verifyCsrfToken($_GET['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }
    $delEpId = trim($_GET['delete_episode']);
    try {
        $del = $pdo->prepare("DELETE FROM episodes WHERE id = :id AND content_id = :cid");
        $del->execute(['id' => $delEpId, 'cid' => $seriesId]);
        setFlash('success', 'Episode deleted.');
    } catch (Exception $e) {
        setFlash('danger', 'Error deleting episode: ' . $e->getMessage());
    }
    header('Location: series_manage.php?id=' . urlencode($seriesId));
    exit;
}

// Fetch all seasons and episodes for this series
$seasonsStmt = $pdo->prepare("SELECT * FROM seasons WHERE content_id = :cid ORDER BY season_number ASC");
$seasonsStmt->execute(['cid' => $seriesId]);
$seasons = $seasonsStmt->fetchAll();

foreach ($seasons as &$s) {
    $epListStmt = $pdo->prepare("SELECT * FROM episodes WHERE season_id = :sid ORDER BY episode_number ASC");
    $epListStmt->execute(['sid' => $s['id']]);
    $s['episodes'] = $epListStmt->fetchAll();
}
unset($s);

$pageTitle = "Manage Episodes: {$series['title']}";
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($series['title']) ?></h1>
        <p class="page-subtitle">Configure seasons and episodes for this web series</p>
    </div>
    <div class="topbar-actions">
        <a href="content_form.php?id=<?= urlencode($series['id']) ?>" class="btn btn-secondary">
            Edit Series Details
        </a>
        <a href="content_list.php?type=series" class="btn btn-secondary">
            &larr; Back to Series
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
    <!-- Seasons & Episodes List -->
    <div>
        <?php if (empty($seasons)): ?>
            <div class="content-card" style="text-align: center; padding: 3rem 1.5rem;">
                <h3 style="margin-bottom: 0.5rem;">No Seasons Created Yet</h3>
                <p style="color: var(--text-muted); margin-bottom: 1.5rem;">Use the panel on the right to create Season 1.</p>
            </div>
        <?php else: ?>
            <?php foreach ($seasons as $s): ?>
                <div class="content-card" style="margin-bottom: 1.5rem;">
                    <div class="card-header">
                        <div>
                            <h3 class="card-title"><?= htmlspecialchars($s['title']) ?></h3>
                            <span style="font-size: 0.8rem; color: var(--text-dim);"><?= count($s['episodes']) ?> Episode(s)</span>
                        </div>
                    </div>

                    <?php if (empty($s['episodes'])): ?>
                        <div style="padding: 1.5rem; text-align: center; color: var(--text-dim); font-size: 0.9rem;">
                            No episodes in this season yet. Add one below.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Ep #</th>
                                        <th>Episode Title</th>
                                        <th>Duration</th>
                                        <th>Preview</th>
                                        <th style="text-align: right;">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($s['episodes'] as $ep): ?>
                                        <tr>
                                            <td style="font-weight: 700; width: 60px;">#<?= $ep['episode_number'] ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                    <img src="<?= htmlspecialchars($ep['thumbnail']) ?>" alt="thumb" style="width: 50px; height: 32px; object-fit: cover; border-radius: 6px;">
                                                    <div>
                                                        <div style="font-weight: 600; color: #fff;"><?= htmlspecialchars($ep['title']) ?></div>
                                                        <div style="font-size: 0.75rem; color: var(--text-dim);"><?= htmlspecialchars(substr($ep['description'] ?? '', 0, 45)) ?>...</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($ep['duration']) ?></td>
                                            <td>
                                                <button type="button" class="btn-icon play" onclick="openStreamPreview('<?= htmlspecialchars($ep['stream_url']) ?>', '<?= htmlspecialchars(addslashes($ep['title'])) ?>')">
                                                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                                </button>
                                            </td>
                                            <td style="text-align: right;">
                                                <a href="series_manage.php?id=<?= urlencode($seriesId) ?>&delete_episode=<?= urlencode($ep['id']) ?>&csrf=<?= generateCsrfToken() ?>" class="btn-icon delete" data-confirm="Delete episode <?= htmlspecialchars(addslashes($ep['title'])) ?>?">
                                                    <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Forms Column (Add Season / Add Episode) -->
    <div>
        <!-- Add Season Box -->
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 1rem;">Add New Season</h3>
            <form method="POST" action="series_manage.php?id=<?= urlencode($seriesId) ?>">
                <input type="hidden" name="action" value="add_season">
                <div class="form-group">
                    <label class="form-label">Season Number</label>
                    <input type="number" name="season_number" class="form-input" value="<?= count($seasons) + 1 ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Season Title</label>
                    <input type="text" name="season_title" class="form-input" value="Season <?= count($seasons) + 1 ?>" required>
                </div>
                <button type="submit" class="btn btn-secondary" style="width: 100%;">Create Season</button>
            </form>
        </div>

        <!-- Add Episode Box -->
        <?php if (!empty($seasons)): ?>
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 1rem;">Add Episode</h3>
            <form method="POST" action="series_manage.php?id=<?= urlencode($seriesId) ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_episode">
                
                <div class="form-group">
                    <label class="form-label">Select Season</label>
                    <select name="season_id" class="form-input">
                        <?php foreach ($seasons as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Episode #</label>
                        <input type="number" name="episode_number" class="form-input" value="1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Duration</label>
                        <input type="text" name="episode_duration" class="form-input" value="45m" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Episode Title</label>
                    <input type="text" name="episode_title" class="form-input" placeholder="e.g. Tetua" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Synopsis</label>
                    <textarea name="episode_desc" class="form-input" rows="2" placeholder="Episode summary..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Stream URL (or upload)</label>
                    <input type="url" name="episode_stream_url" class="form-input" placeholder="https://example.com/ep.mp4">
                    <div style="margin-top: 0.35rem;">
                        <input type="file" name="episode_stream_file" class="form-input" style="font-size: 0.8rem;" accept="video/*">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Thumbnail URL (optional)</label>
                    <input type="url" name="episode_thumb_url" class="form-input" placeholder="https://...">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    Add Episode
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
