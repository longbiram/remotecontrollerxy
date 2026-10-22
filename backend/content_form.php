<?php
/**
 * Content Item Create / Edit Form
 * Premium Modern UI/UX with Dual Input (Upload vs URL), Drag & Drop,
 * Interactive Chips, Rating Slider, and Live Stream Preview.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();

$isEdit = false;
$id = trim($_GET['id'] ?? '');
$item = [
    'id' => '',
    'title' => '',
    'type' => $_GET['type'] ?? 'bollywood',
    'poster_url' => '',
    'banner_url' => '',
    'rating' => '8.0',
    'year' => date('Y'),
    'duration' => '2h 15m',
    'synopsis' => '',
    'genres' => '["Action", "Thriller"]',
    'languages' => '["Hindi", "English"]',
    'available_qualities' => '["1080p", "720p"]',
    'stream_url' => '',
    'is_promoted' => 0,
    'is_trending' => 0,
    'is_premium' => 0,
    'channel_category' => 'Sports',
];

if (!empty($id)) {
    $stmt = $pdo->prepare("SELECT * FROM content_items WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if ($found) {
        $item = $found;
        $isEdit = true;
    } else {
        setFlash('danger', "Item '{$id}' not found.");
        header('Location: content_list.php');
        exit;
    }
}

// Preset arrays for interactive chips
$allGenres = ['Action', 'Thriller', 'Drama', 'Comedy', 'Sci-Fi', 'Romance', 'Horror', 'Adventure', 'Animation', 'Crime', 'Biography', 'Sports', 'News', 'HD', 'World'];
$allLanguages = ['Hindi', 'English', 'Tamil', 'Telugu', 'Malayalam', 'Kannada', 'Hindi Dubbed', 'English Subs'];
$allQualities = ['4K UHD', '1080p 60fps', '1080p', '720p', '480p', '360p'];

$selectedGenres = json_decode($item['genres'] ?? '[]', true) ?: [];
$selectedLanguages = json_decode($item['languages'] ?? '[]', true) ?: [];
$selectedQualities = json_decode($item['available_qualities'] ?? '[]', true) ?: [];

// Handle Form Submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $type = trim($_POST['type'] ?? 'bollywood');
    $rating = (float)($_POST['rating'] ?? 0.0);
    $year = trim($_POST['year'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $synopsis = trim($_POST['synopsis'] ?? '');
    $channelCategory = trim($_POST['channel_category'] ?? '');
    $isPromoted = !empty($_POST['is_promoted']) ? 1 : 0;
    $isTrending = !empty($_POST['is_trending']) ? 1 : 0;
    $isPremium = !empty($_POST['is_premium']) ? 1 : 0;

    $genres = json_encode(array_values($_POST['genres'] ?? ['Action']));
    $languages = json_encode(array_values($_POST['languages'] ?? ['Hindi']));
    $qualities = json_encode(array_values($_POST['qualities'] ?? ['1080p']));

    // 1. Poster: File Upload OR URL
    $posterUrl = trim($_POST['poster_url'] ?? '');
    if (!empty($_FILES['poster_file']['name'])) {
        $uploadRes = handleFileUpload($_FILES['poster_file'], 'poster');
        if ($uploadRes['success']) {
            $posterUrl = $uploadRes['url'];
        } else {
            $error = 'Poster upload error: ' . $uploadRes['error'];
        }
    }

    // 2. Banner: File Upload OR URL
    $bannerUrl = trim($_POST['banner_url'] ?? '');
    if (!empty($_FILES['banner_file']['name'])) {
        $uploadRes = handleFileUpload($_FILES['banner_file'], 'banner');
        if ($uploadRes['success']) {
            $bannerUrl = $uploadRes['url'];
        } else {
            $error = 'Banner upload error: ' . $uploadRes['error'];
        }
    }

    // 3. Stream: File Upload OR URL
    $streamUrl = trim($_POST['stream_url'] ?? '');
    if (!empty($_FILES['video_file']['name'])) {
        $uploadRes = handleFileUpload($_FILES['video_file'], 'video');
        if ($uploadRes['success']) {
            $streamUrl = $uploadRes['url'];
        } else {
            $error = 'Video upload error: ' . $uploadRes['error'];
        }
    }

    if (empty($title)) {
        $error = 'Title is required.';
    } elseif (empty($posterUrl)) {
        $error = 'Poster image is required (paste an image URL or upload a file).';
    } elseif (empty($bannerUrl)) {
        $bannerUrl = $posterUrl; // Fallback to poster if banner omitted
    }

    if (empty($error)) {
        try {
            if ($isEdit) {
                $sql = "UPDATE content_items SET 
                    title = :title, type = :type, poster_url = :poster, banner_url = :banner, 
                    rating = :rating, year = :year, duration = :duration, synopsis = :synopsis, 
                    genres = :genres, languages = :languages, available_qualities = :qualities, 
                    stream_url = :stream, is_promoted = :promoted, is_trending = :trending, 
                    is_premium = :premium, channel_category = :cat 
                    WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'title' => $title,
                    'type' => $type,
                    'poster' => $posterUrl,
                    'banner' => $bannerUrl,
                    'rating' => $rating,
                    'year' => $year,
                    'duration' => $duration,
                    'synopsis' => $synopsis,
                    'genres' => $genres,
                    'languages' => $languages,
                    'qualities' => $qualities,
                    'stream' => $streamUrl,
                    'promoted' => $isPromoted,
                    'trending' => $isTrending,
                    'premium' => $isPremium,
                    'cat' => $channelCategory,
                    'id' => $id,
                ]);

                setFlash('success', "Content '{$title}' updated successfully.");
                header('Location: content_list.php?type=' . urlencode($type));
                exit;
            } else {
                $newId = trim($_POST['custom_id'] ?? '');
                if (empty($newId)) {
                    $newId = generateContentId($title, $type);
                }

                $sql = "INSERT INTO content_items 
                    (id, title, type, poster_url, banner_url, rating, year, duration, synopsis, genres, languages, available_qualities, stream_url, is_promoted, is_trending, is_premium, channel_category, views)
                    VALUES (:id, :title, :type, :poster, :banner, :rating, :year, :duration, :synopsis, :genres, :languages, :qualities, :stream, :promoted, :trending, :premium, :cat, 0)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id' => $newId,
                    'title' => $title,
                    'type' => $type,
                    'poster' => $posterUrl,
                    'banner' => $bannerUrl,
                    'rating' => $rating,
                    'year' => $year,
                    'duration' => $duration,
                    'synopsis' => $synopsis,
                    'genres' => $genres,
                    'languages' => $languages,
                    'qualities' => $qualities,
                    'stream' => $streamUrl,
                    'promoted' => $isPromoted,
                    'trending' => $isTrending,
                    'premium' => $isPremium,
                    'cat' => $channelCategory,
                ]);

                setFlash('success', "New content '{$title}' published.");

                if ($type === 'series') {
                    header('Location: series_manage.php?id=' . urlencode($newId));
                    exit;
                } else {
                    header('Location: content_list.php?type=' . urlencode($type));
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = $isEdit ? "Edit Content: {$item['title']}" : 'Add New Content';
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="page-subtitle"><?= $isEdit ? 'Modify streaming links, upload higher quality artwork, or adjust taxonomy' : 'Upload or link new titles into your streaming catalog' ?></p>
    </div>
    <div class="topbar-actions">
        <a href="content_list.php" class="btn btn-secondary">
            &larr; Back to Catalog
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <strong>Notice:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<form id="contentItemForm" method="POST" action="content_form.php<?= $isEdit ? '?id=' . urlencode($item['id']) : '' ?>" enctype="multipart/form-data">
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        
        <!-- Main Form Column -->
        <div>
            <!-- 1. Content Type Card Selector -->
            <div class="content-card">
                <label class="form-label" style="margin-bottom: 0.75rem;">Content Classification *</label>
                
                <div class="type-card-grid">
                    <label class="type-card-option">
                        <input type="radio" name="type" value="bollywood" <?= $item['type'] === 'bollywood' ? 'checked' : '' ?>>
                        <div class="type-card-box">
                            <svg viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>
                            <span class="title">Bollywood</span>
                        </div>
                    </label>

                    <label class="type-card-option">
                        <input type="radio" name="type" value="hollywood" <?= $item['type'] === 'hollywood' ? 'checked' : '' ?>>
                        <div class="type-card-box">
                            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM8 15c0-1.66 1.34-3 3-3 .35 0 .69.07 1 .18V6h5v2h-3v7c0 1.66-1.34 3-3 3s-3-1.34-3-3z"/></svg>
                            <span class="title">Hollywood</span>
                        </div>
                    </label>

                    <label class="type-card-option">
                        <input type="radio" name="type" value="series" <?= $item['type'] === 'series' ? 'checked' : '' ?>>
                        <div class="type-card-box">
                            <svg viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                            <span class="title">Web Series</span>
                        </div>
                    </label>

                    <label class="type-card-option">
                        <input type="radio" name="type" value="liveTv" <?= $item['type'] === 'liveTv' ? 'checked' : '' ?>>
                        <div class="type-card-box">
                            <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 1.99-.9 1.99-2L23 5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
                            <span class="title">Live TV</span>
                        </div>
                    </label>
                </div>

                <!-- Web Series Notice Banner -->
                <div id="seriesNoticeBanner" class="alert alert-info" style="display: <?= $item['type'] === 'series' ? 'flex' : 'none' ?>; margin-bottom: 0;">
                    <svg style="width: 20px; height: 20px; fill: currentColor; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M11 17h2v-6h-2v6zm1-15C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zM11 9h2V7h-2v2z"/></svg>
                    <div>
                        <strong>Web Series Management:</strong> After saving this series title, you will be redirected to the <strong>Series Hub</strong> where you can create Seasons and upload or link individual Episodes.
                    </div>
                </div>

                <!-- Live TV Category Dropdown -->
                <div id="channelCategoryGroup" class="form-row" style="display: <?= $item['type'] === 'liveTv' ? 'grid' : 'none' ?>; margin-top: 1rem;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label" for="channel_category">Live TV Broadcast Category</label>
                        <select id="channel_category" name="channel_category" class="form-input">
                            <option value="Sports" <?= $item['channel_category'] === 'Sports' ? 'selected' : '' ?>>Sports & Cricket</option>
                            <option value="News" <?= $item['channel_category'] === 'News' ? 'selected' : '' ?>>24/7 News</option>
                            <option value="Entertainment" <?= $item['channel_category'] === 'Entertainment' ? 'selected' : '' ?>>Movies & Entertainment</option>
                            <option value="Music" <?= $item['channel_category'] === 'Music' ? 'selected' : '' ?>>Music & Concerts</option>
                            <option value="Kids" <?= $item['channel_category'] === 'Kids' ? 'selected' : '' ?>>Kids & Cartoons</option>
                            <option value="Devotional" <?= $item['channel_category'] === 'Devotional' ? 'selected' : '' ?>>Devotional & Spiritual</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 2. Primary Metadata Card -->
            <div class="content-card">
                <h3 class="card-title" style="margin-bottom: 1.25rem;">Title & Release Details</h3>

                <div class="form-group">
                    <label class="form-label" for="title">
                        <span>Title <span class="required-star">*</span></span>
                        <span class="label-sub">Official movie, web series, or channel name</span>
                    </label>
                    <div class="input-group">
                        <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M18 4l2 4h-3l-2-4h-2l2 4h-3l-2-4H8l2 4H7L5 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4h-4z"/></svg>
                        <input type="text" id="title" name="title" class="form-input has-left-icon" style="font-size: 1.05rem; font-weight: 600;" value="<?= htmlspecialchars($item['title']) ?>" placeholder="e.g. Jawan, Dune: Part Two, Mirzapur Season 3" required>
                    </div>
                </div>

                <div class="form-row" style="margin-top: 1rem;">
                    <!-- Year / Status with Icon and Year Presets -->
                    <div class="form-group">
                        <label class="form-label" for="year">
                            <span>Release Year / Status</span>
                            <span class="label-sub">Year or 'LIVE'</span>
                        </label>
                        <div class="input-group">
                            <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2z"/></svg>
                            <input type="text" id="year" name="year" class="form-input has-left-icon" value="<?= htmlspecialchars($item['year']) ?>" placeholder="2025 or LIVE">
                        </div>
                        <div class="preset-tags">
                            <span class="preset-chip" data-target="year" data-val="2025">2025</span>
                            <span class="preset-chip" data-target="year" data-val="2024">2024</span>
                            <span class="preset-chip" data-target="year" data-val="2023">2023</span>
                            <span class="preset-chip" data-target="year" data-val="LIVE">LIVE</span>
                        </div>
                    </div>

                    <!-- Duration with Icon and Quick Presets -->
                    <div class="form-group">
                        <label class="form-label" for="duration">
                            <span>Duration / Seasons</span>
                            <span class="label-sub">Runtime</span>
                        </label>
                        <div class="input-group">
                            <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                            <input type="text" id="duration" name="duration" class="form-input has-left-icon" value="<?= htmlspecialchars($item['duration']) ?>" placeholder="e.g. 2h 45m or 10 Episodes">
                        </div>
                        <div class="preset-tags">
                            <span class="preset-chip" data-target="duration" data-val="2h 15m">2h 15m</span>
                            <span class="preset-chip" data-target="duration" data-val="1h 45m">1h 45m</span>
                            <span class="preset-chip" data-target="duration" data-val="10 Episodes">10 Episodes</span>
                            <span class="preset-chip" data-target="duration" data-val="24/7 Live Stream">24/7 Live</span>
                        </div>
                    </div>
                </div>

                <!-- Rating Slider with Interactive Synchronized Badge -->
                <div class="form-group" style="margin-top: 1.25rem;">
                    <label class="form-label">
                        <span>Rating Score</span>
                        <span class="label-sub">Scale 0.0 to 10.0</span>
                    </label>

                    <div class="rating-slider-box">
                        <input type="range" id="rating_slider" min="0" max="10" step="0.1" value="<?= htmlspecialchars($item['rating']) ?>">
                        <input type="number" step="0.1" min="0" max="10" id="rating" name="rating" class="form-input" style="width: 80px; text-align: center; font-weight: 700; font-size: 1rem; padding: 0.5rem;" value="<?= htmlspecialchars($item['rating']) ?>">
                        <div class="rating-display-pill" id="ratingDisplayValue">
                            <?= htmlspecialchars($item['rating']) ?> ★
                        </div>
                    </div>
                </div>

                <!-- Synopsis with Character Counter -->
                <div class="form-group" style="margin-top: 1.25rem;">
                    <label class="form-label" for="synopsis">
                        <span>Synopsis & Storyline</span>
                        <span id="synopsisCharCount" class="label-sub">0 characters</span>
                    </label>
                    <textarea id="synopsis" name="synopsis" class="form-input" rows="4" placeholder="Compelling overview or storyline to display on the mobile app detail sheet..."><?= htmlspecialchars($item['synopsis']) ?></textarea>
                </div>
            </div>

            <!-- 3. Stream Source Card (Direct URL or Local Video File) -->
            <div class="content-card">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">Streaming Source</h3>
                        <p class="input-hint">Direct CDN stream link (MP4, MKV, HLS .m3u8) or uploaded video file</p>
                    </div>
                    <?php if (!empty($item['stream_url'])): ?>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="openStreamPreview('<?= htmlspecialchars($item['stream_url']) ?>', '<?= htmlspecialchars(addslashes($item['title'])) ?>')">
                            <svg style="width: 14px; height: 14px; fill: currentColor; margin-right: 4px;" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            Current Stream
                        </button>
                    <?php endif; ?>
                </div>

                <div class="media-tab-group">
                    <div class="media-tab-buttons">
                        <button type="button" class="media-tab-btn active" data-target="stream-url-sec">
                            <svg style="width: 14px; height: 14px; fill: currentColor; margin-right: 4px; vertical-align: -2px;" viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                            Direct Stream URL
                        </button>
                        <button type="button" class="media-tab-btn" data-target="stream-upload-sec">
                            <svg style="width: 14px; height: 14px; fill: currentColor; margin-right: 4px; vertical-align: -2px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                            Upload Video File
                        </button>
                    </div>

                    <!-- Direct URL Mode with Inline Test Button -->
                    <div class="media-tab-content stream-url-sec">
                        <div class="stream-input-wrapper">
                            <div class="input-group">
                                <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>
                                <input type="url" id="stream_url" name="stream_url" class="form-input has-left-icon" value="<?= htmlspecialchars($item['stream_url']) ?>" placeholder="https://vjs.zencdn.net/v/oceans.mp4 or https://.../stream.m3u8">
                            </div>
                            <button type="button" id="btnTestStreamInline" class="btn btn-secondary btn-test" title="Test stream playback in player modal">
                                <svg style="width: 15px; height: 15px; fill: currentColor; margin-right: 4px;" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                Test Stream
                            </button>
                        </div>
                        <span class="input-hint">Direct CDN stream link (MP4/MKV video or live HLS .m3u8 playlist).</span>
                    </div>

                    <!-- Video Upload Mode with Dropzone -->
                    <div class="media-tab-content stream-upload-sec" style="display: none;">
                        <div class="dropzone-card">
                            <div class="dropzone-icon">
                                <svg viewBox="0 0 24 24"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
                            </div>
                            <div style="font-weight: 700; color: #f8fafc; font-size: 0.95rem; margin-bottom: 0.25rem;">
                                Click to select or Drag & Drop Video File
                            </div>
                            <div style="font-size: 0.8rem; color: var(--text-dim);">
                                Supports MP4, WebM, MKV &bull; Max recommended size: 250MB
                            </div>
                            <input type="file" id="video_file" name="video_file" accept="video/mp4,video/mkv,video/webm" style="display: none;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. Interactive Taxonomy & Tags Card -->
            <div class="content-card">
                <h3 class="card-title" style="margin-bottom: 1.25rem;">Taxonomy & Tags</h3>

                <!-- Available Qualities Pills -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label class="form-label" style="margin-bottom: 0.6rem;">Available Video Resolutions</label>
                    <div class="chip-group">
                        <?php foreach ($allQualities as $q): ?>
                            <label class="chip-item">
                                <input type="checkbox" name="qualities[]" value="<?= htmlspecialchars($q) ?>" <?= in_array($q, $selectedQualities, true) ? 'checked' : '' ?>>
                                <span class="chip-badge">
                                    <span class="check-icon">&#10003;</span>
                                    <?= htmlspecialchars($q) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Languages Pills with Add Custom Button -->
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                        <label class="form-label" style="margin-bottom: 0;">Audio Languages & Dubs</label>
                        <button type="button" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.6rem;" onclick="addCustomTag('language')">+ Add Language</button>
                    </div>
                    <div class="chip-group" id="languagesChipGroup">
                        <?php foreach ($allLanguages as $lang): ?>
                            <label class="chip-item">
                                <input type="checkbox" name="languages[]" value="<?= htmlspecialchars($lang) ?>" <?= in_array($lang, $selectedLanguages, true) ? 'checked' : '' ?>>
                                <span class="chip-badge">
                                    <span class="check-icon">&#10003;</span>
                                    <?= htmlspecialchars($lang) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Genres Pills with Add Custom Button -->
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.6rem;">
                        <label class="form-label" style="margin-bottom: 0;">Genres</label>
                        <button type="button" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 0.2rem 0.6rem;" onclick="addCustomTag('genre')">+ Add Genre</button>
                    </div>
                    <div class="chip-group" id="genresChipGroup">
                        <?php foreach ($allGenres as $genre): ?>
                            <label class="chip-item">
                                <input type="checkbox" name="genres[]" value="<?= htmlspecialchars($genre) ?>" <?= in_array($genre, $selectedGenres, true) ? 'checked' : '' ?>>
                                <span class="chip-badge">
                                    <span class="check-icon">&#10003;</span>
                                    <?= htmlspecialchars($genre) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar / Media Column -->
        <div>
            <!-- Poster Artwork Card -->
            <div class="content-card">
                <div class="card-header" style="margin-bottom: 0.5rem;">
                    <div>
                        <h3 class="card-title">Poster Artwork *</h3>
                        <p class="input-hint">Vertical 2:3 ratio (e.g. 600x900)</p>
                    </div>
                </div>

                <div class="media-tab-group">
                    <div class="media-tab-buttons">
                        <button type="button" class="media-tab-btn active" data-target="poster-url-sec">Direct URL</button>
                        <button type="button" class="media-tab-btn" data-target="poster-upload-sec">Upload File</button>
                    </div>

                    <div class="media-tab-content poster-url-sec">
                        <div class="input-group">
                            <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            <input type="url" id="poster_url" name="poster_url" class="form-input has-left-icon" value="<?= htmlspecialchars($item['poster_url']) ?>" placeholder="https://images.unsplash.com/...">
                        </div>
                        <span class="input-hint">Direct image URL for vertical thumbnail (JPG, PNG, WebP).</span>
                    </div>

                    <div class="media-tab-content poster-upload-sec" style="display: none;">
                        <div class="dropzone-card" style="padding: 1.25rem 0.75rem;">
                            <div class="dropzone-icon">
                                <svg viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            </div>
                            <div style="font-size: 0.85rem; font-weight: 700; color: #fff;">Click or Drop Poster</div>
                            <div style="font-size: 0.75rem; color: var(--text-dim);">JPG, PNG, WebP</div>
                            <input type="file" id="poster_file" name="poster_file" accept="image/jpeg,image/png,image/webp" style="display: none;">
                        </div>
                    </div>
                </div>

                <!-- Poster Live Preview Box -->
                <div class="preview-box" id="posterPreviewContainer" style="<?= empty($item['poster_url']) ? 'display: none;' : '' ?> margin: 1rem auto 0; width: 140px; aspect-ratio: 2/3; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                    <img id="posterPreviewImg" src="<?= htmlspecialchars($item['poster_url']) ?>" alt="Poster preview" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
            </div>

            <!-- Banner Artwork Card -->
            <div class="content-card">
                <div class="card-header" style="margin-bottom: 0.5rem;">
                    <div>
                        <h3 class="card-title">Banner Artwork</h3>
                        <p class="input-hint">Horizontal 16:9 ratio for home hero carousel</p>
                    </div>
                </div>

                <div class="media-tab-group">
                    <div class="media-tab-buttons">
                        <button type="button" class="media-tab-btn active" data-target="banner-url-sec">Direct URL</button>
                        <button type="button" class="media-tab-btn" data-target="banner-upload-sec">Upload File</button>
                    </div>

                    <div class="media-tab-content banner-url-sec">
                        <div class="input-group">
                            <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            <input type="url" id="banner_url" name="banner_url" class="form-input has-left-icon" value="<?= htmlspecialchars($item['banner_url']) ?>" placeholder="https://images.unsplash.com/...">
                        </div>
                        <span class="input-hint">Direct image URL for 16:9 widescreen featured banner.</span>
                    </div>

                    <div class="media-tab-content banner-upload-sec" style="display: none;">
                        <div class="dropzone-card" style="padding: 1.25rem 0.75rem;">
                            <div class="dropzone-icon">
                                <svg viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            </div>
                            <div style="font-size: 0.85rem; font-weight: 700; color: #fff;">Click or Drop Banner</div>
                            <div style="font-size: 0.75rem; color: var(--text-dim);">JPG, PNG, WebP</div>
                            <input type="file" id="banner_file" name="banner_file" accept="image/jpeg,image/png,image/webp" style="display: none;">
                        </div>
                    </div>
                </div>

                <!-- Banner Live Preview Box -->
                <div class="preview-box" id="bannerPreviewContainer" style="<?= empty($item['banner_url']) ? 'display: none;' : '' ?> margin: 1rem auto 0; width: 100%; aspect-ratio: 16/9; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
                    <img id="bannerPreviewImg" src="<?= htmlspecialchars($item['banner_url']) ?>" alt="Banner preview" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
            </div>

            <!-- Spotlight & Visibility Toggles Card -->
            <div class="content-card">
                <h3 class="card-title" style="margin-bottom: 1rem;">Spotlight & Featuring</h3>

                <label class="checkbox-card" style="margin-top: 0; margin-bottom: 0.75rem;">
                    <input type="checkbox" name="is_promoted" value="1" <?= $item['is_promoted'] ? 'checked' : '' ?>>
                    <div class="label-content">
                        <strong>Featured / Hero Banner</strong>
                        <p>Spotlights this item in the top carousel with prominent play action</p>
                    </div>
                </label>

                <label class="checkbox-card">
                    <input type="checkbox" name="is_trending" value="1" <?= $item['is_trending'] ? 'checked' : '' ?>>
                    <div class="label-content">
                        <strong>Trending Now</strong>
                        <p>Places item into the high-priority Trending carousel on home</p>
                    </div>
                </label>

                <label class="checkbox-card" style="margin-top: 0.75rem; border: 1px solid rgba(251, 191, 36, 0.35); background: rgba(251, 191, 36, 0.05);">
                    <input type="checkbox" name="is_premium" value="1" <?= !empty($item['is_premium']) ? 'checked' : '' ?>>
                    <div class="label-content">
                        <strong style="color: #fbbf24; display: flex; align-items: center; gap: 6px;">
                            <svg style="width: 15px; height: 15px; fill: #fbbf24;" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                            VIP Premium Content
                        </strong>
                        <p>Requires active paid subscription to stream. Shows glowing PREMIUM badge in apps.</p>
                    </div>
                </label>
            </div>

            <!-- Sticky Bottom Action Dock -->
            <div class="form-sticky-dock">
                <a href="content_list.php" class="btn btn-secondary btn-sm">Discard</a>
                <button type="submit" id="submitContentBtn" class="btn btn-primary" style="padding: 0.85rem 1.6rem; font-size: 0.95rem;">
                    <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                    <?= $isEdit ? 'Save Changes' : 'Publish Content' ?>
                </button>
            </div>
        </div>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
