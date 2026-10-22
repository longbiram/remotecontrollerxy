<?php
/**
 * PikaShow Admin Panel: Backup & Disaster Recovery Studio
 * Allows administrators to generate complete full-system backups (database + uploaded assets),
 * export database SQL dumps, download backups, upload external backup archives to restore,
 * and manage existing backup files.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup_manager.php';

if (!Database::isInstalled()) {
    header('Location: install/install.php');
    exit;
}

Auth::requireAuth();

$pdo = Database::getInstance();
$currentUser = Auth::user();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$error = '';
$success = '';

// -------------------------------------------------------------
// GET ACTION: DOWNLOAD BACKUP
// -------------------------------------------------------------
if ($action === 'download' && !empty($_GET['file'])) {
    $filename = basename($_GET['file']);
    BackupManager::downloadBackup($filename);
    exit;
}

// -------------------------------------------------------------
// POST ACTION: CREATE FULL BACKUP (.ZIP)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_full_backup') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        try {
            $label = trim($_POST['backup_label'] ?? '');
            $res = BackupManager::createFullBackup($label, $currentUser['username'] ?? 'admin');
            setFlash('success', "Full System Backup created successfully: {$res['filename']} ({$res['size_formatted']}). Download is ready below.");
            header('Location: backup.php?highlight=' . urlencode($res['filename']));
            exit;
        } catch (Exception $e) {
            $error = 'Backup failed: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// POST ACTION: CREATE DATABASE ONLY BACKUP (.SQL)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_db_backup') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        try {
            $label = trim($_POST['backup_label'] ?? '');
            $res = BackupManager::createDatabaseBackup($label, $currentUser['username'] ?? 'admin');
            setFlash('success', "Database Backup (.sql) created successfully: {$res['filename']} ({$res['size_formatted']}).");
            header('Location: backup.php?highlight=' . urlencode($res['filename']));
            exit;
        } catch (Exception $e) {
            $error = 'Database backup failed: ' . $e->getMessage();
        }
    }
}

// -------------------------------------------------------------
// POST ACTION: RESTORE FROM EXISTING SERVER BACKUP FILE
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'restore_server_file') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $targetFile = basename($_POST['filename'] ?? '');
        $restoreUploads = !empty($_POST['restore_uploads']);
        $backupPath = BackupManager::getBackupDir() . '/' . $targetFile;

        if (empty($targetFile) || !file_exists($backupPath)) {
            $error = 'Specified backup file was not found on the server.';
        } else {
            try {
                $res = BackupManager::restoreBackup($backupPath, $pdo, $restoreUploads);
                $msg = "System restored successfully from '{$targetFile}'. Restored {$res['tables_restored']} tables ({$res['total_records']} total records)";
                if ($res['files_restored'] > 0) {
                    $msg .= " and {$res['files_restored']} media files";
                }
                $msg .= ".";
                setFlash('success', $msg);
                header('Location: backup.php');
                exit;
            } catch (Exception $e) {
                $error = 'Restore failed: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// POST ACTION: UPLOAD & RESTORE BACKUP FILE
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_and_restore') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE specified in the form.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No backup file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            ];
            $errCode = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            $error = $uploadErrors[$errCode] ?? 'File upload failed.';
        } else {
            $origName = $_FILES['backup_file']['name'];
            $tmpPath = $_FILES['backup_file']['tmp_name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['zip', 'sql'])) {
                $error = 'Invalid file type. Only .zip and .sql backup files are supported.';
            } else {
                $backupDir = BackupManager::getBackupDir();
                $savedName = 'uploaded_' . date('Y-m-d_H-i-s') . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
                $destPath = $backupDir . '/' . $savedName;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    $error = 'Failed to save uploaded backup file on the server.';
                } else {
                    $restoreUploads = !empty($_POST['restore_uploads']);
                    try {
                        $res = BackupManager::restoreBackup($destPath, $pdo, $restoreUploads);
                        $msg = "Backup '{$origName}' successfully uploaded and restored! Restored {$res['tables_restored']} tables ({$res['total_records']} records)";
                        if ($res['files_restored'] > 0) {
                            $msg .= " and {$res['files_restored']} media uploads";
                        }
                        $msg .= ".";
                        setFlash('success', $msg);
                        header('Location: backup.php?highlight=' . urlencode($savedName));
                        exit;
                    } catch (Exception $e) {
                        $error = 'Restore execution failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------
// POST ACTION: DELETE BACKUP FILE
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_backup') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $filename = basename($_POST['filename'] ?? '');
        if (BackupManager::deleteBackup($filename)) {
            setFlash('success', "Backup file '{$filename}' was deleted.");
            header('Location: backup.php');
            exit;
        } else {
            $error = "Could not delete backup file '{$filename}'.";
        }
    }
}

// -------------------------------------------------------------
// GATHER SYSTEM & BACKUP STATS
// -------------------------------------------------------------
$backupsList = BackupManager::listBackups();
$totalBackupsSize = 0;
foreach ($backupsList as $b) {
    $totalBackupsSize += $b['size_bytes'];
}

// Database stats
$dbStats = ['tables' => 0, 'records' => 0, 'size_bytes' => 0];
try {
    $statusStmt = $pdo->query("SHOW TABLE STATUS");
    while ($r = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $dbStats['tables']++;
        $dbStats['records'] += (int)($r['Rows'] ?? 0);
        $dbStats['size_bytes'] += (int)($r['Data_length'] ?? 0) + (int)($r['Index_length'] ?? 0);
    }
} catch (Exception $e) {}

// Uploads directory stats
$uploadsDir = BackupManager::getUploadsDir();
$uploadsCount = 0;
$uploadsSizeBytes = 0;
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $fn = $file->getFilename();
            if ($fn !== '.gitignore' && !str_starts_with($fn, '.tmp_')) {
                $uploadsCount++;
                $uploadsSizeBytes += $file->getSize();
            }
        }
    }
}

$lastBackup = !empty($backupsList) ? $backupsList[0] : null;
$highlightFile = $_GET['highlight'] ?? '';

$pageTitle = 'Backup & Disaster Recovery Studio';
require __DIR__ . '/includes/header.php';
$csrfToken = generateCsrfToken();
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Backup & Disaster Recovery Studio</h1>
        <p class="page-subtitle">Back up everything (database + uploaded media assets), download archives, and restore server data</p>
    </div>
    <div style="display: flex; gap: 0.75rem; align-items: center;">
        <a href="database_manager.php" class="btn btn-secondary">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7c0-2.21-3.58-4-8-4zm0 2c3.87 0 6 1.5 6 2s-2.13 2-6 2-6-1.5-6-2 2.13-2 6-2z"/></svg>
            Database Studio
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <svg style="width: 18px; height: 18px; fill: #ef4444; margin-right: 8px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<!-- System Statistics Counter Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem;">
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Backups on Server</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= count($backupsList) ?></div>
        <div style="font-size: 0.75rem; color: #38bdf8; margin-top: 0.35rem;"><?= BackupManager::formatBytes($totalBackupsSize) ?> stored in safe directory</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Active Database</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= number_format($dbStats['records']) ?></div>
        <div style="font-size: 0.75rem; color: #10b981; margin-top: 0.35rem;"><?= $dbStats['tables'] ?> tables &bull; <?= BackupManager::formatBytes($dbStats['size_bytes']) ?> data</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Uploaded Media Assets</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= number_format($uploadsCount) ?></div>
        <div style="font-size: 0.75rem; color: #a78bfa; margin-top: 0.35rem;"><?= BackupManager::formatBytes($uploadsSizeBytes) ?> in /uploads directory</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Latest Backup</div>
        <div style="font-size: 1.25rem; font-weight: 700; color: #fff; font-family: 'Outfit'; margin-top: 0.25rem;">
            <?= $lastBackup ? htmlspecialchars($lastBackup['relative_time']) : '<span style="color: var(--text-dim); font-size: 1rem;">None Created</span>' ?>
        </div>
        <div style="font-size: 0.75rem; color: #f59e0b; margin-top: 0.5rem;">
            <?= $lastBackup ? htmlspecialchars($lastBackup['size_formatted']) . ' (' . ($lastBackup['type'] === 'full_system' ? 'Full System' : 'SQL Only') . ')' : 'Create your first backup' ?>
        </div>
    </div>
</div>

<!-- Main 2-Column Actions Section -->
<div style="display: grid; grid-template-columns: 1.15fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
    <!-- LEFT CARD: GENERATE NEW BACKUP -->
    <div class="content-card" style="position: relative; overflow: hidden; border-top: 3px solid var(--accent-red);">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.85rem;">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(229, 9, 20, 0.15); display: flex; align-items: center; justify-content: center;">
                <svg style="width: 20px; height: 20px; fill: var(--accent-red);" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
            </div>
            <div>
                <h3 class="card-title" style="margin: 0; font-size: 1.15rem;">Create New System Backup</h3>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0;">Export everything into a safe, downloadable package</p>
            </div>
        </div>

        <p style="font-size: 0.88rem; color: #cbd5e1; line-height: 1.5; margin-bottom: 1.25rem;">
            A <strong>Full System Backup (.zip)</strong> packages all MySQL tables, records, schema definitions, system settings, and your entire <code style="color: #fca5a5;">/uploads</code> directory (posters, banners, thumbnails). You can use this archive anytime to restore this server or migrate to a brand new server during installation.
        </p>

        <form method="POST" action="backup.php" id="createBackupForm">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" id="backupActionType" value="create_full_backup">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" for="backup_label">Optional Backup Label / Note</label>
                <input type="text" id="backup_label" name="backup_label" class="form-input" placeholder="e.g. before_v2_upgrade or weekly_backup">
                <span class="input-hint">Appended to the backup file name for easy identification</span>
            </div>

            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                <button type="submit" onclick="document.getElementById('backupActionType').value='create_full_backup'; this.disabled=true; this.innerHTML='Generating Full Backup...'; this.form.submit();" class="btn btn-primary" style="flex: 1.4; justify-content: center; padding: 0.75rem 1rem;">
                    <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <strong>Back Up Everything (.ZIP)</strong>
                </button>

                <button type="submit" onclick="document.getElementById('backupActionType').value='create_db_backup'; this.disabled=true; this.innerHTML='Exporting SQL...'; this.form.submit();" class="btn btn-secondary" style="flex: 1; justify-content: center; padding: 0.75rem 1rem;">
                    <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7c0-2.21-3.58-4-8-4zm0 2c3.87 0 6 1.5 6 2s-2.13 2-6 2-6-1.5-6-2 2.13-2 6-2z"/></svg>
                    Database Only (.SQL)
                </button>
            </div>
        </form>

        <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(255, 255, 255, 0.03); border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px; font-size: 0.78rem; color: var(--text-muted);">
                <svg style="width: 14px; height: 14px; fill: #10b981;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                <span>Direct browser downloads enabled &bull; Protected by server-side .htaccess</span>
            </div>
            <span class="badge badge-primary" style="font-size: 0.65rem;">Auto-Indexed</span>
        </div>
    </div>

    <!-- RIGHT CARD: UPLOAD & RESTORE EXTERNAL BACKUP -->
    <div class="content-card" style="position: relative; overflow: hidden; border-top: 3px solid #06b6d4;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 0.85rem;">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(6, 182, 212, 0.15); display: flex; align-items: center; justify-content: center;">
                <svg style="width: 20px; height: 20px; fill: #06b6d4;" viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
            </div>
            <div>
                <h3 class="card-title" style="margin: 0; font-size: 1.15rem;">Upload & Restore Backup Archive</h3>
                <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0;">Upload a .zip or .sql file from your computer to restore this server</p>
            </div>
        </div>

        <form method="POST" action="backup.php" enctype="multipart/form-data" id="uploadRestoreForm" onsubmit="return confirmRestore(this);">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="upload_and_restore">

            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" for="backup_file">Select Backup File (.zip or .sql)</label>
                <div style="border: 2px dashed rgba(255, 255, 255, 0.15); border-radius: 12px; padding: 1.25rem 1rem; text-align: center; background: rgba(0,0,0,0.2); cursor: pointer;" onclick="document.getElementById('backup_file').click();">
                    <svg style="width: 32px; height: 32px; fill: #38bdf8; margin-bottom: 6px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                    <div style="font-size: 0.9rem; font-weight: 600; color: #fff;" id="selectedFileName">Choose .zip or .sql file</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Click to browse or drag file here</div>
                </div>
                <input type="file" id="backup_file" name="backup_file" accept=".zip,.sql" style="display: none;" onchange="handleFileSelected(this)" required>
            </div>

            <div style="margin-bottom: 1rem;">
                <label class="checkbox-card" style="margin: 0; padding: 0.75rem 1rem; font-size: 0.85rem; border-color: rgba(255, 255, 255, 0.1);">
                    <input type="checkbox" name="restore_uploads" value="1" checked>
                    <div class="label-content">
                        <strong style="font-size: 0.85rem; color: #fff;">Restore Media Assets to /uploads (Recommended)</strong>
                        <p style="font-size: 0.75rem; margin: 0; color: var(--text-muted);">Unpacks poster/banner images and media files included in the ZIP archive.</p>
                    </div>
                </label>
            </div>

            <div class="alert alert-danger" style="padding: 0.75rem; font-size: 0.8rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 8px;">
                <svg style="width: 16px; height: 16px; fill: #ef4444; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                <span><strong>Caution:</strong> Restoring will overwrite existing database tables and records with the data from the backup file.</span>
            </div>

            <button type="submit" id="btnUploadRestore" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.75rem 1rem; background: linear-gradient(135deg, #0891b2, #0284c7);">
                <svg style="width: 18px; height: 18px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                <strong>Upload & Restore Now</strong>
            </button>
        </form>
    </div>
</div>

<!-- EXISTING BACKUPS ARCHIVE TABLE -->
<div class="content-card" style="padding: 0; overflow: hidden; margin-bottom: 2rem;">
    <div style="padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div>
            <h3 class="card-title" style="margin: 0;">Existing Backups on Server (<?= count($backupsList) ?>)</h3>
            <p style="font-size: 0.82rem; color: var(--text-muted); margin: 0.25rem 0 0 0;">Stored in safe directory <code>backend/backups/</code> with direct download & restore actions</p>
        </div>
        <div>
            <span style="font-size: 0.8rem; color: var(--text-dim);">Total Size: <?= BackupManager::formatBytes($totalBackupsSize) ?></span>
        </div>
    </div>

    <?php if (empty($backupsList)): ?>
        <div style="text-align: center; padding: 3rem 1.5rem; color: var(--text-muted);">
            <svg style="width: 48px; height: 48px; fill: rgba(255,255,255,0.15); margin-bottom: 1rem;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
            <div style="font-size: 1.1rem; font-weight: 600; color: #fff; margin-bottom: 0.5rem;">No Backups Found Yet</div>
            <p style="font-size: 0.85rem; max-width: 460px; margin: 0 auto 1.5rem auto;">
                Create your first system backup above to safeguard your entire catalog, users, subscriptions, and uploaded posters.
            </p>
            <button onclick="document.getElementById('createBackupForm').scrollIntoView({behavior: 'smooth'});" class="btn btn-primary">
                Create First Backup Now
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Backup Archive / Filename</th>
                        <th>Type</th>
                        <th>File Size</th>
                        <th>Created Date</th>
                        <th>Archive Contents</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backupsList as $b): 
                        $isHigh = ($b['filename'] === $highlightFile);
                    ?>
                        <tr style="<?= $isHigh ? 'background: rgba(229, 9, 20, 0.1); border-left: 3px solid var(--accent-red);' : '' ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if ($b['type'] === 'full_system'): ?>
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(229, 9, 20, 0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <svg style="width: 18px; height: 18px; fill: var(--accent-red);" viewBox="0 0 24 24"><path d="M20 6h-8l-2-2H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6 10H6v-2h8v2zm4-4H6v-2h12v2z"/></svg>
                                        </div>
                                    <?php else: ?>
                                        <div style="width: 32px; height: 32px; border-radius: 8px; background: rgba(6, 182, 212, 0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                            <svg style="width: 18px; height: 18px; fill: #06b6d4;" viewBox="0 0 24 24"><path d="M12 3C7.58 3 4 4.79 4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7c0-2.21-3.58-4-8-4zm0 2c3.87 0 6 1.5 6 2s-2.13 2-6 2-6-1.5-6-2 2.13-2 6-2z"/></svg>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <strong style="color: #fff; font-family: monospace; font-size: 0.88rem;"><?= htmlspecialchars($b['filename']) ?></strong>
                                        <?php if ($isHigh): ?>
                                            <span class="badge badge-primary" style="font-size: 0.65rem; padding: 2px 6px; margin-left: 6px;">New</span>
                                        <?php endif; ?>
                                        <div style="font-size: 0.72rem; color: var(--text-dim); margin-top: 2px;">
                                            Path: <code>backend/backups/<?= htmlspecialchars($b['filename']) ?></code>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($b['type'] === 'full_system'): ?>
                                    <span class="badge badge-primary" style="font-size: 0.75rem; padding: 4px 8px; background: rgba(229, 9, 20, 0.2); border: 1px solid rgba(229, 9, 20, 0.4); color: #fca5a5;">
                                        Full System (.zip)
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="font-size: 0.75rem; padding: 4px 8px; background: rgba(6, 182, 212, 0.15); border: 1px solid rgba(6, 182, 212, 0.35); color: #67e8f9;">
                                        Database Dump (.sql)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong style="color: #fff; font-size: 0.88rem;"><?= htmlspecialchars($b['size_formatted']) ?></strong>
                            </td>
                            <td>
                                <div style="font-size: 0.85rem; color: #fff;"><?= htmlspecialchars($b['relative_time']) ?></div>
                                <div style="font-size: 0.72rem; color: var(--text-muted);"><?= htmlspecialchars($b['created_formatted']) ?></div>
                            </td>
                            <td>
                                <?php if ($b['manifest']): ?>
                                    <div style="font-size: 0.8rem; color: #cbd5e1;">
                                        <strong><?= (int)($b['tables_count'] ?? 0) ?></strong> tables &bull; 
                                        <strong><?= number_format((int)($b['records_count'] ?? 0)) ?></strong> records &bull; 
                                        <span style="color: #38bdf8;"><strong><?= (int)($b['uploads_count'] ?? 0) ?></strong> uploads</span>
                                    </div>
                                <?php elseif ($b['type'] === 'full_system'): ?>
                                    <span style="font-size: 0.78rem; color: var(--text-dim);">Database + Media Archive</span>
                                <?php else: ?>
                                    <span style="font-size: 0.78rem; color: var(--text-dim);">MySQL Table Schema & Inserts</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <!-- Download Button -->
                                    <a href="backup.php?action=download&file=<?= urlencode($b['filename']) ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 4px 10px;" title="Download to local PC">
                                        <svg style="width: 14px; height: 14px; fill: currentColor; margin-right: 4px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg>
                                        Download
                                    </a>

                                    <!-- Restore Button -->
                                    <button type="button" onclick="openServerRestoreModal('<?= htmlspecialchars($b['filename']) ?>', '<?= $b['type'] ?>')" class="btn btn-sm" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.35); font-size: 0.75rem; padding: 4px 10px;" title="Restore server state from this file">
                                        <svg style="width: 14px; height: 14px; fill: currentColor; margin-right: 4px;" viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                                        Restore
                                    </button>

                                    <!-- Delete Button -->
                                    <form method="POST" action="backup.php" style="display: inline;" onsubmit="return confirm('Permanently delete backup \'<?= htmlspecialchars($b['filename']) ?>\'? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="delete_backup">
                                        <input type="hidden" name="filename" value="<?= htmlspecialchars($b['filename']) ?>">
                                        <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" style="font-size: 0.75rem; padding: 4px 8px;" title="Delete backup file">
                                            <svg style="width: 13px; height: 13px; fill: currentColor;" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- HOW TO USE BACKUP IN NEW SERVER INSTALLATION GUIDE -->
<div class="content-card" style="background: rgba(255, 255, 255, 0.02); border: 1px dashed rgba(255, 255, 255, 0.15);">
    <div style="display: flex; align-items: flex-start; gap: 14px;">
        <div style="width: 40px; height: 40px; border-radius: 12px; background: rgba(99, 102, 241, 0.15); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <svg style="width: 22px; height: 22px; fill: #818cf8;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
        </div>
        <div>
            <h4 style="font-size: 1rem; color: #fff; margin-bottom: 0.4rem;">How to Migrate to a New Server Using Your Backup File</h4>
            <ol style="margin-left: 1.25rem; font-size: 0.85rem; color: #cbd5e1; line-height: 1.6;">
                <li>Click <strong>"Back Up Everything (.ZIP)"</strong> above and download your backup archive to your PC.</li>
                <li>Deploy your new server codebase and open its setup URL (e.g. <code>http://your-new-server/install/install.php</code>).</li>
                <li>In the installation wizard, choose <strong>"Restore from Backup File"</strong> mode.</li>
                <li>Upload your downloaded <code>.zip</code> or <code>.sql</code> backup archive and provide your new server's MySQL connection credentials.</li>
                <li>The installer automatically creates the new database, imports all tables & records, and extracts all uploaded posters/banners into <code>/uploads</code>!</li>
                <li>You can immediately log in with your existing admin account on the new server. Zero data loss!</li>
            </ol>
        </div>
    </div>
</div>

<!-- SERVER RESTORE CONFIRMATION MODAL -->
<div id="serverRestoreModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(8px); z-index: 1000; align-items: center; justify-content: center;">
    <div class="content-card" style="max-width: 480px; width: 90%; margin: auto; animation: fadeIn 0.25s ease-out;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 1rem;">
            <div style="width: 36px; height: 36px; border-radius: 10px; background: rgba(239, 68, 68, 0.15); display: flex; align-items: center; justify-content: center;">
                <svg style="width: 20px; height: 20px; fill: #ef4444;" viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.15rem; color: #fff;">Confirm System Restore</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Warning: Existing data will be overwritten</span>
            </div>
        </div>

        <p style="font-size: 0.88rem; color: #cbd5e1; line-height: 1.5; margin-bottom: 1.25rem;">
            Are you sure you want to restore the system state from <strong id="modalFileName" style="color: #38bdf8; font-family: monospace;"></strong>?
        </p>

        <form method="POST" action="backup.php" id="modalRestoreForm">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="restore_server_file">
            <input type="hidden" name="filename" id="modalFileTarget" value="">

            <div id="modalUploadsCheckWrapper" style="margin-bottom: 1.25rem;">
                <label class="checkbox-card" style="margin: 0; padding: 0.75rem 1rem; font-size: 0.85rem; border-color: rgba(255, 255, 255, 0.1);">
                    <input type="checkbox" name="restore_uploads" value="1" checked>
                    <div class="label-content">
                        <strong style="font-size: 0.85rem; color: #fff;">Restore Media Assets into /uploads</strong>
                        <p style="font-size: 0.75rem; margin: 0; color: var(--text-muted);">Extracts posters and banners from archive.</p>
                    </div>
                </label>
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="closeServerRestoreModal()" class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary" style="background: #ef4444; border-color: #ef4444;">
                    Yes, Overwrite & Restore
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function handleFileSelected(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('selectedFileName').innerText = file.name + ' (' + formatBytesJS(file.size) + ')';
    }
}

function formatBytesJS(bytes) {
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + units[i];
}

function confirmRestore(form) {
    const fileInput = document.getElementById('backup_file');
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Please choose a backup file first.');
        return false;
    }
    const confirmed = confirm('WARNING: Restoring will overwrite existing database tables and content with the backup contents. Are you completely sure you want to proceed?');
    if (confirmed) {
        const btn = document.getElementById('btnUploadRestore');
        btn.disabled = true;
        btn.innerHTML = 'Uploading & Restoring... Please wait.';
        return true;
    }
    return false;
}

function openServerRestoreModal(filename, type) {
    document.getElementById('modalFileName').innerText = filename;
    document.getElementById('modalFileTarget').value = filename;
    const modal = document.getElementById('serverRestoreModal');
    modal.style.display = 'flex';
}

function closeServerRestoreModal() {
    document.getElementById('serverRestoreModal').style.display = 'none';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
