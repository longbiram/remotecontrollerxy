<?php
/**
 * Settings & Mobile API Documentation
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();
$currentUser = Auth::user();

$error = '';
$success = '';

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currentPass = $_POST['current_pass'] ?? '';
    $newPass = $_POST['new_pass'] ?? '';
    $confirmPass = $_POST['confirm_pass'] ?? '';

    // Verify current password
    $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = :id");
    $stmt->execute(['id' => $currentUser['id']]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($currentPass, $u['password_hash'])) {
        $error = 'Incorrect current password.';
    } elseif (strlen($newPass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'New passwords do not match.';
    } else {
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $up = $pdo->prepare("UPDATE admin_users SET password_hash = :p WHERE id = :id");
        $up->execute(['p' => $newHash, 'id' => $currentUser['id']]);
        $success = 'Admin password changed successfully.';
    }
}

// Helper to safely upsert settings with native prepared statements (PDO::ATTR_EMULATE_PREPARES => false)
function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v_up");
    $stmt->execute(['k' => $key, 'v' => $value, 'v_up' => $value]);
}

// Handle App Settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_settings') {
    $appName = trim($_POST['app_name'] ?? 'PikaShow');
    $maintenance = !empty($_POST['maintenance_mode']) ? '1' : '0';

    try {
        saveSetting($pdo, 'app_name', $appName);
        saveSetting($pdo, 'maintenance_mode', $maintenance);
        $success = 'System settings updated.';
    } catch (Exception $e) {
        $error = 'Error saving settings: ' . $e->getMessage();
    }
}

// Handle App Release & OTA Update Settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_app_release') {
    $vCode = max(1, (int)($_POST['app_latest_version_code'] ?? 1));
    $vName = trim($_POST['app_latest_version_name'] ?? '1.0.0');
    $apkUrl = trim($_POST['app_apk_url'] ?? '');
    $webUrl = trim($_POST['app_website_url'] ?? '');
    $force = !empty($_POST['app_force_update']) ? '1' : '0';
    $minCode = max(1, (int)($_POST['app_min_supported_version_code'] ?? 1));
    $notes = trim($_POST['app_release_notes'] ?? '');

    try {
        saveSetting($pdo, 'app_latest_version_code', (string)$vCode);
        saveSetting($pdo, 'app_latest_version_name', $vName);
        saveSetting($pdo, 'app_apk_url', $apkUrl);
        saveSetting($pdo, 'app_website_url', $webUrl);
        saveSetting($pdo, 'app_force_update', $force);
        saveSetting($pdo, 'app_min_supported_version_code', (string)$minCode);
        saveSetting($pdo, 'app_release_notes', $notes);
        $success = 'App release and OTA update settings saved successfully.';
    } catch (Exception $e) {
        $error = 'Error saving update settings: ' . $e->getMessage();
    }
}

// Handle Database Table Sync / Migration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_database') {
    try {
        require_once __DIR__ . '/install/schema_definitions.php';
        $dbConfig = Database::getConfig()['db'] ?? [];
        $dbName = $dbConfig['database'] ?? '';
        $insp = SchemaManager::inspectDatabase($pdo, $dbName);
        if ($insp['has_missing']) {
            $res = SchemaManager::installTables($pdo, $dbName, $insp['missing_tables']);
            $success = 'Database synchronized. Installed missing table(s): ' . implode(', ', $res['installed']) . '. Existing tables preserved.';
        } else {
            $success = 'Database verified! All ' . count($insp['existing_tables']) . ' required tables are present and active.';
        }
    } catch (Exception $e) {
        $error = 'Database sync error: ' . $e->getMessage();
    }
}


// Fetch current settings
$settings = [];
$sQuery = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $sQuery->fetch()) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// Fetch tables count
$dbTablesCount = 0;
try {
    $tRes = $pdo->query("SHOW TABLES");
    $dbTablesCount = $tRes->rowCount();
} catch (Exception $e) {
}

$pageTitle = 'Settings & Mobile API';
require __DIR__ . '/includes/header.php';

$baseUrl = getBaseUrl();
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Settings & API Integration</h1>
        <p class="page-subtitle">Configure application settings and integrate with Flutter app</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success" style="margin-bottom: 1.5rem;">
        <?= htmlspecialchars($success) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
    <!-- Column 1: API Integration Guide -->
    <div>
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 1rem;">Mobile App REST API Endpoints</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem;">
                Your Flutter app can point directly to these REST endpoints to fetch live content. All responses are
                formatted in JSON matching the <code>ContentItem</code> model.
            </p>

            <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #38bdf8;">GET /api/content.php</span>
                        <a href="<?= $baseUrl ?>/api/content.php" target="_blank" class="btn btn-secondary btn-sm"
                            style="font-size: 0.75rem;">Test JSON &nearr;</a>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Query params: <code>?type=bollywood|hollywood|series|liveTv</code>, <code>?promoted=1</code>,
                        <code>?trending=1</code>, <code>?search=query</code>
                    </div>
                </div>

                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #38bdf8;">GET /api/detail.php?id=prom_01</span>
                        <a href="<?= $baseUrl ?>/api/detail.php?id=prom_01" target="_blank"
                            class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">Test JSON &nearr;</a>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Returns single item details along with full season & episode lists.
                    </div>
                </div>

                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #10b981;">POST /api/auth_register.php</span>
                        <span class="badge badge-primary" style="font-size: 0.7rem;">Auth</span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Payload: <code>name</code>, <code>email</code>, <code>password</code>. Registers new user,
                        creates session token.
                    </div>
                </div>

                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #10b981;">POST /api/auth_login.php</span>
                        <span class="badge badge-primary" style="font-size: 0.7rem;">Auth</span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Payload: <code>email</code>, <code>password</code>. Verifies credentials & returns active user
                        token.
                    </div>
                </div>

                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #10b981;">GET /api/check_update.php</span>
                        <a href="<?= $baseUrl ?>/api/check_update.php?version_code=1" target="_blank" class="btn btn-secondary btn-sm"
                            style="font-size: 0.75rem;">Test JSON &nearr;</a>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Query param: <code>?version_code=1</code>. Returns latest version code, APK URL, release notes, and force update flag.
                    </div>
                </div>

                <div
                    style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <span style="font-weight: 700; color: #38bdf8;">GET /api/health.php</span>
                        <a href="<?= $baseUrl ?>/api/health.php" target="_blank" class="btn btn-secondary btn-sm"
                            style="font-size: 0.75rem;">Test JSON &nearr;</a>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-dim);">
                        Returns server timestamp, database connection status, and API health.
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Schema Studio & Table Manager Card -->
        <div class="content-card"
            style="border: 1px solid rgba(56, 189, 248, 0.25); background: linear-gradient(180deg, rgba(56, 189, 248, 0.05) 0%, rgba(18, 22, 34, 0.95) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <h3 class="card-title"
                        style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 20px; height: 20px; fill: #38bdf8;" viewBox="0 0 24 24">
                            <path
                                d="M12 3C7.58 3 4 4.79 4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7c0-2.21-3.58-4-8-4zm0 2c3.87 0 6 1.5 6 2s-2.13 2-6 2-6-1.5-6-2 2.13-2 6-2zm6 5c0 .5-2.13 2-6 2s-6-1.5-6-2V8.77C7.38 9.53 9.5 10 12 10s4.62-.47 6-1.23V10zm0 5c0 .5-2.13 2-6 2s-6-1.5-6-2v-1.23c1.38.76 3.5 1.23 6 1.23s4.62-.47 6-1.23V15zm0 2c0 .5-2.13 2-6 2s-6-1.5-6-2v-1.23c1.38.76 3.5 1.23 6 1.23s4.62-.47 6-1.23V17z" />
                        </svg>
                        Database & Schema Management
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Inspect tables, add new tables, add
                        columns, and run database migrations</p>
                </div>
                <span class="badge badge-secondary" style="font-size: 0.8rem;"><?= $dbTablesCount ?> Tables</span>
            </div>

            <p style="color: var(--text-dim); font-size: 0.85rem; margin-bottom: 1.25rem;">
                Easily modify your database structure directly from the admin panel without opening phpMyAdmin or
                external database tools.
            </p>

            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <a href="database_manager.php" class="btn btn-primary"
                    style="flex: 1; text-align: center; justify-content: center;">
                    <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24">
                        <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z" />
                    </svg>
                    Open Database Studio & Add Tables
                </a>
                <a href="install/install.php?repair=1" class="btn btn-secondary" title="Inspect database for missing tables">
                    Inspect Missing Tables
                </a>
                <form method="POST" action="settings.php" style="display: inline;">
                    <input type="hidden" name="action" value="sync_database">
                    <button type="submit" class="btn btn-secondary"
                        title="Auto-install all missing tables">
                        Sync All Missing Tables
                    </button>
                </form>
            </div>
        </div>

        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 0.75rem;">How to Run Backend Locally</h3>
            <div
                style="background: #0f121d; border: 1px solid var(--border-color); padding: 1rem; border-radius: 10px; font-family: monospace; font-size: 0.85rem; color: #a5b4fc; overflow-x: auto;">
                # Option 1: Built-in PHP server (Zero Configuration)<br>
                cd g:\remotecontrol\backend<br>
                php -S 0.0.0.0:8000<br><br>
                # Then open in browser:<br>
                http://localhost:8000/<br><br>
                # Mobile app connection:<br>
                # Android Emulator: http://10.0.2.2:8000/api/content.php<br>
                # Physical Device: http://&lt;YOUR_PC_LAN_IP&gt;:8000/api/content.php
            </div>
        </div>
    </div>

    <!-- Column 2: App Settings & Change Password -->
    <div>
        <!-- App Release & In-App Update Management -->
        <div class="content-card" style="border: 1px solid rgba(16, 185, 129, 0.35); background: linear-gradient(180deg, rgba(16, 185, 129, 0.05) 0%, rgba(18, 22, 34, 0.95) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div>
                    <h3 class="card-title" style="margin-bottom: 0.25rem; display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 20px; height: 20px; fill: #10b981;" viewBox="0 0 24 24">
                            <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                        </svg>
                        App Release & Website OTA Update
                    </h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Manage in-app updates for users who downloaded APK from website</p>
                </div>
                <span class="badge badge-success" style="font-size: 0.8rem;">Active OTA</span>
            </div>

            <form method="POST" action="settings.php">
                <input type="hidden" name="action" value="update_app_release">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Latest Version Code <span style="color:#10b981;">(Numeric integer)</span></label>
                        <input type="number" name="app_latest_version_code" class="form-input" min="1" step="1"
                            value="<?= htmlspecialchars($settings['app_latest_version_code'] ?? '1') ?>" required>
                        <small style="color: var(--text-dim); font-size: 0.75rem;">Must match <code>versionCode</code> (+N) in pubspec.yaml</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Latest Version Name <span style="color:#38bdf8;">(Display string)</span></label>
                        <input type="text" name="app_latest_version_name" class="form-input" placeholder="e.g. 1.0.1"
                            value="<?= htmlspecialchars($settings['app_latest_version_name'] ?? '1.0.0') ?>" required>
                        <small style="color: var(--text-dim); font-size: 0.75rem;">e.g. 1.0.1, 1.1.0, 2.0.0</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Direct APK Download URL</label>
                    <input type="url" name="app_apk_url" class="form-input" placeholder="https://yourwebsite.com/downloads/pikashow.apk"
                        value="<?= htmlspecialchars($settings['app_apk_url'] ?? ($baseUrl . '/uploads/pikashow_latest.apk')) ?>" required>
                    <small style="color: var(--text-dim); font-size: 0.75rem;">The public link to the APK file that the app will download in-app</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Website Landing / Fallback URL</label>
                    <input type="url" name="app_website_url" class="form-input" placeholder="https://yourwebsite.com"
                        value="<?= htmlspecialchars($settings['app_website_url'] ?? $baseUrl) ?>" required>
                    <small style="color: var(--text-dim); font-size: 0.75rem;">Opened in browser if user prefers manual web download</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Release Notes / What's New</label>
                    <textarea name="app_release_notes" class="form-input" rows="3" placeholder="• Bug fixes and performance improvements"><?= htmlspecialchars($settings['app_release_notes'] ?? "• Faster streaming playback\n• Bug fixes and stability improvements\n• Enhanced video server connections") ?></textarea>
                </div>

                <div class="form-row" style="align-items: center; margin-bottom: 1.25rem;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">Min Supported Version Code</label>
                        <input type="number" name="app_min_supported_version_code" class="form-input" min="1" step="1"
                            value="<?= htmlspecialchars($settings['app_min_supported_version_code'] ?? '1') ?>">
                        <small style="color: var(--text-dim); font-size: 0.75rem;">Versions below this MUST update</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 0; padding-top: 1.2rem;">
                        <label class="checkbox-card" style="margin-bottom: 0; padding: 0.6rem 0.85rem;">
                            <input type="checkbox" name="app_force_update" value="1" <?= ($settings['app_force_update'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <div class="label-content">
                                <strong style="color: #ef4444;">Force Update All Users</strong>
                                <p style="font-size: 0.75rem;">Cannot dismiss update dialog</p>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                    Publish / Save App Release Settings
                </button>
            </form>
        </div>

        <!-- App Settings -->
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 1.25rem;">General Settings</h3>
            <form method="POST" action="settings.php">
                <input type="hidden" name="action" value="update_settings">

                <div class="form-group">
                    <label class="form-label">Platform Name</label>
                    <input type="text" name="app_name" class="form-input"
                        value="<?= htmlspecialchars($settings['app_name'] ?? 'PikaShow') ?>" required>
                </div>

                <label class="checkbox-card" style="margin-top: 1rem; margin-bottom: 1.5rem;">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                    <div class="label-content">
                        <strong>Maintenance Mode</strong>
                        <p>When enabled, API returns 503 maintenance message to mobile apps</p>
                    </div>
                </label>

                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    Save General Settings
                </button>
            </form>
        </div>

        <!-- Change Password -->
        <div class="content-card">
            <h3 class="card-title" style="margin-bottom: 1.25rem;">Change Admin Password</h3>
            <form method="POST" action="settings.php">
                <input type="hidden" name="action" value="change_password">

                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_pass" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_pass" class="form-input" placeholder="Min 6 characters"
                            required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_pass" class="form-input" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-secondary" style="width: 100%; margin-top: 0.5rem;">
                    Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>