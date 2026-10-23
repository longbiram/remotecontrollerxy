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
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }
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
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }
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

// Handle Edit Episode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_episode') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }
    $epId = trim($_POST['episode_id'] ?? '');
    $seasonId = (int)($_POST['season_id'] ?? 0);
    $epNum = (int)($_POST['episode_number'] ?? 1);
    $epTitle = trim($_POST['episode_title'] ?? '');
    $epDuration = trim($_POST['episode_duration'] ?? '45m');
    $epDesc = trim($_POST['episode_desc'] ?? '');

    if (empty($epId)) {
        setFlash('danger', 'Episode ID is required to update episode.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }

    // Verify episode belongs to this series
    $checkStmt = $pdo->prepare("SELECT * FROM episodes WHERE id = :id AND content_id = :cid LIMIT 1");
    $checkStmt->execute(['id' => $epId, 'cid' => $seriesId]);
    $existingEp = $checkStmt->fetch();

    if (!$existingEp) {
        setFlash('danger', 'Episode not found or does not belong to this series.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }

    // Thumbnail: Upload or URL or keep existing
    $thumbUrl = trim($_POST['episode_thumb_url'] ?? '');
    if (!empty($_FILES['episode_thumb_file']['name'])) {
        $up = handleFileUpload($_FILES['episode_thumb_file'], 'thumb');
        if ($up['success']) $thumbUrl = $up['url'];
    }
    if (empty($thumbUrl)) {
        $thumbUrl = !empty($existingEp['thumbnail']) ? $existingEp['thumbnail'] : $series['poster_url'];
    }

    // Video Stream: Upload or URL or keep existing
    $epStreamUrl = trim($_POST['episode_stream_url'] ?? '');
    if (!empty($_FILES['episode_stream_file']['name'])) {
        $up = handleFileUpload($_FILES['episode_stream_file'], 'video');
        if ($up['success']) $epStreamUrl = $up['url'];
    }
    if (empty($epStreamUrl)) {
        $epStreamUrl = $existingEp['stream_url'];
    }

    try {
        $upStmt = $pdo->prepare("UPDATE episodes SET 
            season_id = :sid, 
            episode_number = :num, 
            title = :title, 
            duration = :dur, 
            description = :desc, 
            thumbnail = :thumb, 
            stream_url = :stream 
            WHERE id = :id AND content_id = :cid");

        $upStmt->execute([
            'sid' => $seasonId,
            'num' => $epNum,
            'title' => $epTitle ?: "Episode {$epNum}",
            'dur' => $epDuration,
            'desc' => $epDesc,
            'thumb' => $thumbUrl,
            'stream' => $epStreamUrl,
            'id' => $epId,
            'cid' => $seriesId,
        ]);
        setFlash('success', "Episode #{$epNum} ('" . ($epTitle ?: "Episode {$epNum}") . "') updated successfully.");
    } catch (Exception $e) {
        setFlash('danger', 'Error updating episode: ' . $e->getMessage());
    }
    header('Location: series_manage.php?id=' . urlencode($seriesId));
    exit;
}

// Handle Delete Season
if (isset($_GET['delete_season'])) {
    if (!verifyCsrfToken($_GET['csrf'] ?? '')) {
        setFlash('danger', 'Security verification failed. Invalid CSRF token.');
        header('Location: series_manage.php?id=' . urlencode($seriesId));
        exit;
    }
    $delSeasonId = (int)$_GET['delete_season'];
    try {
        $del = $pdo->prepare("DELETE FROM seasons WHERE id = :id AND content_id = :cid");
        $del->execute(['id' => $delSeasonId, 'cid' => $seriesId]);
        setFlash('success', 'Season deleted.');
    } catch (Exception $e) {
        setFlash('danger', 'Error deleting season: ' . $e->getMessage());
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

// Check for direct edit episode query parameter
$editingEpisode = null;
if (!empty($_GET['edit_episode'])) {
    $editEpId = trim($_GET['edit_episode']);
    $fetchEpStmt = $pdo->prepare("SELECT * FROM episodes WHERE id = :id AND content_id = :cid LIMIT 1");
    $fetchEpStmt->execute(['id' => $editEpId, 'cid' => $seriesId]);
    $editingEpisode = $fetchEpStmt->fetch() ?: null;
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

<div class="series-layout-grid">
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
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h3 class="card-title"><?= htmlspecialchars($s['title']) ?></h3>
                            <span style="font-size: 0.8rem; color: var(--text-dim);"><?= count($s['episodes']) ?> Episode(s) &bull; Season #<?= $s['season_number'] ?></span>
                        </div>
                        <a href="series_manage.php?id=<?= urlencode($seriesId) ?>&delete_season=<?= urlencode($s['id']) ?>&csrf=<?= generateCsrfToken() ?>" class="btn-icon delete" data-confirm="Delete entire <?= htmlspecialchars(addslashes($s['title'])) ?> and all its episodes?" title="Delete Season">
                            <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                        </a>
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
                                        <th style="text-align: right; width: 140px;">Actions</th>
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
                                                <button type="button" class="btn-icon play" onclick="openStreamPreview('<?= htmlspecialchars($ep['stream_url']) ?>', '<?= htmlspecialchars(addslashes($ep['title'])) ?>')" title="Play Preview">
                                                    <svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                                </button>
                                            </td>
                                            <td style="text-align: right;">
                                                <div style="display: inline-flex; gap: 0.4rem; justify-content: flex-end;">
                                                    <button type="button" class="btn-icon edit" onclick='openEditEpisodeModal(<?= json_encode($ep, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' title="Edit Episode">
                                                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                                    </button>
                                                    <a href="series_manage.php?id=<?= urlencode($seriesId) ?>&delete_episode=<?= urlencode($ep['id']) ?>&csrf=<?= generateCsrfToken() ?>" class="btn-icon delete" data-confirm="Delete episode <?= htmlspecialchars(addslashes($ep['title'])) ?>?" title="Delete Episode">
                                                        <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                                    </a>
                                                </div>
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
                <input type="hidden" name="csrf" value="<?= generateCsrfToken() ?>">
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
                <input type="hidden" name="csrf" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="action" value="add_episode">
                
                <div class="form-group">
                    <label class="form-label">Select Season</label>
                    <select name="season_id" class="form-input">
                        <?php foreach ($seasons as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?> (Season #<?= $s['season_number'] ?>)</option>
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
                    <div style="margin-top: 0.35rem;">
                        <input type="file" name="episode_thumb_file" class="form-input" style="font-size: 0.8rem;" accept="image/*">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 0.5rem;">
                    Add Episode
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: EDIT EPISODE (RESPONSIVE) -->
<div class="modal-overlay" id="editEpisodeModal" style="display: none;">
    <div class="modal-content" style="max-width: min(640px, 100%);">
        <div class="modal-header">
            <div style="min-width: 0; padding-right: 0.5rem;">
                <h3 class="card-title" style="font-size: clamp(1.05rem, 2.5vw, 1.25rem); margin-bottom: 2px;">Edit Web Series Episode</h3>
                <span style="font-size: 0.75rem; color: var(--text-dim); display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                    Episode ID: <code id="edit_episode_id_badge" style="color: var(--accent-cyan); font-size: 0.75rem; word-break: break-all;"></code>
                </span>
            </div>
            <button type="button" class="btn-icon" onclick="closeEditEpisodeModal()" aria-label="Close modal">&times;</button>
        </div>
        <form method="POST" action="series_manage.php?id=<?= urlencode($seriesId) ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="edit_episode">
            <input type="hidden" name="episode_id" id="edit_episode_id" value="">

            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group" style="flex: 2; min-width: 140px;">
                        <label class="form-label">Season <span style="color: var(--accent-red);">*</span></label>
                        <select name="season_id" id="edit_season_id" class="form-input" required>
                            <?php foreach ($seasons as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['title']) ?> (Season #<?= $s['season_number'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="flex: 1; min-width: 100px;">
                        <label class="form-label">Episode # <span style="color: var(--accent-red);">*</span></label>
                        <input type="number" name="episode_number" id="edit_episode_number" class="form-input" min="1" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 2; min-width: 160px;">
                        <label class="form-label">Episode Title <span style="color: var(--accent-red);">*</span></label>
                        <input type="text" name="episode_title" id="edit_episode_title" class="form-input" placeholder="e.g. Tetua" required>
                    </div>

                    <div class="form-group" style="flex: 1; min-width: 100px;">
                        <label class="form-label">Duration <span style="color: var(--accent-red);">*</span></label>
                        <input type="text" name="episode_duration" id="edit_episode_duration" class="form-input" placeholder="45m" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Synopsis / Description</label>
                    <textarea name="episode_desc" id="edit_episode_desc" class="form-input" rows="3" placeholder="Episode summary or key plot points..."></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Stream URL (or upload replacement)</label>
                    <input type="url" name="episode_stream_url" id="edit_episode_stream_url" class="form-input" placeholder="https://example.com/ep.mp4 or .m3u8">
                    <div style="margin-top: 0.35rem;">
                        <span style="font-size: 0.75rem; color: var(--text-dim); display: block; margin-bottom: 0.2rem;">Or choose a new video file to upload:</span>
                        <input type="file" name="episode_stream_file" class="form-input" style="font-size: 0.8rem;" accept="video/*">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Thumbnail URL (or upload replacement)</label>
                    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <img id="edit_episode_thumb_preview" src="" alt="Thumbnail preview" style="width: 72px; height: 42px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color); display: none; flex-shrink: 0;">
                        <div style="flex: 1; min-width: 180px;">
                            <input type="url" name="episode_thumb_url" id="edit_episode_thumb_url" class="form-input" placeholder="https://example.com/thumb.jpg" oninput="updateEditThumbPreview(this.value)">
                        </div>
                    </div>
                    <div style="margin-top: 0.35rem;">
                        <span style="font-size: 0.75rem; color: var(--text-dim); display: block; margin-bottom: 0.2rem;">Or choose a new image file to upload:</span>
                        <input type="file" name="episode_thumb_file" class="form-input" style="font-size: 0.8rem;" accept="image/*">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditEpisodeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Episode Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditEpisodeModal(ep) {
    if (!ep) return;
    document.getElementById('edit_episode_id').value = ep.id || '';
    document.getElementById('edit_episode_id_badge').innerText = ep.id || '';
    if (ep.season_id) {
        document.getElementById('edit_season_id').value = ep.season_id;
    }
    document.getElementById('edit_episode_number').value = ep.episode_number || 1;
    document.getElementById('edit_episode_title').value = ep.title || '';
    document.getElementById('edit_episode_duration').value = ep.duration || '45m';
    document.getElementById('edit_episode_desc').value = ep.description || '';
    document.getElementById('edit_episode_stream_url').value = ep.stream_url || '';
    document.getElementById('edit_episode_thumb_url').value = ep.thumbnail || '';
    
    updateEditThumbPreview(ep.thumbnail);

    const modal = document.getElementById('editEpisodeModal');
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function updateEditThumbPreview(url) {
    const preview = document.getElementById('edit_episode_thumb_preview');
    if (!preview) return;
    if (url && (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/'))) {
        preview.src = url;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

function closeEditEpisodeModal() {
    const modal = document.getElementById('editEpisodeModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Close modal when clicking outside or pressing Escape
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('editEpisodeModal');
    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeEditEpisodeModal();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeEditEpisodeModal();
    });

    <?php if (!empty($editingEpisode)): ?>
    openEditEpisodeModal(<?= json_encode($editingEpisode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>);
    <?php endif; ?>
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
