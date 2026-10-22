<?php
/**
 * PikaShow First-Time Installation Wizard & Database Setup
 * If the database already exists, it preserves existing tables and data,
 * and asks to install only the missing tables.
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/backup_manager.php';
require_once __DIR__ . '/schema_definitions.php';

// Handle AJAX DB Test / Inspection Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'test_db' || ($_POST['action'] ?? '') === 'inspect_db')) {
    $host = trim($_POST['host'] ?? '127.0.0.1');
    $port = (int)($_POST['port'] ?? 3306);
    $user = trim($_POST['user'] ?? 'root');
    $pass = $_POST['pass'] ?? '';
    $dbname = trim($_POST['name'] ?? 'pikashow_db');

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $inspection = SchemaManager::inspectDatabase($pdo, $dbname);
        $tables = SchemaManager::getTables();

        $tableDetails = [];
        foreach ($tables as $tKey => $tDef) {
            $tableDetails[$tKey] = [
                'name' => $tDef['name'],
                'title' => $tDef['title'],
                'category' => $tDef['category'],
                'description' => $tDef['description'],
            ];
        }

        $msg = '';
        if (!$inspection['db_exists']) {
            $msg = "Connection successful! Database '{$dbname}' does not exist yet and will be created fresh.";
        } elseif ($inspection['all_exist']) {
            $msg = "Database '{$dbname}' already exists and contains all " . count($inspection['existing_tables']) . " required tables. No table installation needed.";
        } else {
            $msg = "Database '{$dbname}' already exists. Found " . count($inspection['existing_tables']) . " existing table(s) and " . count($inspection['missing_tables']) . " missing table(s).";
        }

        jsonResponse([
            'success' => true,
            'message' => $msg,
            'inspection' => $inspection,
            'table_details' => $tableDetails,
        ]);
    } catch (PDOException $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Database connection failed: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Handle AJAX Backup Inspection Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'inspect_backup_file') {
    if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'No backup file received or upload error occurred.']);
    }
    $tmpPath = $_FILES['backup_file']['tmp_name'];
    $origName = $_FILES['backup_file']['name'];
    $inspection = BackupManager::inspectBackupFile($tmpPath);
    if (!$inspection['valid']) {
        jsonResponse(['success' => false, 'message' => $inspection['message']]);
    }
    $inspection['orig_filename'] = $origName;
    jsonResponse(['success' => true, 'details' => $inspection]);
}

// Check if already installed
$isInstalled = Database::isInstalled();
$isRepairMode = (isset($_GET['repair']) && $_GET['repair'] === '1') || (isset($_GET['mode']) && $_GET['mode'] === 'repair');
$isRestoreMode = (isset($_GET['mode']) && $_GET['mode'] === 'restore_backup') || (isset($_GET['restore']) && $_GET['restore'] === '1');

// If already installed and in Repair / Missing Tables Mode
if ($isInstalled && $isRepairMode) {
    $repairError = '';
    $repairSuccess = '';
    $dbConfig = Database::getConfig()['db'] ?? [];
    $pdo = Database::getInstance();
    $dbName = $dbConfig['database'] ?? '';

    // Handle missing table installation action from repair screen
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_missing') {
        $tablesToInstall = $_POST['missing_tables'] ?? [];
        if (empty($tablesToInstall)) {
            $repairError = 'No tables selected for installation.';
        } else {
            try {
                $res = SchemaManager::installTables($pdo, $dbName, $tablesToInstall);
                if ($res['success']) {
                    $repairSuccess = 'Successfully installed missing table(s): ' . implode(', ', $res['installed']);
                } else {
                    $repairError = 'Installed with warnings: ' . implode(', ', $res['errors']);
                }
            } catch (Exception $e) {
                $repairError = 'Failed to install missing tables: ' . $e->getMessage();
            }
        }
    }

    $inspection = SchemaManager::inspectDatabase($pdo, $dbName);
    $tableDefs = SchemaManager::getTables();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Health & Missing Tables - PikaShow</title>
        <link rel="stylesheet" href="../assets/css/installer.css">
    </head>
    <body>
        <div class="installer-card" style="max-width: 720px;">
            <div class="brand-header">
                <div class="brand-badge">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                    Database Health Studio
                </div>
                <h1 class="installer-title">Missing Tables Inspector</h1>
                <p class="installer-subtitle">Active Database: <code><?= htmlspecialchars($dbName) ?></code> (<?= htmlspecialchars($dbConfig['host'] ?? '127.0.0.1') ?>:<?= (int)($dbConfig['port'] ?? 3306) ?>)</p>
            </div>

            <?php if (!empty($repairSuccess)): ?>
                <div class="alert alert-success">
                    <strong>✓ Success:</strong> <?= htmlspecialchars($repairSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($repairError)): ?>
                <div class="alert alert-danger">
                    <strong>Error:</strong> <?= htmlspecialchars($repairError) ?>
                </div>
            <?php endif; ?>

            <div style="margin-bottom: 1.5rem;">
                <div style="font-size: 0.88rem; font-weight: 600; margin-bottom: 0.4rem; color: #cbd5e1;">Existing Tables (<?= count($inspection['existing_tables']) ?>/<?= count($tableDefs) ?>):</div>
                <div class="chip-list">
                    <?php foreach ($inspection['existing_tables'] as $tbl): ?>
                        <span class="table-chip present">✓ <?= htmlspecialchars($tbl) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($inspection['has_missing']): ?>
                <form method="POST" action="install.php?repair=1">
                    <input type="hidden" name="action" value="install_missing">
                    <div class="alert alert-danger" style="margin-bottom: 1rem;">
                        <strong>Missing Tables Detected:</strong> <?= count($inspection['missing_tables']) ?> table(s) are missing from your database. Select the tables you wish to install.
                    </div>

                    <div class="missing-tables-container" style="margin-bottom: 1.5rem;">
                        <?php foreach ($inspection['missing_tables'] as $mTbl): 
                            $meta = $tableDefs[$mTbl] ?? ['title' => $mTbl, 'category' => 'Schema', 'description' => ''];
                        ?>
                            <label class="missing-table-item">
                                <input type="checkbox" name="missing_tables[]" value="<?= htmlspecialchars($mTbl) ?>" checked>
                                <div class="meta">
                                    <div class="tbl-name">
                                        <?= htmlspecialchars($meta['title']) ?> (<code><?= htmlspecialchars($mTbl) ?></code>)
                                        <span class="tbl-cat"><?= htmlspecialchars($meta['category']) ?></span>
                                    </div>
                                    <div class="tbl-desc"><?= htmlspecialchars($meta['description']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <a href="../index.php" class="btn btn-secondary">Dashboard</a>
                        <button type="submit" class="btn btn-primary">Install Missing Tables</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-success" style="margin-bottom: 1.5rem;">
                    <strong>All Tables Present!</strong> Your database is 100% up to date with all required tables.
                </div>
                <div style="display: flex; gap: 1rem; justify-content: center;">
                    <a href="../login.php" class="btn btn-primary">Go to Login</a>
                    <a href="../index.php" class="btn btn-secondary">Open Dashboard</a>
                </div>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// If already installed and NOT in repair mode or restore mode, show standard already installed card
if ($isInstalled && !$isRestoreMode) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Already Installed - PikaShow</title>
        <link rel="stylesheet" href="../assets/css/installer.css">
    </head>
    <body>
        <div class="installer-card" style="text-align: center; max-width: 580px;">
            <div class="brand-badge">PikaShow Backend</div>
            <h1 class="installer-title">Already Installed</h1>
            <p class="installer-subtitle" style="margin-bottom: 1.5rem;">
                PikaShow backend is already installed and configured.
            </p>

            <div class="alert alert-info" style="text-align: left; margin-bottom: 2rem;">
                <strong>Database Health & Restore:</strong> If you updated backend features, you can verify missing tables. If you have a backup archive (.zip or .sql) from another server, you can restore it directly.
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <div style="display: flex; gap: 1rem; justify-content: center;">
                    <a href="../login.php" class="btn btn-primary" style="flex: 1;">Admin Sign In</a>
                    <a href="../index.php" class="btn btn-secondary" style="flex: 1;">Open Dashboard</a>
                </div>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <a href="install.php?repair=1" class="btn btn-test" style="flex: 1; text-align: center; justify-content: center;">
                        🔍 Check Missing Tables
                    </a>
                    <a href="install.php?mode=restore_backup" class="btn btn-secondary" style="flex: 1; text-align: center; justify-content: center;">
                        📦 Restore from Backup
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// System Requirements check
$reqs = [
    'PHP Version >= 8.0' => [
        'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'current' => PHP_VERSION,
        'required' => '>= 8.0.0'
    ],
    'PDO Extension' => [
        'status' => extension_loaded('pdo'),
        'current' => extension_loaded('pdo') ? 'Enabled' : 'Disabled',
        'required' => 'Required'
    ],
    'PDO MySQL Driver' => [
        'status' => extension_loaded('pdo_mysql'),
        'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Disabled',
        'required' => 'Required'
    ],
    'JSON Extension' => [
        'status' => extension_loaded('json'),
        'current' => extension_loaded('json') ? 'Enabled' : 'Disabled',
        'required' => 'Required'
    ],
    'ZipArchive Extension' => [
        'status' => BackupManager::isZipSupported(),
        'current' => BackupManager::isZipSupported() ? 'Enabled (Full System Backups)' : 'Disabled',
        'required' => 'Recommended'
    ],
    'Uploads Directory Writable' => [
        'status' => is_writable(dirname(__DIR__) . '/uploads'),
        'current' => is_writable(dirname(__DIR__) . '/uploads') ? 'Writable' : 'Not Writable',
        'required' => 'Writable'
    ],
];

$requiredReqs = array_filter($reqs, fn($r) => $r['required'] !== 'Recommended');
$allReqsPassed = !in_array(false, array_column($requiredReqs, 'status'), true);
$errorMsg = '';
$successMsg = '';

// Handle Installation via Backup Archive POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_from_backup') {
    $dbHost = trim(!empty($_POST['b_db_host']) ? $_POST['b_db_host'] : ($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = (int)(!empty($_POST['b_db_port']) ? $_POST['b_db_port'] : ($_POST['db_port'] ?? 3306));
    $dbName = trim(!empty($_POST['b_db_name']) ? $_POST['b_db_name'] : ($_POST['db_name'] ?? 'pikashow_db'));
    $dbUser = trim(!empty($_POST['b_db_user']) ? $_POST['b_db_user'] : ($_POST['db_user'] ?? 'root'));
    $dbPass = !empty($_POST['b_db_pass']) ? $_POST['b_db_pass'] : ($_POST['db_pass'] ?? '');
    $restoreUploads = !empty($_POST['restore_uploads']);
    $keepBackupAdmin = !empty($_POST['keep_backup_admin']);

    $adminOverride = null;
    if (!$keepBackupAdmin) {
        $adminUser = trim(!empty($_POST['b_admin_user']) ? $_POST['b_admin_user'] : ($_POST['admin_user'] ?? ''));
        $adminEmail = trim(!empty($_POST['b_admin_email']) ? $_POST['b_admin_email'] : ($_POST['admin_email'] ?? ''));
        $adminPass = !empty($_POST['b_admin_pass']) ? $_POST['b_admin_pass'] : ($_POST['admin_pass'] ?? '');
        $adminPassConfirm = !empty($_POST['b_admin_pass_confirm']) ? $_POST['b_admin_pass_confirm'] : ($_POST['admin_pass_confirm'] ?? '');

        if (empty($adminUser) || empty($adminEmail) || strlen($adminPass) < 6) {
            $errorMsg = 'New admin username, email, and password (min 6 characters) are required.';
        } elseif ($adminPass !== $adminPassConfirm) {
            $errorMsg = 'Admin passwords do not match.';
        } else {
            $adminOverride = [
                'username' => $adminUser,
                'email' => $adminEmail,
                'password' => $adminPass,
            ];
        }
    }

    if (empty($errorMsg)) {
        if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
            $errorMsg = 'Please provide all MySQL database connection credentials.';
        } elseif (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Please choose a valid backup archive file (.zip or .sql).';
        } else {
            $tmpPath = $_FILES['backup_file']['tmp_name'];
            $origName = $_FILES['backup_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['zip', 'sql'])) {
                $errorMsg = 'Invalid backup format. Only .zip and .sql files are supported.';
            } else {
                try {
                    // 1. Connect to MySQL server on new host
                    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);

                    // 2. Create database if it does not exist
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    $pdo->exec("USE `{$dbName}`;");

                    // 3. Save uploaded backup into backups/ folder for future reference
                    $backupDir = BackupManager::getBackupDir();
                    $savedName = 'restored_server_install_' . date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
                    $destPath = $backupDir . '/' . $savedName;
                    move_uploaded_file($tmpPath, $destPath);

                    // 4. Restore the backup (database tables + uploads + optional admin credentials override)
                    $restoreRes = BackupManager::restoreBackup($destPath, $pdo, $restoreUploads, $adminOverride);

                    // 5. Generate and write config.php
                    $secretKey = bin2hex(random_bytes(32));
                    $configContent = "<?php\n" .
                        "// Generated by PikaShow Installer (Restored from Backup: " . addslashes($origName) . ") on " . date('Y-m-d H:i:s') . "\n" .
                        "return [\n" .
                        "    'db' => [\n" .
                        "        'host' => " . var_export($dbHost, true) . ",\n" .
                        "        'port' => " . (int)$dbPort . ",\n" .
                        "        'database' => " . var_export($dbName, true) . ",\n" .
                        "        'username' => " . var_export($dbUser, true) . ",\n" .
                        "        'password' => " . var_export($dbPass, true) . ",\n" .
                        "        'charset' => 'utf8mb4',\n" .
                        "    ],\n" .
                        "    'app' => [\n" .
                        "        'name' => 'PikaShow Admin',\n" .
                        "        'url' => " . var_export(getBaseUrl(), true) . ",\n" .
                        "        'secret_key' => " . var_export($secretKey, true) . ",\n" .
                        "    ],\n" .
                        "];\n";

                    file_put_contents(__DIR__ . '/../config/config.php', $configContent);
                    file_put_contents(__DIR__ . '/../config/.installed', date('Y-m-d H:i:s'));

                    $notice = "New server setup complete! Successfully restored {$restoreRes['tables_restored']} tables ({$restoreRes['total_records']} total records)";
                    if ($restoreRes['files_restored'] > 0) {
                        $notice .= " and {$restoreRes['files_restored']} media assets";
                    }
                    $notice .= " from backup '{$origName}'. You can now log in with your admin credentials!";
                    setFlash('success', $notice);

                    header('Location: ../login.php');
                    exit;
                } catch (Exception $e) {
                    $errorMsg = 'Installation from backup failed: ' . $e->getMessage();
                }
            }
        }
    }
}

// Handle Installation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? 'pikashow_db');
    $dbUser = trim($_POST['db_user'] ?? 'root');
    $dbPass = $_POST['db_pass'] ?? '';

    $installScope = $_POST['install_scope'] ?? 'auto'; // 'missing_only', 'none', 'full_fresh'
    $missingTablesChosen = $_POST['missing_tables'] ?? null;
    $keepExistingAdmin = !empty($_POST['keep_existing_admin']);

    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminPassConfirm = $_POST['admin_pass_confirm'] ?? '';
    $seedData = !empty($_POST['seed_data']);

    if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
        $errorMsg = 'Please provide all database credentials.';
    } elseif (!$keepExistingAdmin && (empty($adminUser) || empty($adminEmail) || strlen($adminPass) < 6)) {
        $errorMsg = 'Admin username, email, and password (min 6 characters) are required.';
    } elseif (!$keepExistingAdmin && ($adminPass !== $adminPassConfirm)) {
        $errorMsg = 'Admin passwords do not match.';
    } else {
        try {
            // 1. Connect to MySQL server
            $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // 2. Inspect Database
            $inspection = SchemaManager::inspectDatabase($pdo, $dbName);
            $installedMissing = [];
            $wasDbCreated = false;

            if (!$inspection['db_exists']) {
                // Database does not exist -> Create DB and all tables fresh
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $pdo->exec("USE `{$dbName}`;");
                $wasDbCreated = true;

                $allTables = array_keys(SchemaManager::getTables());
                $res = SchemaManager::installTables($pdo, $dbName, $allTables);
                if (!$res['success']) {
                    throw new Exception("Error creating schema: " . implode(', ', $res['errors']));
                }
                $installedMissing = $res['installed'];
            } else {
                // Database ALREADY exists!
                // Safe operation: NEVER drop existing tables or delete existing rows.
                $pdo->exec("USE `{$dbName}`;");

                if ($installScope === 'missing_only' || $installScope === 'auto') {
                    // Install only the requested missing tables
                    $toInstall = is_array($missingTablesChosen)
                        ? array_values(array_intersect($inspection['missing_tables'], $missingTablesChosen))
                        : $inspection['missing_tables'];

                    if (!empty($toInstall)) {
                        $res = SchemaManager::installTables($pdo, $dbName, $toInstall);
                        if (!$res['success']) {
                            throw new Exception("Error installing missing tables: " . implode(', ', $res['errors']));
                        }
                        $installedMissing = $res['installed'];
                    }
                }
            }

            // 3. Handle Admin User Setup
            if (!$keepExistingAdmin && !empty($adminUser) && !empty($adminEmail) && !empty($adminPass)) {
                $passHash = password_hash($adminPass, PASSWORD_BCRYPT);
                // Check if user exists to update or insert
                $chkAdmin = $pdo->prepare("SELECT id FROM admin_users WHERE username = :u OR email = :e LIMIT 1");
                $chkAdmin->execute(['u' => $adminUser, 'e' => $adminEmail]);
                $existingAdminId = $chkAdmin->fetchColumn();

                if ($existingAdminId) {
                    $upd = $pdo->prepare("UPDATE admin_users SET password_hash = :p, username = :u, email = :e WHERE id = :id");
                    $upd->execute(['p' => $passHash, 'u' => $adminUser, 'e' => $adminEmail, 'id' => $existingAdminId]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, role) VALUES (:u, :e, :p, 'admin')");
                    $ins->execute(['u' => $adminUser, 'e' => $adminEmail, 'p' => $passHash]);
                }
            } elseif ($keepExistingAdmin && $inspection['admin_count'] === 0) {
                throw new Exception("Cannot keep existing admin because no admin account was found in the database. Please provide admin credentials.");
            }

            // 4. Seed Data if checked
            if ($seedData) {
                // Check if content catalog already has records to prevent duplicates
                $contentCount = 0;
                try {
                    $contentCount = (int)$pdo->query("SELECT COUNT(*) FROM content_items")->fetchColumn();
                } catch (Exception $e) {
                    $contentCount = 0;
                }

                $seed = require __DIR__ . '/seed_data.php';

                // Safe upsert settings
                if (!empty($seed['settings'])) {
                    $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v_up");
                    foreach ($seed['settings'] as $k => $v) {
                        $setStmt->execute(['k' => $k, 'v' => $v, 'v_up' => $v]);
                    }
                }

                // Insert content items only if table is currently empty
                if ($contentCount === 0 && !empty($seed['content_items'])) {
                    $itemStmt = $pdo->prepare("INSERT INTO content_items 
                        (id, title, type, poster_url, banner_url, rating, year, duration, synopsis, genres, languages, available_qualities, stream_url, is_promoted, is_trending, channel_category, views)
                        VALUES (:id, :title, :type, :poster, :banner, :rating, :year, :duration, :synopsis, :genres, :languages, :qualities, :stream, :promoted, :trending, :cat, :views)");

                    $seasonStmt = $pdo->prepare("INSERT INTO seasons (content_id, season_number, title) VALUES (:cid, :snum, :title)");
                    $epStmt = $pdo->prepare("INSERT INTO episodes (id, season_id, content_id, episode_number, title, duration, description, thumbnail, stream_url) 
                        VALUES (:id, :sid, :cid, :epnum, :title, :dur, :desc, :thumb, :stream)");

                    foreach ($seed['content_items'] as $item) {
                        $itemStmt->execute([
                            'id' => $item['id'],
                            'title' => $item['title'],
                            'type' => $item['type'],
                            'poster' => $item['poster_url'],
                            'banner' => $item['banner_url'],
                            'rating' => $item['rating'],
                            'year' => $item['year'],
                            'duration' => $item['duration'],
                            'synopsis' => $item['synopsis'],
                            'genres' => $item['genres'],
                            'languages' => $item['languages'],
                            'qualities' => $item['available_qualities'],
                            'stream' => $item['stream_url'],
                            'promoted' => $item['is_promoted'],
                            'trending' => $item['is_trending'],
                            'cat' => $item['channel_category'],
                            'views' => $item['views'] ?? 0,
                        ]);

                        if (!empty($item['seasons'])) {
                            foreach ($item['seasons'] as $s) {
                                $seasonStmt->execute([
                                    'cid' => $item['id'],
                                    'snum' => $s['season_number'],
                                    'title' => $s['title'],
                                ]);
                                $seasonId = (int)$pdo->lastInsertId();

                                if (!empty($s['episodes'])) {
                                    foreach ($s['episodes'] as $ep) {
                                        $epStmt->execute([
                                            'id' => $ep['id'],
                                            'sid' => $seasonId,
                                            'cid' => $item['id'],
                                            'epnum' => $ep['episode_number'],
                                            'title' => $ep['title'],
                                            'dur' => $ep['duration'],
                                            'desc' => $ep['description'],
                                            'thumb' => $ep['thumbnail'],
                                            'stream' => $ep['stream_url'],
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // 5. Write config.php
            $secretKey = bin2hex(random_bytes(32));
            $configContent = "<?php\n" .
                "// Generated by PikaShow Installer on " . date('Y-m-d H:i:s') . "\n" .
                "return [\n" .
                "    'db' => [\n" .
                "        'host' => " . var_export($dbHost, true) . ",\n" .
                "        'port' => " . (int)$dbPort . ",\n" .
                "        'database' => " . var_export($dbName, true) . ",\n" .
                "        'username' => " . var_export($dbUser, true) . ",\n" .
                "        'password' => " . var_export($dbPass, true) . ",\n" .
                "        'charset' => 'utf8mb4',\n" .
                "    ],\n" .
                "    'app' => [\n" .
                "        'name' => 'PikaShow Admin',\n" .
                "        'url' => " . var_export(getBaseUrl(), true) . ",\n" .
                "        'secret_key' => " . var_export($secretKey, true) . ",\n" .
                "    ],\n" .
                "];\n";

            file_put_contents(__DIR__ . '/../config/config.php', $configContent);
            file_put_contents(__DIR__ . '/../config/.installed', date('Y-m-d H:i:s'));

            // Flash success message
            if ($wasDbCreated) {
                setFlash('success', "PikaShow Backend successfully installed! Database '{$dbName}' and all tables created.");
            } elseif (!empty($installedMissing)) {
                setFlash('success', "PikaShow Backend successfully connected to existing database '{$dbName}'. Installed missing table(s): " . implode(', ', $installedMissing) . ". Existing tables preserved!");
            } else {
                setFlash('success', "PikaShow Backend successfully connected to existing database '{$dbName}'! All existing tables and data preserved.");
            }

            header('Location: ../login.php');
            exit;
        } catch (Exception $e) {
            $errorMsg = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PikaShow Backend - Setup & Installation</title>
    <link rel="stylesheet" href="../assets/css/installer.css">
</head>
<body>

<div class="installer-card">
    <div class="brand-header">
        <div class="brand-badge">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
            PikaShow Backend
        </div>
        <h1 class="installer-title">Installation Wizard</h1>
        <p class="installer-subtitle">Configure your MySQL database, admin account, and streaming backend.</p>
    </div>

    <!-- Setup Mode Tabs: Standard Setup vs Restore from Backup -->
    <div class="setup-mode-selector">
        <button type="button" class="mode-tab-btn <?= !$isRestoreMode ? 'active' : '' ?>" id="tabBtnStandard" onclick="switchSetupMode('standard')">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
            Standard Setup
        </button>
        <button type="button" class="mode-tab-btn <?= $isRestoreMode ? 'active' : '' ?>" id="tabBtnBackup" onclick="switchSetupMode('backup')">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
            Restore from Backup (.zip / .sql)
        </button>
    </div>

    <!-- Stepper Navigation (Standard Mode) -->
    <div class="stepper" id="stepperNav" style="<?= $isRestoreMode ? 'display: none;' : '' ?>">
        <div class="step-node active" id="node1">
            <div class="step-bubble">1</div>
            <span>Requirements</span>
        </div>
        <div class="step-node" id="node2">
            <div class="step-bubble">2</div>
            <span>Database</span>
        </div>
        <div class="step-node" id="node3">
            <div class="step-bubble">3</div>
            <span>Admin & Seed</span>
        </div>
    </div>

    <?php if (!empty($errorMsg)): ?>
        <div class="alert alert-danger">
            <strong>Error:</strong> <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <form id="installerForm" method="POST" action="install.php" enctype="multipart/form-data">
        <input type="hidden" name="action" id="installerActionInput" value="<?= $isRestoreMode ? 'install_from_backup' : 'install' ?>">
        <input type="hidden" name="install_scope" id="install_scope" value="auto">
        <input type="hidden" name="db_already_exists" id="db_already_exists" value="0">

        <!-- STEP 1: System Requirements -->
        <div class="step-panel" data-step="1">
            <h3 style="margin-bottom: 1rem; font-size: 1.15rem; font-family: 'Outfit';">System Environment Check</h3>
            <ul class="req-list">
                <?php foreach ($reqs as $name => $info): ?>
                    <li class="req-item">
                        <div>
                            <div class="title"><?= htmlspecialchars($name) ?></div>
                            <div class="input-hint">Required: <?= htmlspecialchars($info['required']) ?> | Found: <?= htmlspecialchars($info['current']) ?></div>
                        </div>
                        <?php if ($info['status']): ?>
                            <span class="badge pass">Pass</span>
                        <?php else: ?>
                            <span class="badge fail">Fail</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!$allReqsPassed): ?>
                <div class="alert alert-danger">
                    Some requirements are not met. Please ensure PHP PDO MySQL is enabled in your php.ini.
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    All environment checks passed! Click <strong>Next</strong> to configure your database.
                </div>
            <?php endif; ?>
        </div>

        <!-- STEP 2: Database Configuration -->
        <div class="step-panel" data-step="2" style="display: none;">
            <h3 style="margin-bottom: 0.5rem; font-size: 1.15rem; font-family: 'Outfit';">MySQL Database Connection</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                If the database already exists on your server, the installer will safely inspect it and offer to install only missing tables.
            </p>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="db_host">Database Host</label>
                    <input type="text" id="db_host" name="db_host" class="form-input" value="127.0.0.1" required>
                    <span class="input-hint">Usually 127.0.0.1 or localhost</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="db_port">Database Port</label>
                    <input type="number" id="db_port" name="db_port" class="form-input" value="3306" required>
                    <span class="input-hint">Default is 3306</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="db_name">Database Name</label>
                <input type="text" id="db_name" name="db_name" class="form-input" value="pikashow_db" required>
                <span class="input-hint">Existing database or name of new database to create</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="db_user">MySQL Username</label>
                    <input type="text" id="db_user" name="db_user" class="form-input" value="root" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="db_pass">MySQL Password</label>
                    <input type="password" id="db_pass" name="db_pass" class="form-input" placeholder="Leave empty if none">
                </div>
            </div>

            <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.8rem; color: var(--text-muted);">Inspect tables before installing</span>
                <button type="button" id="btnTestDb" class="btn btn-test">
                    <svg style="width: 15px; height: 15px; fill: currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9v-2h2v2zm0-4H9V7h2v5zm4 4h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                    Test Connection & Inspect DB
                </button>
            </div>

            <!-- Dynamic DB Inspection Container -->
            <div id="dbInspectionResult" style="display: none;"></div>
        </div>

        <!-- STEP 3: Admin User & Seeding -->
        <div class="step-panel" data-step="3" style="display: none;">
            <h3 style="margin-bottom: 0.5rem; font-size: 1.15rem; font-family: 'Outfit';">Admin Account & Catalog Setup</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                Set up administrator access and optional starter streaming catalog.
            </p>

            <!-- Detected DB Summary Banner -->
            <div id="step3DbSummary" style="display: none;"></div>

            <!-- Existing Admin Detected Option -->
            <div id="existingAdminCard" style="display: none; margin-bottom: 1.25rem;">
                <label class="checkbox-card" style="margin-top: 0; border-color: rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.08);">
                    <input type="checkbox" id="keep_existing_admin" name="keep_existing_admin" value="1" checked>
                    <div class="label-content">
                        <strong style="color: #6ee7b7;">Keep Existing Admin Account(s) (Recommended)</strong>
                        <p id="existingAdminDesc">Existing admin accounts found in this database. Your current login credentials will remain unchanged.</p>
                    </div>
                </label>
            </div>

            <!-- Admin Credentials Fields -->
            <div id="adminFieldsWrapper">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="admin_user">Admin Username</label>
                        <input type="text" id="admin_user" name="admin_user" class="form-input" value="admin" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="admin_email">Admin Email</label>
                        <input type="email" id="admin_email" name="admin_email" class="form-input" value="admin@pikashow.com" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="admin_pass">Admin Password</label>
                        <input type="password" id="admin_pass" name="admin_pass" class="form-input" placeholder="At least 6 characters" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="admin_pass_confirm">Confirm Password</label>
                        <input type="password" id="admin_pass_confirm" name="admin_pass_confirm" class="form-input" placeholder="Repeat password" required>
                    </div>
                </div>
            </div>

            <!-- Pre-seed Content Checkbox -->
            <label class="checkbox-card" id="seedCatalogCard" style="margin-top: 1rem;">
                <input type="checkbox" id="seed_data" name="seed_data" value="1" checked>
                <div class="label-content">
                    <strong id="seedCatalogTitle">Import PikaShow Catalog (Recommended for New DB)</strong>
                    <p id="seedCatalogDesc">Pre-populates database with Bollywood, Hollywood, Web Series (Mirzapur, Panchayat), and Live TV channels with working video streams.</p>
                </div>
            </label>
        </div>

        <!-- BACKUP RESTORE PANEL (When Restore from Backup mode is active) -->
        <div id="backupRestorePanel" style="display: <?= $isRestoreMode ? 'block' : 'none' ?>;">
            <h3 style="margin-bottom: 0.5rem; font-size: 1.15rem; font-family: 'Outfit';">Restore Server from Backup Archive</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem;">
                Upload your <strong>.zip</strong> (Full System Backup) or <strong>.sql</strong> (Database Dump) to restore all tables, users, catalog items, and uploaded media assets onto this server.
            </p>

            <!-- File Upload Dropzone -->
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" for="installerBackupFile">Select or Drop Backup File (.zip or .sql)</label>
                <div class="backup-dropzone" id="installerDropzone">
                    <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <div style="font-weight: 700; color: #fff; font-size: 0.95rem;" id="installerDropzoneTitle">Choose Backup Archive (.zip / .sql)</div>
                    <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 4px;" id="installerDropzoneHint">Click to browse local files or drag & drop here</div>
                </div>
                <input type="file" id="installerBackupFile" name="backup_file" accept=".zip,.sql" style="display: none;">
            </div>

            <!-- Dynamic Live Inspection Preview Card -->
            <div id="installerBackupStatsCard" style="display: none; margin-bottom: 1.25rem;"></div>

            <!-- Target Database Connection on New Server -->
            <h4 style="font-size: 1rem; color: #fff; margin: 1.5rem 0 0.5rem 0; font-family: 'Outfit';">Target MySQL Server Connection</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="b_db_host">Database Host</label>
                    <input type="text" id="b_db_host" name="b_db_host" class="form-input" value="127.0.0.1">
                    <span class="input-hint">Usually 127.0.0.1 or localhost</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="b_db_port">Database Port</label>
                    <input type="number" id="b_db_port" name="b_db_port" class="form-input" value="3306">
                    <span class="input-hint">Default is 3306</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="b_db_name">Database Name</label>
                <input type="text" id="b_db_name" name="b_db_name" class="form-input" value="pikashow_db">
                <span class="input-hint">Database will be created automatically if it doesn't exist yet</span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="b_db_user">MySQL Username</label>
                    <input type="text" id="b_db_user" name="b_db_user" class="form-input" value="root">
                </div>
                <div class="form-group">
                    <label class="form-label" for="b_db_pass">MySQL Password</label>
                    <input type="password" id="b_db_pass" name="b_db_pass" class="form-input" placeholder="Leave empty if none">
                </div>
            </div>

            <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.8rem; color: var(--text-muted);">Verify MySQL connection on this server</span>
                <button type="button" id="btnTestBackupDb" class="btn btn-test">
                    <svg style="width: 15px; height: 15px; fill: currentColor;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9v-2h2v2zm0-4H9V7h2v5zm4 4h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                    Test MySQL Connection
                </button>
            </div>
            <div id="backupDbTestResult" style="display: none; margin-top: 0.75rem;"></div>

            <!-- Admin Account Preferences -->
            <h4 style="font-size: 1rem; color: #fff; margin: 1.5rem 0 0.5rem 0; font-family: 'Outfit';">Admin Account</h4>
            <label class="checkbox-card" style="margin-top: 0; margin-bottom: 1rem; border-color: rgba(16, 185, 129, 0.4); background: rgba(16, 185, 129, 0.08);">
                <input type="checkbox" id="keep_backup_admin" name="keep_backup_admin" value="1" checked>
                <div class="label-content">
                    <strong style="color: #6ee7b7;">Keep Admin Account from Backup (Recommended)</strong>
                    <p style="margin: 0;">Log in with your existing administrator credentials after restore completes.</p>
                </div>
            </label>

            <!-- Optional New Admin Override -->
            <div id="backupAdminOverrideFields" style="display: none; margin-bottom: 1rem; padding: 1rem; background: rgba(0,0,0,0.25); border-radius: 12px; border: 1px solid var(--surface-border);">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="b_admin_user">New Admin Username</label>
                        <input type="text" id="b_admin_user" name="admin_user" class="form-input" value="admin">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="b_admin_email">New Admin Email</label>
                        <input type="email" id="b_admin_email" name="admin_email" class="form-input" value="admin@pikashow.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="b_admin_pass">New Admin Password</label>
                        <input type="password" id="b_admin_pass" name="admin_pass" class="form-input" placeholder="Min 6 characters">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="b_admin_pass_confirm">Confirm Password</label>
                        <input type="password" id="b_admin_pass_confirm" name="admin_pass_confirm" class="form-input" placeholder="Repeat password">
                    </div>
                </div>
            </div>

            <!-- Media Assets Restore Option -->
            <label class="checkbox-card" style="margin-top: 0.5rem; margin-bottom: 1.5rem;">
                <input type="checkbox" id="b_restore_uploads" name="restore_uploads" value="1" checked>
                <div class="label-content">
                    <strong style="color: #fff;">Restore Uploaded Media to /uploads (Recommended)</strong>
                    <p style="margin: 0;">Extracts all posters, banners, and thumbnails bundled in the ZIP backup.</p>
                </div>
            </label>

            <!-- Submit Button in Backup Mode -->
            <button type="submit" id="btnSubmitBackupRestore" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.85rem 1.25rem; font-size: 1rem; font-weight: 700; background: linear-gradient(135deg, var(--accent-red), #b20710); box-shadow: 0 4px 18px rgba(229, 9, 20, 0.4);">
                <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 8px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                Restore from Backup & Complete Installation
            </button>
        </div>

        <!-- Footer Buttons (Standard Mode) -->
        <div class="btn-group" id="standardBtnGroup" style="<?= $isRestoreMode ? 'display: none;' : '' ?>">
            <button type="button" id="btnPrev" class="btn btn-secondary" style="display: none;">
                &larr; Back
            </button>
            <div style="flex: 1;"></div>
            <button type="button" id="btnNext" class="btn btn-primary" <?= !$allReqsPassed ? 'disabled' : '' ?>>
                Next &rarr;
            </button>
            <button type="submit" id="btnSubmit" class="btn btn-primary" style="display: none;">
                Complete Installation
            </button>
        </div>
    </form>
</div>

<script src="../assets/js/installer.js"></script>
</body>
</html>
