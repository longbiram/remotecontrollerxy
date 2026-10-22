<?php
/**
 * REST API: List Content Items
 * Formatted for Flutter App consumption matching ContentItem model
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

// First-time check
if (!Database::isInstalled()) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Backend is not installed or configured yet.',
        'installed' => false,
    ], 503);
}

// Enforce HMAC signature & secret key validation
enforceApiProtection('/api/content.php');

try {
    $pdo = Database::getInstance();
    ensureDatabaseTables($pdo);

    // Check maintenance mode
    $maintCheck = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
    $maint = $maintCheck->fetch();
    if (($maint['setting_value'] ?? '0') === '1') {
        jsonResponse([
            'status' => 'maintenance',
            'message' => 'Server is currently undergoing scheduled maintenance. Please check back shortly.',
        ], 503);
    }

    $type = $_GET['type'] ?? '';
    $trending = isset($_GET['trending']) ? (int)$_GET['trending'] : null;
    $promoted = isset($_GET['promoted']) ? (int)$_GET['promoted'] : null;
    $category = $_GET['category'] ?? '';
    $search = trim($_GET['search'] ?? ($_GET['q'] ?? ''));

    $sql = "SELECT * FROM content_items WHERE 1=1";
    $params = [];

    if (!empty($type)) {
        $sql .= " AND type = :type";
        $params['type'] = $type;
    }

    if ($trending !== null) {
        $sql .= " AND is_trending = :trending";
        $params['trending'] = $trending;
    }

    if ($promoted !== null) {
        $sql .= " AND is_promoted = :promoted";
        $params['promoted'] = $promoted;
    }

    if (!empty($category)) {
        $sql .= " AND channel_category = :cat";
        $params['cat'] = $category;
    }

    if (!empty($search)) {
        $sql .= " AND (title LIKE :s OR synopsis LIKE :s OR genres LIKE :s)";
        $params['s'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY is_promoted DESC, is_trending DESC, created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $contentList = [];
    foreach ($rows as $r) {
        $item = [
            'id' => $r['id'],
            'title' => $r['title'],
            'type' => $r['type'],
            'posterUrl' => $r['poster_url'],
            'bannerUrl' => $r['banner_url'],
            'rating' => (float)$r['rating'],
            'year' => (string)$r['year'],
            'duration' => (string)$r['duration'],
            'synopsis' => (string)$r['synopsis'],
            'genres' => json_decode($r['genres'] ?? '[]', true) ?: [],
            'languages' => json_decode($r['languages'] ?? '[]', true) ?: [],
            'availableQualities' => json_decode($r['available_qualities'] ?? '[]', true) ?: [],
            'streamUrl' => $r['stream_url'],
            'isPromoted' => (bool)$r['is_promoted'],
            'isTrending' => (bool)$r['is_trending'],
            'isPremium' => !empty($r['is_premium']),
            'channelCategory' => (string)$r['channel_category'],
            'views' => (int)$r['views'],
            'createdAt' => $r['created_at'] ?? '',
            'created_at' => $r['created_at'] ?? '',
            'seasons' => [],
        ];

        // If series, load seasons & episodes
        if ($r['type'] === 'series') {
            $sStmt = $pdo->prepare("SELECT * FROM seasons WHERE content_id = :cid ORDER BY season_number ASC");
            $sStmt->execute(['cid' => $r['id']]);
            $seasons = $sStmt->fetchAll();

            foreach ($seasons as $s) {
                $epStmt = $pdo->prepare("SELECT * FROM episodes WHERE season_id = :sid ORDER BY episode_number ASC");
                $epStmt->execute(['sid' => $s['id']]);
                $episodes = $epStmt->fetchAll();

                $epList = [];
                foreach ($episodes as $ep) {
                    $epList[] = [
                        'id' => $ep['id'],
                        'episodeNumber' => (int)$ep['episode_number'],
                        'title' => $ep['title'],
                        'duration' => $ep['duration'],
                        'description' => (string)$ep['description'],
                        'thumbnail' => $ep['thumbnail'],
                        'streamUrl' => $ep['stream_url'],
                    ];
                }

                $item['seasons'][] = [
                    'seasonNumber' => (int)$s['season_number'],
                    'title' => $s['title'],
                    'episodes' => $epList,
                ];
            }
        }

        $contentList[] = $item;
    }

    jsonResponse([
        'status' => 'success',
        'count' => count($contentList),
        'data' => $contentList,
    ]);

} catch (Exception $e) {
    jsonResponse([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], 500);
}
