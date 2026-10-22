<?php
/**
 * REST API: Get Single Content Details
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

if (!Database::isInstalled()) {
    jsonResponse(['status' => 'error', 'message' => 'Backend not installed.'], 503);
}

// Enforce HMAC signature & secret key validation
enforceApiProtection('/api/detail.php');

$id = trim($_GET['id'] ?? '');
if (empty($id)) {
    jsonResponse(['status' => 'error', 'message' => 'Missing content id parameter.'], 400);
}

try {
    $pdo = Database::getInstance();
    ensureDatabaseTables($pdo);

    // Increment view counter
    $vStmt = $pdo->prepare("UPDATE content_items SET views = views + 1 WHERE id = :id");
    $vStmt->execute(['id' => $id]);

    $stmt = $pdo->prepare("SELECT * FROM content_items WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $r = $stmt->fetch();

    if (!$r) {
        jsonResponse(['status' => 'error', 'message' => 'Item not found.'], 404);
    }

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
        'seasons' => [],
    ];

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

    // Related items
    $relStmt = $pdo->prepare("SELECT id, title, type, poster_url, rating, year FROM content_items WHERE type = :type AND id != :id LIMIT 6");
    $relStmt->execute(['type' => $r['type'], 'id' => $id]);
    $related = $relStmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'data' => $item,
        'related' => $related,
    ]);

} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
