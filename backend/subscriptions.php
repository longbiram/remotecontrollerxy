<?php
/**
 * Admin Panel: Subscription Plans & User Subscriptions Lifecycle Manager
 * Allows admins to configure plans, view user requests, send payment links,
 * verify submitted transaction IDs, and activate/expire plans.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();
ensureDatabaseTables($pdo);
$currentUser = Auth::user();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$activeTab = $_GET['tab'] ?? 'requests';
$statusFilter = $_GET['status'] ?? '';
$error = '';
$success = '';

// Auto-expire past active subscriptions
try {
    $pdo->query("UPDATE user_subscriptions SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()");
} catch (Exception $e) {}

// -------------------------------------------------------------
// POST ACTIONS: SUBSCRIPTION REQUESTS LIFECYCLE
// -------------------------------------------------------------

// 1. ADMIN SENDS PAYMENT LINK TO USER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'send_payment_link') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $paymentLink = trim($_POST['payment_link'] ?? '');
        $paymentNotes = trim($_POST['payment_notes'] ?? '');

        if ($subId <= 0 || empty($paymentLink)) {
            $error = 'Subscription ID and Payment Link URL are required.';
        } else {
            try {
                $up = $pdo->prepare("
                    UPDATE user_subscriptions 
                    SET payment_link = :link, payment_notes = :notes, status = 'payment_link_sent' 
                    WHERE id = :id
                ");
                $up->execute([
                    'link' => $paymentLink,
                    'notes' => $paymentNotes,
                    'id' => $subId,
                ]);

                setFlash('success', "Payment link successfully dispatched to user for Subscription #$subId.");
                header('Location: subscriptions.php?tab=requests&status=payment_link_sent');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to send payment link: ' . $e->getMessage();
            }
        }
    }
}

// 2. ADMIN APPROVES & ACTIVATES PLAN (Based on Plan Duration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'approve_activate') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $subId = (int)($_POST['subscription_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("SELECT * FROM user_subscriptions WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $subId]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sub) {
                $error = "Subscription #$subId not found.";
            } else {
                $days = (int)($sub['duration_days'] ?? 30);
                if ($days <= 0) $days = 30;

                $up = $pdo->prepare("
                    UPDATE user_subscriptions 
                    SET status = 'active', activated_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL :days DAY)
                    WHERE id = :id
                ");
                $up->execute([
                    'days' => $days,
                    'id' => $subId,
                ]);

                setFlash('success', "Subscription #$subId approved and activated for $days days!");
                header('Location: subscriptions.php?tab=requests&status=active');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Failed to activate subscription: ' . $e->getMessage();
        }
    }
}

// 3. ADMIN REJECTS / CANCELS SUBSCRIPTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reject_subscription') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $subId = (int)($_POST['subscription_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Payment could not be verified.');

        try {
            $up = $pdo->prepare("UPDATE user_subscriptions SET status = 'rejected', payment_notes = :notes WHERE id = :id");
            $up->execute(['notes' => $reason, 'id' => $subId]);

            setFlash('success', "Subscription #$subId has been rejected.");
            header('Location: subscriptions.php?tab=requests');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to reject subscription: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// POST ACTIONS: SUBSCRIPTION PLANS MANAGEMENT
// -------------------------------------------------------------

// 4. CREATE OR EDIT PLAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'create_plan' || $action === 'edit_plan')) {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $currency = strtoupper(trim($_POST['currency'] ?? 'INR'));
        $durationDays = (int)($_POST['duration_days'] ?? 30);
        $description = trim($_POST['description'] ?? '');
        $rawFeatures = trim($_POST['features'] ?? '');
        $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $featuresArr = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "", $rawFeatures)))));
        $featuresJson = json_encode($featuresArr);

        if (empty($name) || $price <= 0 || $durationDays <= 0) {
            $error = 'Plan name, valid price, and duration days (> 0) are required.';
        } else {
            try {
                if ($action === 'edit_plan' && $planId > 0) {
                    $up = $pdo->prepare("
                        UPDATE subscription_plans 
                        SET name = :name, price = :price, currency = :curr, duration_days = :dur, 
                            description = :desc, features = :feat, is_featured = :feat_flag, status = :st 
                        WHERE id = :id
                    ");
                    $up->execute([
                        'name' => $name,
                        'price' => $price,
                        'curr' => $currency,
                        'dur' => $durationDays,
                        'desc' => $description,
                        'feat' => $featuresJson,
                        'feat_flag' => $isFeatured,
                        'st' => $status,
                        'id' => $planId,
                    ]);
                    setFlash('success', "Plan '$name' updated successfully.");
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO subscription_plans 
                        (name, price, currency, duration_days, description, features, is_featured, status) 
                        VALUES (:name, :price, :curr, :dur, :desc, :feat, :feat_flag, :st)
                    ");
                    $ins->execute([
                        'name' => $name,
                        'price' => $price,
                        'curr' => $currency,
                        'dur' => $durationDays,
                        'desc' => $description,
                        'feat' => $featuresJson,
                        'feat_flag' => $isFeatured,
                        'st' => $status,
                    ]);
                    setFlash('success', "New subscription plan '$name' created!");
                }
                header('Location: subscriptions.php?tab=plans');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to save plan: ' . $e->getMessage();
            }
        }
    }
}

// 5. DELETE PLAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_plan') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $delId = (int)($_POST['plan_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM subscription_plans WHERE id = :id")->execute(['id' => $delId]);
            setFlash('success', "Plan deleted.");
            header('Location: subscriptions.php?tab=plans');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to delete plan: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// FETCH DATA FOR DASHBOARD
// -------------------------------------------------------------

// A. Subscription Plans
$plans = [];
$pStmt = $pdo->query("SELECT * FROM subscription_plans ORDER BY price ASC");
$plans = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// B. User Subscriptions List with User details
$subSql = "
    SELECT s.*, u.name as user_name, u.email as user_email 
    FROM user_subscriptions s 
    LEFT JOIN app_users u ON s.user_id = u.id 
    WHERE 1=1
";
$params = [];
if (!empty($statusFilter)) {
    $subSql .= " AND s.status = :status";
    $params['status'] = $statusFilter;
}
$subSql .= " ORDER BY s.id DESC";

$sStmt = $pdo->prepare($subSql);
$sStmt->execute($params);
$subscriptions = $sStmt->fetchAll(PDO::FETCH_ASSOC);

// Counts by status
$counts = [
    'all' => 0,
    'pending_approval' => 0,
    'payment_link_sent' => 0,
    'payment_submitted' => 0,
    'active' => 0,
    'expired' => 0,
    'cancelled' => 0,
];
$cStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM user_subscriptions GROUP BY status");
while ($r = $cStmt->fetch(PDO::FETCH_ASSOC)) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int)$r['cnt'];
    }
    $counts['all'] += (int)$r['cnt'];
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Subscriptions & VIP Plans';
require __DIR__ . '/includes/header.php';
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Subscriptions & VIP Plans</h1>
        <p class="page-subtitle">Configure subscription tiers, send payment links, verify transactions, and activate memberships</p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <button onclick="switchTab('create_plan_modal')" class="btn btn-primary">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add New Plan
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <svg style="width: 18px; height: 18px; fill: #ef4444; margin-right: 8px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<!-- Quick Stat Counters -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem;">
    <div class="content-card" style="padding: 1.25rem; border-left: 4px solid #f59e0b;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Waiting Payment Link</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #f59e0b; font-family: 'Outfit';"><?= $counts['pending_approval'] ?></div>
        <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.35rem;">User requested &bull; Send payment link</div>
    </div>
    <div class="content-card" style="padding: 1.25rem; border-left: 4px solid #38bdf8;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Verify Transaction ID</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #38bdf8; font-family: 'Outfit';"><?= $counts['payment_submitted'] ?></div>
        <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.35rem;">Payment done &bull; Ready to activate</div>
    </div>
    <div class="content-card" style="padding: 1.25rem; border-left: 4px solid #10b981;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Active Subscribers</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #10b981; font-family: 'Outfit';"><?= $counts['active'] ?></div>
        <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.35rem;">Unrestricted VIP access</div>
    </div>
    <div class="content-card" style="padding: 1.25rem; border-left: 4px solid #a855f7;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Configured Plans</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= count($plans) ?></div>
        <div style="font-size: 0.75rem; color: #a855f7; margin-top: 0.35rem;">Tiers visible in mobile app</div>
    </div>
</div>

<!-- Tabs Navigation -->
<div style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem;">
    <button onclick="switchTab('requests')" class="tab-btn <?= $activeTab === 'requests' ? 'active' : '' ?>" id="tab-nav-requests">
        User Subscriptions (<?= count($subscriptions) ?>)
    </button>
    <button onclick="switchTab('plans')" class="tab-btn <?= $activeTab === 'plans' ? 'active' : '' ?>" id="tab-nav-plans">
        Subscription Plans (<?= count($plans) ?>)
    </button>
</div>

<!-- TAB 1: USER SUBSCRIPTION REQUESTS & LIFECYCLE -->
<div id="tab-pane-requests" class="tab-pane" style="display: <?= $activeTab === 'requests' ? 'block' : 'none' ?>;">
    <!-- Status Filter Pills -->
    <div style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem; flex-wrap: wrap;">
        <a href="subscriptions.php?tab=requests" class="badge <?= empty($statusFilter) ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem;">
            All (<?= $counts['all'] ?>)
        </a>
        <a href="subscriptions.php?tab=requests&status=pending_approval" class="badge <?= $statusFilter === 'pending_approval' ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem; color: #f59e0b;">
            ⏳ Awaiting Payment Link (<?= $counts['pending_approval'] ?>)
        </a>
        <a href="subscriptions.php?tab=requests&status=payment_link_sent" class="badge <?= $statusFilter === 'payment_link_sent' ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem;">
            🔗 Link Sent (<?= $counts['payment_link_sent'] ?>)
        </a>
        <a href="subscriptions.php?tab=requests&status=payment_submitted" class="badge <?= $statusFilter === 'payment_submitted' ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem; color: #38bdf8;">
            💳 TXN Submitted - Ready (<?= $counts['payment_submitted'] ?>)
        </a>
        <a href="subscriptions.php?tab=requests&status=active" class="badge <?= $statusFilter === 'active' ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem; color: #10b981;">
            ✓ Active VIP (<?= $counts['active'] ?>)
        </a>
        <a href="subscriptions.php?tab=requests&status=expired" class="badge <?= $statusFilter === 'expired' ? 'badge-primary' : 'badge-secondary' ?>" style="text-decoration: none; padding: 6px 12px; font-size: 0.8rem;">
            Expired (<?= $counts['expired'] ?>)
        </a>
    </div>

    <div class="content-card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Req #</th>
                        <th>User</th>
                        <th>Plan & Amount</th>
                        <th>Status</th>
                        <th>Transaction ID / Link</th>
                        <th>Duration / Expiry</th>
                        <th style="text-align: right;">Admin Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscriptions)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-dim);">
                                No subscription requests found in this category.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscriptions as $s): ?>
                            <tr>
                                <td>
                                    <strong style="color: #fff; font-family: monospace;">#<?= $s['id'] ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-dim);"><?= date('M d, H:i', strtotime($s['created_at'])) ?></div>
                                </td>
                                <td>
                                    <strong style="color: #fff;"><?= htmlspecialchars($s['user_name'] ?? 'User #' . $s['user_id']) ?></strong>
                                    <div style="font-size: 0.78rem; color: var(--text-muted);"><?= htmlspecialchars($s['user_email'] ?? '') ?></div>
                                </td>
                                <td>
                                    <strong style="color: #38bdf8;"><?= htmlspecialchars($s['plan_name']) ?></strong>
                                    <div style="font-size: 0.8rem; color: #10b981; font-weight: 700;">
                                        <?= $s['currency'] ?> <?= number_format($s['amount'], 2) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $st = $s['status'];
                                    if ($st === 'pending_approval') {
                                        echo '<span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.4);">Awaiting Link</span>';
                                    } elseif ($st === 'payment_link_sent') {
                                        echo '<span class="badge" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.4);">Link Sent</span>';
                                    } elseif ($st === 'payment_submitted') {
                                        echo '<span class="badge" style="background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.4);">TXN Submitted</span>';
                                    } elseif ($st === 'active') {
                                        echo '<span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4);">Active VIP</span>';
                                    } elseif ($st === 'expired') {
                                        echo '<span class="badge" style="background: rgba(100, 116, 139, 0.2); color: #94a3b8;">Expired</span>';
                                    } elseif ($st === 'cancelled') {
                                        echo '<span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #f87171;">Cancelled</span>';
                                    } else {
                                        echo '<span class="badge badge-secondary">' . htmlspecialchars($st) . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($s['transaction_id'])): ?>
                                        <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); padding: 4px 8px; border-radius: 6px; font-family: monospace; font-size: 0.8rem; color: #34d399; display: inline-block;">
                                            TXN: <?= htmlspecialchars($s['transaction_id']) ?>
                                        </div>
                                    <?php elseif (!empty($s['payment_link'])): ?>
                                        <div style="font-size: 0.78rem; color: #38bdf8; word-break: break-all; max-width: 180px;">
                                            <a href="<?= htmlspecialchars($s['payment_link']) ?>" target="_blank" style="color: #38bdf8;">Open Link &nearr;</a>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-dim); font-size: 0.8rem;">None yet</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; color: #fff; font-weight: 600;"><?= $s['duration_days'] ?> Days</div>
                                    <?php if (!empty($s['expires_at'])): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            Exp: <?= date('M d, Y', strtotime($s['expires_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px; align-items: center;">
                                        <!-- Action: Send Payment Link -->
                                        <?php if ($s['status'] === 'pending_approval' || $s['status'] === 'payment_link_sent'): ?>
                                            <button type="button" onclick="openSendPaymentModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['user_name'] ?? 'User')) ?>', '<?= htmlspecialchars(addslashes($s['plan_name'])) ?>', <?= $s['amount'] ?>, '<?= htmlspecialchars(addslashes($s['payment_link'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($s['payment_notes'] ?? '')) ?>')" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 4px 8px;">
                                                <?= !empty($s['payment_link']) ? 'Edit Link' : 'Send Link' ?>
                                            </button>
                                        <?php endif; ?>

                                        <!-- Action: Approve & Activate -->
                                        <?php if ($s['status'] === 'payment_submitted' || $s['status'] === 'payment_link_sent' || $s['status'] === 'pending_approval'): ?>
                                            <form method="POST" action="subscriptions.php" style="display: inline;" onsubmit="return confirm('Activate VIP access for <?= htmlspecialchars($s['user_name'] ?? 'User') ?> (<?= $s['duration_days'] ?> days)?');">
                                                <input type="hidden" name="action" value="approve_activate">
                                                <input type="hidden" name="subscription_id" value="<?= $s['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                                <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.75rem; padding: 4px 10px; background: #10b981; border-color: #10b981;">
                                                    Approve &bull; Activate
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Action: Reject -->
                                        <?php if ($s['status'] !== 'active' && $s['status'] !== 'cancelled' && $s['status'] !== 'rejected'): ?>
                                            <form method="POST" action="subscriptions.php" style="display: inline;" onsubmit="return confirm('Reject this subscription request?');">
                                                <input type="hidden" name="action" value="reject_subscription">
                                                <input type="hidden" name="subscription_id" value="<?= $s['id'] ?>">
                                                <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="font-size: 0.75rem; padding: 4px 8px;">
                                                    Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- TAB 2: SUBSCRIPTION PLANS MANAGEMENT -->
<div id="tab-pane-plans" class="tab-pane" style="display: <?= $activeTab === 'plans' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem;">
        <?php foreach ($plans as $p): ?>
            <?php 
            $features = json_decode($p['features'] ?? '[]', true) ?: [];
            ?>
            <div class="content-card" style="border: 1px solid <?= $p['is_featured'] ? 'var(--accent-red)' : 'var(--border-color)' ?>; position: relative;">
                <?php if ($p['is_featured']): ?>
                    <span class="badge badge-primary" style="position: absolute; top: 12px; right: 12px; font-size: 0.7rem;">
                        ★ POPULAR
                    </span>
                <?php endif; ?>

                <div style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 0.35rem; font-family: 'Outfit';">
                    <?= htmlspecialchars($p['name']) ?>
                </div>

                <div style="display: flex; align-items: baseline; gap: 4px; margin-bottom: 0.75rem;">
                    <span style="font-size: 1.75rem; font-weight: 900; color: #fbbf24; font-family: 'Outfit';">
                        <?= $p['currency'] ?> <?= number_format($p['price'], 0) ?>
                    </span>
                    <span style="color: var(--text-muted); font-size: 0.85rem;">/ <?= $p['duration_days'] ?> Days</span>
                </div>

                <p style="color: var(--text-dim); font-size: 0.85rem; margin-bottom: 1rem; min-height: 38px;">
                    <?= htmlspecialchars($p['description'] ?? '') ?>
                </p>

                <div style="border-top: 1px solid var(--border-color); padding-top: 0.75rem; margin-bottom: 1.25rem;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 0.5rem;">Included Features:</div>
                    <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.85rem; color: #cbd5e1;">
                        <?php foreach ($features as $f): ?>
                            <li style="display: flex; align-items: center; gap: 6px;">
                                <span style="color: #10b981;">&#10003;</span> <?= htmlspecialchars($f) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div style="display: flex; gap: 0.5rem; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                    <button type="button" onclick='openEditPlanModal(<?= json_encode($p) ?>)' class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                        Edit Plan
                    </button>
                    <form method="POST" action="subscriptions.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete plan <?= htmlspecialchars($p['name']) ?>?');">
                        <input type="hidden" name="action" value="delete_plan">
                        <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                        <button type="submit" class="btn btn-danger btn-sm" style="font-size: 0.75rem;">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- MODAL: SEND PAYMENT LINK -->
<div id="paymentModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 999; backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1.5rem;">
    <div class="content-card" style="max-width: 520px; width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,0.8); border: 1px solid var(--border-light);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 class="card-title" style="margin: 0;">Send Payment Link to User</h3>
            <button type="button" onclick="closeSendPaymentModal()" class="btn-icon" style="color: var(--text-dim);">&times;</button>
        </div>

        <form method="POST" action="subscriptions.php">
            <input type="hidden" name="action" value="send_payment_link">
            <input type="hidden" name="subscription_id" id="modalSubId" value="">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">

            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 10px; padding: 0.75rem 1rem; margin-bottom: 1.25rem;">
                <div style="font-size: 0.85rem; color: var(--text-muted);">Recipient: <strong id="modalUserName" style="color: #fff;">-</strong></div>
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.2rem;">Plan: <strong id="modalPlanName" style="color: #38bdf8;">-</strong> &bull; Amount: <strong id="modalAmount" style="color: #10b981;">-</strong></div>
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label">Payment Gateway / UPI URL <span style="color: var(--accent-red);">*</span></label>
                <input type="url" name="payment_link" id="modalPaymentLink" class="form-input" placeholder="https://rzp.io/l/vip_pass or upi://pay?pa=..." required>
                <span style="font-size: 0.75rem; color: var(--text-dim);">The user will see this link and click to complete payment.</span>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label">Payment Instructions / Notes (Optional)</label>
                <textarea name="payment_notes" id="modalPaymentNotes" class="form-input" rows="3" placeholder="e.g. Scan QR code or pay via UPI. Once completed, paste your 12-digit UTR/Transaction ID in the app."></textarea>
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="closeSendPaymentModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Dispatch Link to User &rarr;</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ADD / EDIT PLAN -->
<div id="planModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 999; backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 1.5rem;">
    <div class="content-card" style="max-width: 580px; width: 100%; box-shadow: 0 20px 50px rgba(0,0,0,0.8); border: 1px solid var(--border-light); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
            <h3 class="card-title" id="planModalTitle" style="margin: 0;">Add Subscription Plan</h3>
            <button type="button" onclick="closePlanModal()" class="btn-icon" style="color: var(--text-dim);">&times;</button>
        </div>

        <form method="POST" action="subscriptions.php">
            <input type="hidden" name="action" id="planAction" value="create_plan">
            <input type="hidden" name="plan_id" id="editPlanId" value="">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">

            <div class="form-row" style="margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label class="form-label">Plan Name <span style="color: var(--accent-red);">*</span></label>
                    <input type="text" name="name" id="planName" class="form-input" placeholder="e.g. Monthly VIP Pass" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Duration (Days) <span style="color: var(--accent-red);">*</span></label>
                    <input type="number" name="duration_days" id="planDuration" class="form-input" value="30" min="1" required>
                </div>
            </div>

            <div class="form-row" style="margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label class="form-label">Price <span style="color: var(--accent-red);">*</span></label>
                    <input type="number" step="0.01" name="price" id="planPrice" class="form-input" placeholder="199.00" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Currency</label>
                    <input type="text" name="currency" id="planCurrency" class="form-input" value="INR" required>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label">Description</label>
                <input type="text" name="description" id="planDescription" class="form-input" placeholder="e.g. Access all movies, series and live TV">
            </div>

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label">Features (One per line)</label>
                <textarea name="features" id="planFeatures" class="form-input" rows="4" placeholder="4K Ultra HD Streaming&#10;All VIP Movies & Web Series&#10;Live TV Channels&#10;Ad-Free Experience"></textarea>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label class="checkbox-card" style="margin-bottom: 0.5rem;">
                    <input type="checkbox" name="is_featured" id="planFeatured" value="1">
                    <div class="label-content">
                        <strong>Highlight as Featured / Popular</strong>
                        <p>Shows a golden badge on the plan card in the mobile app</p>
                    </div>
                </label>
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="closePlanModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" id="planSubmitBtn">Save Plan &rarr;</button>
            </div>
        </form>
    </div>
</div>

<style>
.tab-btn {
    background: transparent;
    border: none;
    padding: 0.75rem 1.25rem;
    color: var(--text-muted);
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.2s ease;
}
.tab-btn:hover { color: #fff; }
.tab-btn.active {
    color: var(--accent-red);
    border-bottom-color: var(--accent-red);
}
</style>

<script>
function switchTab(tabId) {
    if (tabId === 'create_plan_modal') {
        openCreatePlanModal();
        return;
    }
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    const pane = document.getElementById('tab-pane-' + tabId);
    const nav = document.getElementById('tab-nav-' + tabId);
    if (pane) pane.style.display = 'block';
    if (nav) nav.classList.add('active');
}

function openSendPaymentModal(subId, userName, planName, amount, currentLink, currentNotes) {
    document.getElementById('modalSubId').value = subId;
    document.getElementById('modalUserName').innerText = userName;
    document.getElementById('modalPlanName').innerText = planName;
    document.getElementById('modalAmount').innerText = '₹ ' + amount;
    document.getElementById('modalPaymentLink').value = currentLink || '';
    document.getElementById('modalPaymentNotes').value = currentNotes || '';
    document.getElementById('paymentModal').style.display = 'flex';
}

function closeSendPaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function openCreatePlanModal() {
    document.getElementById('planAction').value = 'create_plan';
    document.getElementById('editPlanId').value = '';
    document.getElementById('planModalTitle').innerText = 'Add New Subscription Plan';
    document.getElementById('planName').value = '';
    document.getElementById('planDuration').value = '30';
    document.getElementById('planPrice').value = '';
    document.getElementById('planCurrency').value = 'INR';
    document.getElementById('planDescription').value = '';
    document.getElementById('planFeatures').value = "All VIP Movies & Web Series\n4K Ultra HD Streaming\nLive TV Channels\nAd-Free Experience";
    document.getElementById('planFeatured').checked = false;
    document.getElementById('planSubmitBtn').innerText = 'Create Plan';
    document.getElementById('planModal').style.display = 'flex';
}

function openEditPlanModal(plan) {
    document.getElementById('planAction').value = 'edit_plan';
    document.getElementById('editPlanId').value = plan.id;
    document.getElementById('planModalTitle').innerText = 'Edit Plan: ' + plan.name;
    document.getElementById('planName').value = plan.name;
    document.getElementById('planDuration').value = plan.duration_days;
    document.getElementById('planPrice').value = plan.price;
    document.getElementById('planCurrency').value = plan.currency;
    document.getElementById('planDescription').value = plan.description || '';

    let feats = [];
    try {
        feats = JSON.parse(plan.features || '[]');
    } catch(e) {}
    document.getElementById('planFeatures').value = feats.join("\n");
    document.getElementById('planFeatured').checked = plan.is_featured == 1;
    document.getElementById('planSubmitBtn').innerText = 'Update Plan';
    document.getElementById('planModal').style.display = 'flex';
}

function closePlanModal() {
    document.getElementById('planModal').style.display = 'none';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
