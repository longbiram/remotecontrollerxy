<?php
/**
 * REST API: User Watch History
 * Allows Flutter client to fetch, record, and clear watch history from backend.
 * Provides cross-device synchronization for logged-in users.
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
    enforceApiProtection('/api/history.php');
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
        'message' => 'Valid user login required for history sync.',
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

    // ── GET: Return user's watch history ─────────────────────────────────────
    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT uh.*, 
                   ci.title as db_title, ci.type as db_type, ci.poster_url as db_poster,
                   ci.banner_url as db_banner, ci.rating as db_rating, ci.year as db_year,
                   ci.duration as db_duration, ci.synopsis as db_synopsis, ci.stream_url as db_stream,
                   ci.is_premium as db_is_premium, ci.channel_category as db_channel_category,
                   ci.genres as db_genres, ci.languages as db_languages,
                   ci.is_trending as db_is_trending, ci.is_promoted as db_is_promoted
            FROM user_history uh
            LEFT JOIN content_items ci ON uh.content_id = ci.id
            WHERE uh.user_id = :uid
            ORDER BY uh.watched_at DESC
            LIMIT 50
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $item = null;
            if (!empty($r['db_title'])) {
                $genres = json_decode($r['db_genres'] ?? '[]', true) ?: [];
                $languages = json_decode($r['db_languages'] ?? '[]', true) ?: [];
                $cast = [];

                $item = [
                    'id' => $r['content_id'],
                    'title' => $r['db_title'],
                    'type' => $r['db_type'] ?? 'movies',
                    'posterUrl' => $r['db_poster'] ?? '',
                    'poster_url' => $r['db_poster'] ?? '',
                    'bannerUrl' => $r['db_banner'] ?? '',
                    'banner_url' => $r['db_banner'] ?? '',
                    'rating' => (float)($r['db_rating'] ?? 0.0),
                    'year' => (int)($r['db_year'] ?? 2024),
                    'duration' => $r['db_duration'] ?? '',
                    'genres' => $genres,
                    'synopsis' => $r['db_synopsis'] ?? '',
                    'languages' => $languages,
                    'cast' => $cast,
                    'isTrending' => (bool)($r['db_is_trending'] ?? false),
                    'is_trending' => (bool)($r['db_is_trending'] ?? false),
                    'isPromoted' => (bool)($r['db_is_promoted'] ?? false),
                    'is_promoted' => (bool)($r['db_is_promoted'] ?? false),
                    'isPremium' => (bool)($r['db_is_premium'] ?? false),
                    'is_premium' => (bool)($r['db_is_premium'] ?? false),
                    'streamUrl' => $r['db_stream'] ?? '',
                    'stream_url' => $r['db_stream'] ?? '',
                    'channelCategory' => $r['db_channel_category'] ?? null,
                    'channel_category' => $r['db_channel_category'] ?? null,
                ];
            } elseif (!empty($r['item_json'])) {
                $decoded = json_decode($r['item_json'], true);
                if (is_array($decoded)) {
                    $item = $decoded;
                }
            }

            if ($item) {
                $results[] = [
                    'content_id' => $r['content_id'],
                    'item' => $item,
                    'watched_at' => $r['watched_at'],
                ];
            }
        }

        jsonResponse([
            'status' => 'success',
            'count' => count($results),
            'history' => $results,
        ]);
    }

    // ── POST: Record item in watch history ──────────────────────────────────
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $contentId = trim($body['content_id'] ?? ($body['id'] ?? ''));
        if (empty($contentId)) {
            jsonResponse([
                'status' => 'error',
                'message' => 'Missing content_id parameter',
            ], 400);
        }

        $itemJson = null;
        if (!empty($body['item']) && is_array($body['item'])) {
            $itemJson = json_encode($body['item']);
        } elseif (!empty($body['item_json']) && is_string($body['item_json'])) {
            $itemJson = $body['item_json'];
        }

        $ins = $pdo->prepare("
            INSERT INTO user_history (user_id, content_id, item_json, watched_at)
            VALUES (:uid, :cid, :json, NOW())
            ON DUPLICATE KEY UPDATE
                item_json = COALESCE(VALUES(item_json), item_json),
                watched_at = NOW()
        ");
        $ins->execute([
            'uid' => $userId,
            'cid' => $contentId,
            'json' => $itemJson,
        ]);

        jsonResponse([
            'status' => 'success',
            'message' => 'Watch history recorded successfully',
            'content_id' => $contentId,
        ]);
    }

    // ── DELETE: Clear watch history ─────────────────────────────────────────
    if ($method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        $clearAll = !empty($_GET['all']) || !empty($body['all']);
        if ($clearAll) {
            $del = $pdo->prepare("DELETE FROM user_history WHERE user_id = :uid");
            $del->execute(['uid' => $userId]);

            jsonResponse([
                'status' => 'success',
                'message' => 'Watch history cleared successfully',
            ]);
        }

        $contentId = trim($body['content_id'] ?? ($_GET['content_id'] ?? ''));
        if (empty($contentId)) {
            jsonResponse([
                'status' => 'error',
                'message' => 'Missing content_id to delete',
            ], 400);
        }

        $del = $pdo->prepare("DELETE FROM user_history WHERE user_id = :uid AND content_id = :cid");
        $del->execute([
            'uid' => $userId,
            'cid' => $contentId,
        ]);

        jsonResponse([
            'status' => 'success',
            'message' => 'History item removed successfully',
            'content_id' => $contentId,
        ]);
    }

    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);

} catch (Exception $e) {
    error_log("history.php error: " . $e->getMessage());
    jsonResponse([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ], 500);
}
