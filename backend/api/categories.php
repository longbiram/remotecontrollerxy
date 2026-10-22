<?php
/**
 * REST API: Categories, Genres, Taxonomy, and Dynamic Home Categories with Custom Placing
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!Database::isInstalled()) {
    jsonResponse(['status' => 'error', 'message' => 'Backend not installed.'], 503);
}

try {
    $pdo = Database::getInstance();
    ensureDatabaseTables($pdo);

    // Fetch dynamic home categories ordered by display_order
    $homeCatStmt = $pdo->query("SELECT * FROM home_categories WHERE is_enabled = 1 ORDER BY display_order ASC, id ASC");
    $homeCategories = [];
    while ($r = $homeCatStmt->fetch(PDO::FETCH_ASSOC)) {
        $homeCategories[] = [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'slug' => (string)$r['slug'],
            'subtitle' => (string)($r['subtitle'] ?? ''),
            'contentType' => (string)$r['content_type'],
            'filterGenre' => (string)($r['filter_genre'] ?? ''),
            'sortBy' => (string)$r['sort_by'],
            'displayOrder' => (int)$r['display_order'],
            'isEnabled' => (bool)$r['is_enabled'],
        ];
    }

    // Distinct Live TV categories
    $catStmt = $pdo->query("SELECT DISTINCT channel_category FROM content_items WHERE channel_category != '' AND type = 'liveTv'");
    $channelCategories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

    jsonResponse([
        'status' => 'success',
        'homeCategories' => $homeCategories,
        'contentTypes' => ['bollywood', 'hollywood', 'series', 'liveTv'],
        'channelCategories' => $channelCategories,
        'genres' => ['Action', 'Thriller', 'Drama', 'Comedy', 'Sci-Fi', 'Romance', 'Horror', 'Adventure', 'Animation', 'Crime', 'Biography', 'Sports', 'News', 'HD'],
        'languages' => ['Hindi', 'English', 'Tamil', 'Telugu', 'Malayalam', 'Kannada', 'Hindi Dubbed', 'English Subs'],
        'qualities' => ['4K UHD', '1080p 60fps', '1080p', '720p', '480p'],
    ]);

} catch (Exception $e) {
    jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
}
