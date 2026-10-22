<?php
/**
 * Admin Panel: App Users Management
 * Displays registered mobile app users with 10-per-page pagination,
 * real-time search, status filtering, account suspension/activation,
 * and detailed user profile & subscription inspector.
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
$currentUser = Auth::user();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$error = '';
$success = '';

// -------------------------------------------------------------
// POST ACTIONS: USER STATUS TOGGLE & DELETE
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle_status') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'active');

        if (!in_array($newStatus, ['active', 'suspended', 'pending'], true)) {
            $error = 'Invalid user status specified.';
        } elseif ($userId <= 0) {
            $error = 'Invalid user ID.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE app_users SET status = :status WHERE id = :id");
                $stmt->execute(['status' => $newStatus, 'id' => $userId]);
                setFlash('success', "User #{$userId} status changed to " . ucfirst($newStatus) . ".");
                header('Location: users.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
                exit;
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_user') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId <= 0) {
            $error = 'Invalid user ID.';
        } else {
            try {
                $delSub = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = :id");
                $delSub->execute(['id' => $userId]);

                $delUser = $pdo->prepare("DELETE FROM app_users WHERE id = :id");
                $delUser->execute(['id' => $userId]);

                setFlash('success', "User #{$userId} and associated subscriptions were permanently removed.");
                header('Location: users.php');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to delete user: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// USER STATS AGGREGATION
// -------------------------------------------------------------
$userStats = [
    'total' => 0,
    'active' => 0,
    'suspended' => 0,
    'vip' => 0,
];

try {
    $statQuery = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cnt,
            SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_cnt
        FROM app_users
    ");
    if ($st = $statQuery->fetch()) {
        $userStats['total'] = (int)$st['total'];
        $userStats['active'] = (int)($st['active_cnt'] ?? 0);
        $userStats['suspended'] = (int)($st['suspended_cnt'] ?? 0);
    }

    $vipQuery = $pdo->query("
        SELECT COUNT(DISTINCT user_id) as vip_cnt 
        FROM user_subscriptions 
        WHERE status = 'active' AND (expires_at IS NULL OR expires_at > NOW())
    ");
    if ($vp = $vipQuery->fetch()) {
        $userStats['vip'] = (int)($vp['vip_cnt'] ?? 0);
    }
} catch (Exception $e) {}

// -------------------------------------------------------------
// PAGINATION & FILTERING (STRICTLY 10 PER PAGE)
// -------------------------------------------------------------
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$planFilter = trim($_GET['plan'] ?? '');

$where = ["1=1"];
$params = [];

if (!empty($search)) {
    $where[] = "(u.name LIKE :search OR u.email LIKE :search OR u.id = :search_exact)";
    $params['search'] = "%{$search}%";
    $params['search_exact'] = is_numeric($search) ? (int)$search : 0;
}

if (!empty($statusFilter) && in_array($statusFilter, ['active', 'suspended', 'pending'], true)) {
    $where[] = "u.status = :status";
    $params['status'] = $statusFilter;
}

if ($planFilter === 'vip') {
    $where[] = "sub.status = 'active' AND (sub.expires_at IS NULL OR sub.expires_at > NOW())";
} elseif ($planFilter === 'free') {
    $where[] = "(sub.id IS NULL OR sub.status != 'active' OR (sub.expires_at IS NOT NULL AND sub.expires_at <= NOW()))";
}

$whereSql = implode(' AND ', $where);

// Count total matching records
$countSql = "
    SELECT COUNT(DISTINCT u.id) as cnt 
    FROM app_users u
    LEFT JOIN user_subscriptions sub ON (sub.user_id = u.id AND sub.status = 'active')
    WHERE {$whereSql}
";
$cntStmt = $pdo->prepare($countSql);
$cntStmt->execute($params);
$totalRecords = (int)($cntStmt->fetch()['cnt'] ?? 0);

$totalPages = max(1, (int)ceil($totalRecords / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// Fetch users for current page with subscription info
$listSql = "
    SELECT 
        u.*,
        sub.id as active_sub_id,
        sub.plan_name as active_plan_name,
        sub.status as active_sub_status,
        sub.expires_at as active_sub_expires_at
    FROM app_users u
    LEFT JOIN user_subscriptions sub ON (sub.user_id = u.id AND sub.status = 'active' AND (sub.expires_at IS NULL OR sub.expires_at > NOW()))
    WHERE {$whereSql}
    ORDER BY u.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$users = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
$pageTitle = 'App Users Management';
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title">App Users</h1>
        <p class="page-subtitle">Manage registered mobile app accounts, monitor VIP subscriptions, and control access permissions.</p>
    </div>
    <div class="topbar-actions">
        <a href="subscriptions.php" class="btn btn-secondary">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
            Subscription Plans
        </a>
        <a href="index.php" class="btn btn-secondary">
            &larr; Dashboard
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <strong>Error:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Users Metric Summary Bar -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
    <div class="stat-card">
        <div>
            <div class="stat-label">Total Users</div>
            <div class="stat-value"><?= number_format($userStats['total']) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Registered Accounts</span>
        </div>
        <div class="stat-icon blue">
            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Active Users</div>
            <div class="stat-value" style="color: #34d399;"><?= number_format($userStats['active']) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Granted App Access</span>
        </div>
        <div class="stat-icon emerald">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">VIP Subscribers</div>
            <div class="stat-value" style="color: #fbbf24;"><?= number_format($userStats['vip']) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Paid Plan Active</span>
        </div>
        <div class="stat-icon purple">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
        </div>
    </div>

    <div class="stat-card">
        <div>
            <div class="stat-label">Suspended</div>
            <div class="stat-value" style="color: #f87171;"><?= number_format($userStats['suspended']) ?></div>
            <span style="font-size: 0.75rem; color: var(--text-dim);">Blocked Accounts</span>
        </div>
        <div class="stat-icon red">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
        </div>
    </div>
</div>

<!-- Filters and Search Toolbar -->
<div class="content-card" style="padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
    <form method="GET" action="users.php" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
        <!-- Search Input with Left Icon -->
        <div style="flex: 1; min-width: 240px;">
            <div class="input-group">
                <svg class="input-icon-left" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="text" name="q" class="form-input has-left-icon" placeholder="Search by name, email, or user ID..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>

        <!-- Status Filter -->
        <div style="width: 170px;">
            <select name="status" class="form-input" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Only</option>
                <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended Only</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending Only</option>
            </select>
        </div>

        <!-- Plan Filter -->
        <div style="width: 170px;">
            <select name="plan" class="form-input" onchange="this.form.submit()">
                <option value="">All Plans</option>
                <option value="vip" <?= $planFilter === 'vip' ? 'selected' : '' ?>>VIP Active Only</option>
                <option value="free" <?= $planFilter === 'free' ? 'selected' : '' ?>>Free / Expired Only</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary btn-sm" style="padding: 0.75rem 1.25rem;">
            Filter
        </button>

        <?php if (!empty($search) || !empty($statusFilter) || !empty($planFilter)): ?>
            <a href="users.php" class="btn btn-secondary btn-sm" style="padding: 0.75rem 1rem;">
                Reset
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Users Table -->
<div class="content-card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Registered App Users</h2>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">
                Showing <strong><?= $totalRecords > 0 ? ($offset + 1) : 0 ?></strong> to <strong><?= min($totalRecords, $offset + $perPage) ?></strong> of <strong><?= number_format($totalRecords) ?></strong> users (10 per page)
            </p>
        </div>
        <div>
            <span style="font-size: 0.82rem; background: rgba(255,255,255,0.06); padding: 0.35rem 0.75rem; border-radius: 8px; color: var(--text-muted); border: 1px solid var(--border-color);">
                Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
            </span>
        </div>
    </div>

    <?php if (empty($users)): ?>
        <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
            <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(255, 255, 255, 0.04); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <svg style="width: 32px; height: 32px; fill: var(--text-dim);" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            </div>
            <h3 style="font-size: 1.15rem; color: #fff; margin-bottom: 0.4rem;">No users found</h3>
            <p style="font-size: 0.9rem; margin-bottom: 1.25rem;">
                <?= (!empty($search) || !empty($statusFilter) || !empty($planFilter)) ? 'Try adjusting your search filters or clearing search criteria.' : 'Users who sign up or log in via the mobile app will automatically appear here.' ?>
            </p>
            <?php if (!empty($search) || !empty($statusFilter) || !empty($planFilter)): ?>
                <a href="users.php" class="btn btn-secondary btn-sm">Clear All Filters</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70px;">ID</th>
                        <th>User</th>
                        <th>Email Address</th>
                        <th>Subscription</th>
                        <th>Status</th>
                        <th>Last Active</th>
                        <th>Registered</th>
                        <th style="text-align: right; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php
                            $initials = '';
                            $nameParts = explode(' ', trim($user['name']));
                            foreach ($nameParts as $part) {
                                if (!empty($part)) {
                                    $initials .= strtoupper(mb_substr($part, 0, 1));
                                    if (strlen($initials) >= 2) break;
                                }
                            }
                            if (empty($initials)) $initials = 'U';

                            // Gradient based on ID
                            $gradients = [
                                'linear-gradient(135deg, #3b82f6, #06b6d4)',
                                'linear-gradient(135deg, #8b5cf6, #ec4899)',
                                'linear-gradient(135deg, #10b981, #059669)',
                                'linear-gradient(135deg, #f59e0b, #d97706)',
                                'linear-gradient(135deg, #e50914, #b20710)',
                            ];
                            $grad = $gradients[$user['id'] % count($gradients)];
                        ?>
                        <tr>
                            <td style="color: var(--text-dim); font-weight: 700; font-family: monospace;">
                                #<?= $user['id'] ?>
                            </td>
                            <td>
                                <div class="user-avatar-cell">
                                    <?php if (!empty($user['avatar']) && filter_var($user['avatar'], FILTER_VALIDATE_URL)): ?>
                                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid var(--border-color);">
                                    <?php else: ?>
                                        <div class="user-avatar-bubble" style="background: <?= $grad ?>;">
                                            <?= htmlspecialchars($initials) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 700; color: #fff; font-size: 0.95rem;">
                                            <?= htmlspecialchars($user['name']) ?>
                                        </div>
                                        <span style="font-size: 0.75rem; color: var(--text-dim);">Member #<?= $user['id'] ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="font-size: 0.9rem; color: #cbd5e1;"><?= htmlspecialchars($user['email']) ?></span>
                            </td>
                            <td>
                                <?php if (!empty($user['active_plan_name'])): ?>
                                    <span class="badge-vip-pill" title="Expires: <?= $user['active_sub_expires_at'] ? htmlspecialchars($user['active_sub_expires_at']) : 'Lifetime' ?>">
                                        <svg style="width: 12px; height: 12px; fill: currentColor;" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
                                        VIP: <?= htmlspecialchars($user['active_plan_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-free-pill">
                                        Free Tier
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['status'] === 'active'): ?>
                                    <span class="badge-status active">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #34d399;"></span>
                                        Active
                                    </span>
                                <?php elseif ($user['status'] === 'suspended'): ?>
                                    <span class="badge-status suspended">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #f87171;"></span>
                                        Suspended
                                    </span>
                                <?php else: ?>
                                    <span class="badge-status pending">
                                        <span style="width: 6px; height: 6px; border-radius: 50%; background: #fbbf24;"></span>
                                        Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--text-dim);">
                                <?= !empty($user['last_login']) ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'Never' ?>
                            </td>
                            <td style="font-size: 0.82rem; color: var(--text-dim);">
                                <?= date('M d, Y', strtotime($user['created_at'])) ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <!-- View Details Button -->
                                    <button type="button" class="btn-icon" title="View User Profile & Details" onclick="openUserDetails(<?= htmlspecialchars(json_encode($user)) ?>)">
                                        <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                    </button>

                                    <!-- Status Toggle Button -->
                                    <form method="POST" action="users.php" style="display: inline;">
                                        <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <?php if ($user['status'] === 'active'): ?>
                                            <input type="hidden" name="status" value="suspended">
                                            <button type="submit" class="btn-icon" style="color: #f87171;" title="Suspend this user" onclick="return confirm('Suspend user <?= htmlspecialchars(addslashes($user['name'])) ?>? They will not be able to log in.')">
                                                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5 11H7v-2h10v2z"/></svg>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="status" value="active">
                                            <button type="submit" class="btn-icon" style="color: #34d399;" title="Activate this user" onclick="return confirm('Activate user <?= htmlspecialchars(addslashes($user['name'])) ?>?')">
                                                <svg viewBox="0 0 24 24"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg>
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- Delete Button -->
                                    <form method="POST" action="users.php" style="display: inline;">
                                        <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <button type="submit" class="btn-icon delete" title="Permanently delete user" onclick="return confirm('Are you sure you want to permanently delete user #<?= $user['id'] ?> (<?= htmlspecialchars(addslashes($user['name'])) ?>)?')">
                                            <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls: Strictly 10 per page -->
        <?php
            function buildUserPageUrl($targetPage) {
                $params = $_GET;
                $params['page'] = $targetPage;
                return 'users.php?' . http_build_query($params);
            }
        ?>
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Showing <strong><?= ($offset + 1) ?></strong> to <strong><?= min($totalRecords, $offset + $perPage) ?></strong> of <strong><?= number_format($totalRecords) ?></strong> users (10 per page)
            </div>

            <div class="pagination-nav">
                <!-- Previous Button -->
                <?php if ($page > 1): ?>
                    <a href="<?= buildUserPageUrl($page - 1) ?>" class="page-btn">
                        &larr; Prev
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">&larr; Prev</span>
                <?php endif; ?>

                <!-- Page Number Links -->
                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);

                    if ($startPage > 1) {
                        echo '<a href="' . buildUserPageUrl(1) . '" class="page-btn">1</a>';
                        if ($startPage > 2) {
                            echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                        }
                    }

                    for ($p = $startPage; $p <= $endPage; $p++) {
                        $activeClass = ($p === $page) ? 'active' : '';
                        echo '<a href="' . buildUserPageUrl($p) . '" class="page-btn ' . $activeClass . '">' . $p . '</a>';
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) {
                            echo '<span style="color: var(--text-dim); padding: 0 4px;">...</span>';
                        }
                        echo '<a href="' . buildUserPageUrl($totalPages) . '" class="page-btn">' . $totalPages . '</a>';
                    }
                ?>

                <!-- Next Button -->
                <?php if ($page < $totalPages): ?>
                    <a href="<?= buildUserPageUrl($page + 1) ?>" class="page-btn">
                        Next &rarr;
                    </a>
                <?php else: ?>
                    <span class="page-btn disabled">Next &rarr;</span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- User Details Modal -->
<div class="modal-overlay" id="userDetailsModal" style="display: none;">
    <div class="modal-content" style="max-width: 580px;">
        <div class="modal-header">
            <h3 class="card-title" style="display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px; fill: var(--accent-red);" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                User Profile Inspector
            </h3>
            <button type="button" class="btn-icon" onclick="closeUserDetails()">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display: flex; gap: 1.25rem; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--border-color);">
                <div id="modalUserAvatarBubble" class="user-avatar-bubble" style="width: 60px; height: 60px; font-size: 1.4rem;">
                    U
                </div>
                <div>
                    <h3 id="modalUserName" style="font-size: 1.25rem; font-weight: 800; color: #fff;">User Name</h3>
                    <p id="modalUserEmail" style="font-size: 0.9rem; color: var(--text-muted); margin-top: 2px;">user@example.com</p>
                    <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem;">
                        <span id="modalUserStatusBadge" class="badge-status active">Active</span>
                        <span id="modalUserPlanBadge" class="badge-vip-pill">VIP Plan</span>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div style="background: rgba(255,255,255,0.02); padding: 0.85rem; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase;">User ID</div>
                    <div id="modalUserId" style="font-weight: 700; color: #fff; font-size: 1rem; margin-top: 3px;">#0</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); padding: 0.85rem; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase;">Registered On</div>
                    <div id="modalUserCreated" style="font-weight: 700; color: #fff; font-size: 0.9rem; margin-top: 3px;">-</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); padding: 0.85rem; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase;">Last Active</div>
                    <div id="modalUserLastLogin" style="font-weight: 700; color: #fff; font-size: 0.9rem; margin-top: 3px;">-</div>
                </div>
                <div style="background: rgba(255,255,255,0.02); padding: 0.85rem; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="font-size: 0.75rem; color: var(--text-dim); text-transform: uppercase;">VIP Expiration</div>
                    <div id="modalUserPlanExpiry" style="font-weight: 700; color: #fbbf24; font-size: 0.9rem; margin-top: 3px;">None</div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">
                    <span>API Session Token</span>
                    <span class="label-sub">Used by mobile app for bearer auth</span>
                </label>
                <div class="input-group">
                    <input type="text" id="modalUserToken" class="form-input" readonly style="font-family: monospace; font-size: 0.82rem; background: rgba(0,0,0,0.4);" value="">
                </div>
            </div>
        </div>
        <div style="padding: 1rem 1.5rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 0.75rem; background: rgba(0,0,0,0.2);">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeUserDetails()">Close</button>
        </div>
    </div>
</div>

<script>
function openUserDetails(user) {
    document.getElementById('modalUserId').textContent = '#' + user.id;
    document.getElementById('modalUserName').textContent = user.name || 'Unnamed';
    document.getElementById('modalUserEmail').textContent = user.email || '';
    document.getElementById('modalUserCreated').textContent = user.created_at || 'N/A';
    document.getElementById('modalUserLastLogin').textContent = user.last_login || 'Never';
    document.getElementById('modalUserToken').value = user.token || 'No active session token';

    // Avatar bubble
    const avatarBubble = document.getElementById('modalUserAvatarBubble');
    let initials = 'U';
    if (user.name) {
        const parts = user.name.trim().split(' ');
        initials = parts.map(p => p[0]).join('').toUpperCase().substring(0, 2);
    }
    avatarBubble.textContent = initials;

    // Status Badge
    const statusBadge = document.getElementById('modalUserStatusBadge');
    statusBadge.className = 'badge-status ' + (user.status || 'pending');
    statusBadge.textContent = (user.status || 'pending').toUpperCase();

    // Plan Badge & Expiry
    const planBadge = document.getElementById('modalUserPlanBadge');
    const planExpiry = document.getElementById('modalUserPlanExpiry');
    if (user.active_plan_name) {
        planBadge.className = 'badge-vip-pill';
        planBadge.textContent = 'VIP: ' + user.active_plan_name;
        planExpiry.textContent = user.active_sub_expires_at || 'Lifetime';
    } else {
        planBadge.className = 'badge-free-pill';
        planBadge.textContent = 'Free Tier';
        planExpiry.textContent = 'No active subscription';
    }

    document.getElementById('userDetailsModal').style.display = 'flex';
}

function closeUserDetails() {
    document.getElementById('userDetailsModal').style.display = 'none';
}

// Close on backdrop click
document.getElementById('userDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUserDetails();
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
