<?php
/**
 * REST API: User Watch Progress (Continue Watching)
 * Allows Flutter client to save, retrieve, and delete watch progress.
 * Provides seamless cross-device synchronization for logged-in users.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_guard.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-App-Key, X-App-Time, X-App-Signature, X-User-Token');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit(0);
}

if (!Database::isInstalled()) {
    jsonResponse([
        'status' => 'error',
        'message' => 'Backend is not configured yet.',
    ], 503);
}

// Verify client HMAC signature if client headers are present
if (!empty($_SERVER['HTTP_X_APP_KEY']) || !empty($_SERVER['HTTP_X_APP_SIGNATURE'])) {
    enforceApiProtection('/api/watch_progress.php');
}

// 1. Authenticate User from Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = $_SERVER['HTTP_X_USER_TOKEN'] ?? '';
if (empty($token) && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token) && !empty($_REQUEST['token'])) {
    $token = trim($_REQUEST['token']);
}

if (empty($token) || $token === 'guest_token' || $token === 'guest_session') {
    jsonResponse([
        'status' => 'error',
        'code' => 'LOGIN_REQUIRED',
        'message' => 'Valid user login required for cross-device watch progress sync.',
    ], 401);
}

try {
    $pdo = Database::getInstance();
    if (!$pdo) {
        throw new Exception("Unable to connect to database");
    }
    ensureDatabaseTables($pdo);

    // Fetch user by token
    $uStmt = $pdo->prepare("SELECT id, name, email, status FROM app_users WHERE token = :token LIMIT 1");
    $uStmt->execute(['token' => $token]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        jsonResponse([
            'status' => 'error',
            'code' => 'INVALID_TOKEN',
            'message' => 'User session expired or invalid. Please sign in again.',
        ], 401);
    }

    if ($user['status'] === 'suspended') {
        jsonResponse([
            'status' => 'error',
            'code' => 'ACCOUNT_SUSPENDED',
            'message' => 'Your account has been suspended.',
        ], 403);
    }

    $userId = (int)$user['id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // ── GET: Return user's continue watching items ───────────────────────────
    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT wp.*, 
                   ci.title as db_title, ci.type as db_type, ci.poster_url as db_poster,
                   ci.banner_url as db_banner, ci.rating as db_rating, ci.year as db_year,
                   ci.duration as db_duration, ci.synopsis as db_synopsis, ci.stream_url as db_stream,
                   ci.is_premium as db_is_premium, ci.channel_category as db_channel_category
            FROM watch_progress wp
            LEFT JOIN content_items ci ON wp.content_id = ci.id
            WHERE wp.user_id = :uid
            ORDER BY wp.updated_at DESC
            LIMIT 25
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $cachedItem = !empty($r['item_json']) ? json_decode($r['item_json'], true) : null;
            $item = $cachedItem ?: [
                'id' => $r['content_id'],
                'title' => $r['db_title'] ?? 'Title',
                'type' => $r['db_type'] ?? 'bollywood',
                'posterUrl' => $r['db_poster'] ?? '',
                'bannerUrl' => $r['db_banner'] ?? '',
                'rating' => (float)($r['db_rating'] ?? 0.0),
                'year' => (string)($r['db_year'] ?? ''),
                'duration' => (string)($r['db_duration'] ?? ''),
                'synopsis' => (string)($r['db_synopsis'] ?? ''),
                'streamUrl' => (string)($r['db_stream'] ?? ''),
                'isPremium' => !empty($r['db_is_premium']),
                'channelCategory' => (string)($r['db_channel_category'] ?? ''),
            ];

            $cachedEpisode = !empty($r['episode_json']) ? json_decode($r['episode_json'], true) : null;

            $results[] = [
                'contentId' => $r['content_id'],
                'episodeId' => $r['episode_id'],
                'positionSeconds' => (int)$r['position_seconds'],
                'durationSeconds' => (int)$r['duration_seconds'],
                'updatedAt' => $r['updated_at'],
                'item' => $item,
                'episode' => $cachedEpisode,
            ];
        }

        jsonResponse([
            'status' => 'success',
            'count' => count($results),
            'data' => $results,
        ]);
    }

    // ── POST: Save or remove watch progress ─────────────────────────────────
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $action = $data['action'] ?? 'save';
        $contentId = trim($data['content_id'] ?? ($data['contentId'] ?? ''));
        $episodeId = !empty($data['episode_id']) ? trim($data['episode_id']) : (!empty($data['episodeId']) ? trim($data['episodeId']) : null);

        if (empty($contentId)) {
            jsonResponse([
                'status' => 'error',
                'message' => 'Missing content_id parameter.',
            ], 422);
        }

        // Delete action
        if ($action === 'delete' || $action === 'remove') {
            if ($episodeId !== null) {
                $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid AND episode_id = :eid");
                $del->execute(['uid' => $userId, 'cid' => $contentId, 'eid' => $episodeId]);
            } else {
                $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid");
                $del->execute(['uid' => $userId, 'cid' => $contentId]);
            }

            jsonResponse([
                'status' => 'success',
                'message' => 'Watch progress removed.',
            ]);
        }

        // Save progress action
        $positionSeconds = (int)($data['position_seconds'] ?? ($data['positionSeconds'] ?? 0));
        $durationSeconds = (int)($data['duration_seconds'] ?? ($data['durationSeconds'] ?? 0));
        $item = $data['item'] ?? null;
        $episode = $data['episode'] ?? null;

        // If >= 95% watched, remove (considered completed)
        if ($durationSeconds > 0 && ($positionSeconds / $durationSeconds) >= 0.95) {
            if ($episodeId !== null) {
                $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid AND episode_id = :eid");
                $del->execute(['uid' => $userId, 'cid' => $contentId, 'eid' => $episodeId]);
            } else {
                $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid");
                $del->execute(['uid' => $userId, 'cid' => $contentId]);
            }

            jsonResponse([
                'status' => 'success',
                'message' => 'Item completed and removed from continue watching.',
                'completed' => true,
            ]);
        }

        $itemJson = !empty($item) ? (is_string($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE)) : null;
        $epJson = !empty($episode) ? (is_string($episode) ? $episode : json_encode($episode, JSON_UNESCAPED_UNICODE)) : null;

        // Upsert into watch_progress
        $ins = $pdo->prepare("
            INSERT INTO watch_progress (user_id, content_id, episode_id, position_seconds, duration_seconds, item_json, episode_json, updated_at)
            VALUES (:uid, :cid, :eid, :pos, :dur, :item_json, :ep_json, NOW())
            ON DUPLICATE KEY UPDATE
                position_seconds = VALUES(position_seconds),
                duration_seconds = VALUES(duration_seconds),
                item_json = COALESCE(VALUES(item_json), item_json),
                episode_json = COALESCE(VALUES(episode_json), episode_json),
                updated_at = NOW()
        ");
        $ins->execute([
            'uid' => $userId,
            'cid' => $contentId,
            'eid' => $episodeId,
            'pos' => $positionSeconds,
            'dur' => $durationSeconds,
            'item_json' => $itemJson,
            'ep_json' => $epJson,
        ]);

        jsonResponse([
            'status' => 'success',
            'message' => 'Watch progress saved successfully.',
        ]);
    }

    // ── DELETE: Remove progress ─────────────────────────────────────────────
    if ($method === 'DELETE') {
        $contentId = trim($_GET['content_id'] ?? ($_GET['contentId'] ?? ''));
        $episodeId = !empty($_GET['episode_id']) ? trim($_GET['episode_id']) : (!empty($_GET['episodeId']) ? trim($_GET['episodeId']) : null);

        if (empty($contentId)) {
            jsonResponse(['status' => 'error', 'message' => 'Missing content_id parameter.'], 422);
        }

        if ($episodeId !== null) {
            $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid AND episode_id = :eid");
            $del->execute(['uid' => $userId, 'cid' => $contentId, 'eid' => $episodeId]);
        } else {
            $del = $pdo->prepare("DELETE FROM watch_progress WHERE user_id = :uid AND content_id = :cid");
            $del->execute(['uid' => $userId, 'cid' => $contentId]);
        }

        jsonResponse([
            'status' => 'success',
            'message' => 'Watch progress removed.',
        ]);
    }

    jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);

} catch (Exception $e) {
    error_log("watch_progress.php error: " . $e->getMessage());
    jsonResponse([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ], 500);
}
