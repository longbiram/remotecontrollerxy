<?php
/**
 * Database Management & Schema Studio
 * Allows admins to view database tables, inspect columns, create new tables (GUI builder),
 * add columns to existing tables, and run SQL queries/migrations safely.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

Auth::requireAuth();

$pdo = Database::getInstance();
$currentUser = Auth::user();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$activeTab = $_GET['tab'] ?? 'tables';
$error = '';
$success = '';
$queryResult = null;
$executedSql = '';

// Check database connection
if (!$pdo) {
    setFlash('danger', 'Database is not connected. Please configure your database in settings or run the installer.');
    header('Location: settings.php');
    exit;
}

// -------------------------------------------------------------
// POST ACTIONS HANDLERS
// -------------------------------------------------------------

// 0. INSTALL MISSING SCHEMA TABLES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'install_missing_tables') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        require_once __DIR__ . '/install/schema_definitions.php';
        $dbConfig = Database::getConfig()['db'] ?? [];
        $dbName = $dbConfig['database'] ?? '';
        $insp = SchemaManager::inspectDatabase($pdo, $dbName);
        if (!empty($insp['missing_tables'])) {
            $res = SchemaManager::installTables($pdo, $dbName, $insp['missing_tables']);
            if ($res['success']) {
                $success = 'Successfully installed missing table(s): ' . implode(', ', $res['installed']) . '. Existing tables and data preserved.';
            } else {
                $error = 'Errors installing missing tables: ' . implode(', ', $res['errors']);
            }
        } else {
            $success = 'All required database tables are already present.';
        }
    }
}

// 1. CREATE NEW TABLE (Interactive GUI Builder)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_table') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $rawTableName = trim($_POST['table_name'] ?? '');
        $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower($rawTableName));
        $colNames = $_POST['col_name'] ?? [];
        $colTypes = $_POST['col_type'] ?? [];
        $colLengths = $_POST['col_length'] ?? [];
        $colNullables = $_POST['col_nullable'] ?? [];
        $colDefaults = $_POST['col_default'] ?? [];
        $colPrimary = $_POST['col_primary'] ?? [];
        $colAutoInc = $_POST['col_auto_inc'] ?? [];

        if (empty($tableName)) {
            $error = 'Please provide a valid table name (letters, numbers, underscores only).';
        } elseif (empty($colNames) || !is_array($colNames)) {
            $error = 'At least one column is required to create a table.';
        } else {
            $validTypes = ['INT', 'BIGINT', 'TINYINT', 'VARCHAR', 'TEXT', 'LONGTEXT', 'DECIMAL', 'DATE', 'DATETIME', 'TIMESTAMP', 'JSON', 'FLOAT', 'DOUBLE', 'BOOLEAN'];
            $colDefs = [];
            $primaryKeys = [];

            for ($i = 0; $i < count($colNames); $i++) {
                $cName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(trim($colNames[$i])));
                if (empty($cName)) continue;

                $cType = strtoupper(trim($colTypes[$i] ?? 'VARCHAR'));
                if (!in_array($cType, $validTypes)) {
                    $cType = 'VARCHAR';
                }

                $cLength = trim($colLengths[$i] ?? '');
                $typeDef = $cType;
                if (!empty($cLength) && !in_array($cType, ['TEXT', 'LONGTEXT', 'DATETIME', 'TIMESTAMP', 'JSON', 'DATE', 'BOOLEAN'])) {
                    $sanitizedLen = preg_replace('/[^0-9,]/', '', $cLength);
                    if (!empty($sanitizedLen)) {
                        $typeDef .= "($sanitizedLen)";
                    }
                } elseif ($cType === 'VARCHAR' && empty($cLength)) {
                    $typeDef .= "(255)";
                }

                $nullable = !empty($colNullables[$i]) ? 'NULL' : 'NOT NULL';
                $isAutoInc = !empty($colAutoInc[$i]) ? ' AUTO_INCREMENT' : '';
                $isPri = !empty($colPrimary[$i]);

                $defaultClause = '';
                $rawDef = trim($colDefaults[$i] ?? '');
                if ($isAutoInc) {
                    $defaultClause = '';
                } elseif ($rawDef === 'CURRENT_TIMESTAMP') {
                    $defaultClause = ' DEFAULT CURRENT_TIMESTAMP';
                } elseif ($rawDef === 'NULL' && $nullable === 'NULL') {
                    $defaultClause = ' DEFAULT NULL';
                } elseif ($rawDef !== '' && $rawDef !== 'NONE') {
                    $defaultClause = " DEFAULT " . $pdo->quote($rawDef);
                }

                $colDefs[] = "`$cName` $typeDef $nullable$defaultClause$isAutoInc";

                if ($isPri) {
                    $primaryKeys[] = "`$cName`";
                }
            }

            if (empty($colDefs)) {
                $error = 'No valid column definitions found.';
            } else {
                if (!empty($primaryKeys)) {
                    $colDefs[] = "PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
                }

                $createSql = "CREATE TABLE `$tableName` (\n  " . implode(",\n  ", $colDefs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

                try {
                    $pdo->exec($createSql);
                    setFlash('success', "Table `$tableName` was created successfully!");
                    header("Location: database_manager.php?tab=tables&inspect=$tableName");
                    exit;
                } catch (Exception $e) {
                    $error = 'Failed to create table: ' . $e->getMessage();
                }
            }
        }
    }
}

// 2. ADD COLUMN TO EXISTING TABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_column') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $targetTable = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(trim($_POST['target_table'] ?? '')));
        $colName = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(trim($_POST['col_name'] ?? '')));
        $colType = strtoupper(trim($_POST['col_type'] ?? 'VARCHAR'));
        $colLength = preg_replace('/[^0-9,]/', '', trim($_POST['col_length'] ?? ''));
        $colNullable = !empty($_POST['col_nullable']) ? 'NULL' : 'NOT NULL';
        $colPosition = trim($_POST['col_position'] ?? '');
        $colDefault = trim($_POST['col_default'] ?? '');

        if (empty($targetTable) || empty($colName)) {
            $error = 'Table name and column name are required.';
        } else {
            $typeDef = $colType;
            if (!empty($colLength)) {
                $typeDef .= "($colLength)";
            } elseif ($colType === 'VARCHAR') {
                $typeDef .= "(255)";
            }

            $defaultClause = '';
            if ($colDefault === 'CURRENT_TIMESTAMP') {
                $defaultClause = ' DEFAULT CURRENT_TIMESTAMP';
            } elseif ($colDefault === 'NULL' && $colNullable === 'NULL') {
                $defaultClause = ' DEFAULT NULL';
            } elseif ($colDefault !== '' && $colDefault !== 'NONE') {
                $defaultClause = " DEFAULT " . $pdo->quote($colDefault);
            }

            $posClause = '';
            if ($colPosition === 'FIRST') {
                $posClause = ' FIRST';
            } elseif (!empty($colPosition)) {
                $cleanAfter = preg_replace('/[^a-zA-Z0-9_]/', '', $colPosition);
                if (!empty($cleanAfter)) {
                    $posClause = " AFTER `$cleanAfter`";
                }
            }

            $alterSql = "ALTER TABLE `$targetTable` ADD COLUMN `$colName` $typeDef $colNullable$defaultClause$posClause";

            try {
                $pdo->exec($alterSql);
                setFlash('success', "Column `$colName` successfully added to `$targetTable`.");
                header("Location: database_manager.php?tab=tables&inspect=$targetTable");
                exit;
            } catch (Exception $e) {
                $error = 'Failed to add column: ' . $e->getMessage();
            }
        }
    }
}

// 3. EXECUTE CUSTOM SQL / MIGRATION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'execute_sql') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $sqlQuery = trim($_POST['sql_query'] ?? '');
        $executedSql = $sqlQuery;

        if (empty($sqlQuery)) {
            $error = 'Please enter an SQL query to execute.';
        } else {
            try {
                $startTime = microtime(true);
                $isSelect = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s+/i', $sqlQuery);

                if ($isSelect) {
                    $stmt = $pdo->query($sqlQuery);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                    $queryResult = [
                        'type' => 'select',
                        'rows' => $rows,
                        'count' => count($rows),
                        'time' => $elapsed,
                    ];
                    $success = "Query executed in {$elapsed}ms. Returned " . count($rows) . " row(s).";
                } else {
                    $affected = $pdo->exec($sqlQuery);
                    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
                    $queryResult = [
                        'type' => 'exec',
                        'affected' => $affected,
                        'time' => $elapsed,
                    ];
                    $success = "Query executed successfully in {$elapsed}ms. Rows affected: {$affected}.";
                }
                $activeTab = 'console';
            } catch (Exception $e) {
                $error = 'SQL Execution Error: ' . $e->getMessage();
                $activeTab = 'console';
            }
        }
    }
}

// 4. ONE-CLICK INITIALIZE APP USERS TABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'ensure_app_users') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `app_users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `email` VARCHAR(191) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `avatar` TEXT NULL,
                `status` ENUM('active', 'suspended', 'pending') NOT NULL DEFAULT 'active',
                `token` VARCHAR(255) NULL,
                `last_login` DATETIME NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_email` (`email`),
                INDEX `idx_token` (`token`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
            setFlash('success', "Table `app_users` is ready and verified!");
            header('Location: database_manager.php?tab=tables&inspect=app_users');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to setup app_users table: ' . $e->getMessage();
        }
    }
}

// 5. DROP TABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'drop_table') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $dropTable = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(trim($_POST['table_name'] ?? '')));
        // Prevent accidental drop of vital tables without confirmation
        if (in_array($dropTable, ['admin_users', 'settings']) && empty($_POST['confirm_vital'])) {
            $error = "Cannot drop vital system table `$dropTable` without explicit confirmation.";
        } else {
            try {
                $pdo->exec("DROP TABLE `$dropTable`");
                setFlash('success', "Table `$dropTable` was dropped.");
                header('Location: database_manager.php?tab=tables');
                exit;
            } catch (Exception $e) {
                $error = 'Failed to drop table: ' . $e->getMessage();
            }
        }
    }
}

// 6. TRUNCATE TABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'truncate_table') {
    if (!verifyCsrfToken($_POST['csrf'] ?? '')) {
        $error = 'Security check failed. Invalid CSRF token.';
    } else {
        $truncTable = preg_replace('/[^a-zA-Z0-9_]/', '', strtolower(trim($_POST['table_name'] ?? '')));
        if ($truncTable === 'admin_users') {
            $error = "Cannot truncate `admin_users` table.";
        } else {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0; TRUNCATE TABLE `$truncTable`; SET FOREIGN_KEY_CHECKS = 1;");
                setFlash('success', "Table `$truncTable` has been truncated.");
                header("Location: database_manager.php?tab=tables&inspect=$truncTable");
                exit;
            } catch (Exception $e) {
                $error = 'Failed to truncate table: ' . $e->getMessage();
            }
        }
    }
}

// -------------------------------------------------------------
// FETCH DATABASE TABLES & STATUS
// -------------------------------------------------------------
$tablesList = [];
$totalRows = 0;
$totalDataSize = 0;

try {
    $statusStmt = $pdo->query("SHOW TABLE STATUS");
    while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
        $tName = $row['Name'];
        $rows = (int)($row['Rows'] ?? 0);
        $dataLen = (int)($row['Data_length'] ?? 0) + (int)($row['Index_length'] ?? 0);
        $totalRows += $rows;
        $totalDataSize += $dataLen;

        $tablesList[] = [
            'name' => $tName,
            'engine' => $row['Engine'] ?? 'InnoDB',
            'rows' => $rows,
            'collation' => $row['Collation'] ?? 'utf8mb4_unicode_ci',
            'size_bytes' => $dataLen,
            'size_formatted' => formatBytes($dataLen),
            'comment' => $row['Comment'] ?? '',
        ];
    }
} catch (Exception $e) {
    $error = 'Failed to fetch table list: ' . $e->getMessage();
}

// Inspect specific table
$inspectTable = $_GET['inspect'] ?? '';
$inspectedColumns = [];
$sampleData = [];
if (!empty($inspectTable)) {
    $cleanInspect = preg_replace('/[^a-zA-Z0-9_]/', '', $inspectTable);
    try {
        $colStmt = $pdo->query("SHOW FULL COLUMNS FROM `$cleanInspect`");
        $inspectedColumns = $colStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch sample rows
        $sampleStmt = $pdo->query("SELECT * FROM `$cleanInspect` ORDER BY 1 DESC LIMIT 15");
        $sampleData = $sampleStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $inspectTable = '';
    }
}

function formatBytes(int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . ($units[$i] ?? 'B');
}

$pageTitle = 'Database Manager & Schema Studio';
require __DIR__ . '/includes/header.php';
$csrfToken = generateCsrfToken();
?>

<div class="topbar">
    <div>
        <h1 class="page-title">Database Manager & Schema Studio</h1>
        <p class="page-subtitle">Inspect schemas, add new tables, modify columns, and run database migrations</p>
    </div>
    <div style="display: flex; gap: 0.75rem;">
        <a href="backup.php" class="btn btn-primary" style="background: linear-gradient(135deg, #E50914, #b20710); text-decoration: none;">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
            Backup & Restore
        </a>
        <button onclick="switchTab('create')" class="btn btn-secondary">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
            Add New Table
        </button>
        <button onclick="switchTab('console')" class="btn btn-secondary">
            <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M9.4 16.6L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4zm5.2 0l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4z"/></svg>
            SQL Console
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
        <svg style="width: 18px; height: 18px; fill: #ef4444; margin-right: 8px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
    <div class="alert alert-success" style="margin-bottom: 1.5rem;">
        <svg style="width: 18px; height: 18px; fill: #10b981; margin-right: 8px; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
        <span><?= htmlspecialchars($success) ?></span>
    </div>
<?php endif; ?>

<?php
// Check for missing schema tables
$schemaInspection = null;
try {
    require_once __DIR__ . '/install/schema_definitions.php';
    $dbConfig = Database::getConfig()['db'] ?? [];
    $dbName = $dbConfig['database'] ?? '';
    if ($pdo && !empty($dbName)) {
        $schemaInspection = SchemaManager::inspectDatabase($pdo, $dbName);
    }
} catch (Exception $e) {}
?>

<?php if (!empty($schemaInspection['has_missing'])): ?>
    <div class="alert" style="margin-bottom: 1.5rem; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.35); color: #fde68a; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; border-radius: 12px; padding: 1rem 1.25rem;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <svg style="width: 22px; height: 22px; fill: #fbbf24; flex-shrink: 0;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            <div>
                <strong style="color: #fff; font-size: 0.95rem;">Missing System Table(s) Detected:</strong>
                <span style="font-size: 0.85rem; margin-left: 4px;"><?= count($schemaInspection['missing_tables']) ?> required table(s) missing (<code><?= htmlspecialchars(implode(', ', $schemaInspection['missing_tables'])) ?></code>). Existing data will be preserved.</span>
            </div>
        </div>
        <form method="POST" action="database_manager.php" style="margin: 0;">
            <input type="hidden" name="action" value="install_missing_tables">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
            <button type="submit" class="btn btn-primary" style="padding: 0.55rem 1.2rem; font-size: 0.85rem; background: #f59e0b; border: none; color: #1e1b4b; font-weight: 700; cursor: pointer;">
                Install Missing Tables Now
            </button>
        </form>
    </div>
<?php endif; ?>


<!-- Quick Stat Cards -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem;">
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Total Tables</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= count($tablesList) ?></div>
        <div style="font-size: 0.75rem; color: #38bdf8; margin-top: 0.35rem;">Active database entities</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Total Records</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= number_format($totalRows) ?></div>
        <div style="font-size: 0.75rem; color: #10b981; margin-top: 0.35rem;">Across all catalogs & users</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">Data Storage Size</div>
        <div style="font-size: 1.85rem; font-weight: 800; color: #fff; font-family: 'Outfit';"><?= formatBytes($totalDataSize) ?></div>
        <div style="font-size: 0.75rem; color: #a78bfa; margin-top: 0.35rem;">Data + Index consumption</div>
    </div>
    <div class="content-card" style="padding: 1.25rem;">
        <div style="color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">App Users Schema</div>
        <?php $hasUsers = in_array('app_users', array_column($tablesList, 'name')); ?>
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.35rem;">
            <span class="badge <?= $hasUsers ? 'badge-primary' : 'badge-gold' ?>" style="font-size: 0.85rem; padding: 4px 10px;">
                <?= $hasUsers ? 'Installed & Active' : 'Not Created Yet' ?>
            </span>
        </div>
        <?php if (!$hasUsers): ?>
            <form method="POST" action="database_manager.php" style="margin-top: 0.6rem;">
                <input type="hidden" name="action" value="ensure_app_users">
                <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                <button type="submit" class="btn btn-primary btn-sm" style="font-size: 0.75rem; padding: 4px 8px;">Create `app_users` Table &rarr;</button>
            </form>
        <?php else: ?>
            <div style="font-size: 0.75rem; color: var(--text-dim); margin-top: 0.5rem;">User auth & login ready</div>
        <?php endif; ?>
    </div>
</div>

<!-- Tabs Navigation -->
<div style="display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem; align-items: center;">
    <button onclick="switchTab('tables')" class="tab-btn <?= $activeTab === 'tables' ? 'active' : '' ?>" id="tab-nav-tables">
        Database Tables (<?= count($tablesList) ?>)
    </button>
    <button onclick="switchTab('create')" class="tab-btn <?= $activeTab === 'create' ? 'active' : '' ?>" id="tab-nav-create">
        + Create New Table
    </button>
    <button onclick="switchTab('column')" class="tab-btn <?= $activeTab === 'column' ? 'active' : '' ?>" id="tab-nav-column">
        Add Column
    </button>
    <button onclick="switchTab('console')" class="tab-btn <?= $activeTab === 'console' ? 'active' : '' ?>" id="tab-nav-console">
        SQL Query Console
    </button>
    <div style="flex: 1;"></div>
    <a href="backup.php" class="tab-btn" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); border-radius: 8px; padding: 6px 14px; background: rgba(56, 189, 248, 0.08);">
        <svg style="width: 14px; height: 14px; fill: currentColor;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
        <strong>Backup Studio &rarr;</strong>
    </a>
</div>

<!-- TAB 1: TABLES LIST & INSPECTOR -->
<div id="tab-pane-tables" class="tab-pane" style="display: <?= $activeTab === 'tables' ? 'block' : 'none' ?>;">
    <div style="display: grid; grid-template-columns: <?= !empty($inspectTable) ? '1fr 1.35fr' : '1fr' ?>; gap: 1.5rem; align-items: start;">
        <!-- Left: Tables Table -->
        <div class="content-card" style="padding: 0; overflow: hidden;">
            <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <h3 class="card-title" style="margin: 0;">Tables in Database</h3>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Click "Inspect" to view columns</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Engine</th>
                            <th>Records</th>
                            <th>Size</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tablesList as $t): ?>
                            <tr style="<?= $inspectTable === $t['name'] ? 'background: rgba(229, 9, 20, 0.08);' : '' ?>">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <svg style="width: 16px; height: 16px; fill: #38bdf8;" viewBox="0 0 24 24"><path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/></svg>
                                        <strong style="color: #fff; font-family: monospace;"><?= htmlspecialchars($t['name']) ?></strong>
                                        <?php if ($t['name'] === 'app_users'): ?>
                                            <span class="badge badge-primary" style="font-size: 0.65rem; padding: 2px 6px;">New</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span style="font-size: 0.8rem; color: var(--text-dim);"><?= htmlspecialchars($t['engine']) ?></span></td>
                                <td><strong><?= number_format($t['rows']) ?></strong></td>
                                <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($t['size_formatted']) ?></span></td>
                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 6px;">
                                        <a href="database_manager.php?tab=tables&inspect=<?= urlencode($t['name']) ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem; padding: 4px 8px;">
                                            Inspect &rarr;
                                        </a>
                                        <?php if (!in_array($t['name'], ['admin_users', 'settings'])): ?>
                                            <form method="POST" action="database_manager.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to TRUNCATE <?= htmlspecialchars($t['name']) ?>? All rows will be permanently deleted!');">
                                                <input type="hidden" name="action" value="truncate_table">
                                                <input type="hidden" name="table_name" value="<?= htmlspecialchars($t['name']) ?>">
                                                <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                                <button type="submit" class="btn btn-sm" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3); font-size: 0.75rem; padding: 4px 8px;" title="Empty table data">
                                                    Truncate
                                                </button>
                                            </form>
                                            <form method="POST" action="database_manager.php" style="display: inline;" onsubmit="return confirm('WARNING: Are you sure you want to DROP <?= htmlspecialchars($t['name']) ?>? The table and all its data will be destroyed!');">
                                                <input type="hidden" name="action" value="drop_table">
                                                <input type="hidden" name="table_name" value="<?= htmlspecialchars($t['name']) ?>">
                                                <input type="hidden" name="csrf" value="<?= $csrfToken ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" style="font-size: 0.75rem; padding: 4px 8px;" title="Drop Table">
                                                    Drop
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Table Column Inspector & Sample Data (if selected) -->
        <?php if (!empty($inspectTable)): ?>
            <div>
                <div class="content-card" style="margin-bottom: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <div>
                            <h3 class="card-title" style="margin-bottom: 0.25rem;">
                                Structure of `<span style="color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($inspectTable) ?></span>`
                            </h3>
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><?= count($inspectedColumns) ?> columns defined</span>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button onclick="prefillAddColumn('<?= htmlspecialchars($inspectTable) ?>')" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                                + Add Column Here
                            </button>
                            <a href="database_manager.php?tab=tables" class="btn btn-sm" style="background: rgba(255,255,255,0.05); color: var(--text-muted); font-size: 0.75rem;">
                                Close &times;
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                        <table class="data-table" style="font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th>Field</th>
                                    <th>Type</th>
                                    <th>Null</th>
                                    <th>Key</th>
                                    <th>Default</th>
                                    <th>Extra</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($inspectedColumns as $c): ?>
                                    <tr>
                                        <td><strong style="color: #fff; font-family: monospace;"><?= htmlspecialchars($c['Field']) ?></strong></td>
                                        <td><span style="color: #38bdf8; font-family: monospace;"><?= htmlspecialchars($c['Type']) ?></span></td>
                                        <td>
                                            <span style="font-size: 0.75rem; color: <?= $c['Null'] === 'YES' ? '#10b981' : '#f59e0b' ?>;">
                                                <?= htmlspecialchars($c['Null']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($c['Key'] === 'PRI'): ?>
                                                <span class="badge badge-primary" style="font-size: 0.65rem;">PRI</span>
                                            <?php elseif (!empty($c['Key'])): ?>
                                                <span class="badge badge-secondary" style="font-size: 0.65rem;"><?= htmlspecialchars($c['Key']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span style="font-family: monospace; color: var(--text-dim);"><?= htmlspecialchars($c['Default'] ?? 'NULL') ?></span></td>
                                        <td><span style="color: #a78bfa; font-size: 0.75rem;"><?= htmlspecialchars($c['Extra']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sample Rows -->
                <div class="content-card">
                    <h3 class="card-title" style="margin-bottom: 0.75rem; font-size: 1rem;">
                        Sample Records (Latest <?= count($sampleData) ?> rows)
                    </h3>
                    <?php if (empty($sampleData)): ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">This table currently has no records.</p>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="data-table" style="font-size: 0.8rem; font-family: monospace;">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($sampleData[0]) as $k): ?>
                                            <th><?= htmlspecialchars($k) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sampleData as $r): ?>
                                        <tr>
                                            <?php foreach ($r as $val): ?>
                                                <td>
                                                    <?php 
                                                    $valStr = (string)$val;
                                                    if (strlen($valStr) > 40) {
                                                        $valStr = substr($valStr, 0, 40) . '...';
                                                    }
                                                    echo htmlspecialchars($valStr);
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- TAB 2: CREATE NEW TABLE (Interactive Visual GUI Builder) -->
<div id="tab-pane-create" class="tab-pane" style="display: <?= $activeTab === 'create' ? 'block' : 'none' ?>;">
    <div class="content-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h3 class="card-title">Visual Table Builder (Create New Table)</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Define table name and schema columns. The system will safely generate and run the <code>CREATE TABLE</code> statement.</p>
            </div>
            <button type="button" onclick="loadUserTableTemplate()" class="btn btn-secondary btn-sm">
                Load Sample Template (Custom Items)
            </button>
        </div>

        <form method="POST" action="database_manager.php" id="createTableForm">
            <input type="hidden" name="action" value="create_table">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">

            <div class="form-row" style="margin-bottom: 1.5rem;">
                <div class="form-group" style="max-width: 400px;">
                    <label class="form-label">Table Name <span style="color: var(--accent-red);">*</span></label>
                    <input type="text" name="table_name" id="newTableName" class="form-input" placeholder="e.g. notifications, user_bookmarks" required pattern="[a-zA-Z0-9_]+" style="font-family: monospace;">
                    <span style="font-size: 0.75rem; color: var(--text-dim);">Letters, numbers, and underscores only</span>
                </div>
                <div class="form-group" style="max-width: 250px;">
                    <label class="form-label">Storage Engine</label>
                    <input type="text" class="form-input" value="InnoDB (utf8mb4_unicode_ci)" readonly style="opacity: 0.7;">
                </div>
            </div>

            <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                <h4 style="font-size: 1rem; font-weight: 700; color: #fff;">Table Columns</h4>
                <button type="button" onclick="addColumnRow()" class="btn btn-secondary btn-sm">
                    + Add Column
                </button>
            </div>

            <div class="table-responsive" style="margin-bottom: 1.5rem;">
                <table class="data-table" id="columnsBuilderTable">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Column Name <span style="color: var(--accent-red);">*</span></th>
                            <th style="width: 18%;">Data Type</th>
                            <th style="width: 14%;">Length / Values</th>
                            <th style="width: 12%;">Nullable</th>
                            <th style="width: 16%;">Default Value</th>
                            <th style="width: 8%;">Primary</th>
                            <th style="width: 8%;">A_I</th>
                            <th style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="columnsContainer">
                        <!-- Initial Default Primary ID column -->
                        <tr class="col-row">
                            <td>
                                <input type="text" name="col_name[]" class="form-input" value="id" required style="font-family: monospace;" placeholder="column_name">
                            </td>
                            <td>
                                <select name="col_type[]" class="form-input">
                                    <option value="INT" selected>INT</option>
                                    <option value="BIGINT">BIGINT</option>
                                    <option value="VARCHAR">VARCHAR</option>
                                    <option value="TEXT">TEXT</option>
                                    <option value="LONGTEXT">LONGTEXT</option>
                                    <option value="DECIMAL">DECIMAL</option>
                                    <option value="TINYINT">TINYINT</option>
                                    <option value="DATETIME">DATETIME</option>
                                    <option value="TIMESTAMP">TIMESTAMP</option>
                                    <option value="JSON">JSON</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="col_length[]" class="form-input" value="11" placeholder="e.g. 255">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_nullable[]" value="1">
                            </td>
                            <td>
                                <input type="text" name="col_default[]" class="form-input" placeholder="NONE">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_primary[]" value="1" checked>
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_auto_inc[]" value="1" checked title="Auto Increment">
                            </td>
                            <td style="text-align: center;">
                                <button type="button" onclick="removeColumnRow(this)" class="btn-icon" style="color: var(--text-dim);" title="Remove Column">&times;</button>
                            </td>
                        </tr>

                        <!-- Initial Title column -->
                        <tr class="col-row">
                            <td>
                                <input type="text" name="col_name[]" class="form-input" value="title" required style="font-family: monospace;" placeholder="column_name">
                            </td>
                            <td>
                                <select name="col_type[]" class="form-input">
                                    <option value="INT">INT</option>
                                    <option value="BIGINT">BIGINT</option>
                                    <option value="VARCHAR" selected>VARCHAR</option>
                                    <option value="TEXT">TEXT</option>
                                    <option value="LONGTEXT">LONGTEXT</option>
                                    <option value="DECIMAL">DECIMAL</option>
                                    <option value="TINYINT">TINYINT</option>
                                    <option value="DATETIME">DATETIME</option>
                                    <option value="TIMESTAMP">TIMESTAMP</option>
                                    <option value="JSON">JSON</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="col_length[]" class="form-input" value="255" placeholder="e.g. 255">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_nullable[]" value="1">
                            </td>
                            <td>
                                <input type="text" name="col_default[]" class="form-input" placeholder="NONE">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_primary[]" value="1">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_auto_inc[]" value="1">
                            </td>
                            <td style="text-align: center;">
                                <button type="button" onclick="removeColumnRow(this)" class="btn-icon" style="color: var(--text-dim);" title="Remove Column">&times;</button>
                            </td>
                        </tr>

                        <!-- Initial Created_at column -->
                        <tr class="col-row">
                            <td>
                                <input type="text" name="col_name[]" class="form-input" value="created_at" required style="font-family: monospace;" placeholder="column_name">
                            </td>
                            <td>
                                <select name="col_type[]" class="form-input">
                                    <option value="INT">INT</option>
                                    <option value="BIGINT">BIGINT</option>
                                    <option value="VARCHAR">VARCHAR</option>
                                    <option value="TEXT">TEXT</option>
                                    <option value="LONGTEXT">LONGTEXT</option>
                                    <option value="DECIMAL">DECIMAL</option>
                                    <option value="TINYINT">TINYINT</option>
                                    <option value="DATETIME">DATETIME</option>
                                    <option value="TIMESTAMP" selected>TIMESTAMP</option>
                                    <option value="JSON">JSON</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="col_length[]" class="form-input" placeholder="-">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_nullable[]" value="1">
                            </td>
                            <td>
                                <input type="text" name="col_default[]" class="form-input" value="CURRENT_TIMESTAMP">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_primary[]" value="1">
                            </td>
                            <td style="text-align: center;">
                                <input type="checkbox" name="col_auto_inc[]" value="1">
                            </td>
                            <td style="text-align: center;">
                                <button type="button" onclick="removeColumnRow(this)" class="btn-icon" style="color: var(--text-dim);" title="Remove Column">&times;</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center;">
                <button type="submit" class="btn btn-primary">
                    <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    Create Table in Database
                </button>
                <button type="button" onclick="addColumnRow()" class="btn btn-secondary">
                    + Add Another Column
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TAB 3: ADD COLUMN TO EXISTING TABLE -->
<div id="tab-pane-column" class="tab-pane" style="display: <?= $activeTab === 'column' ? 'block' : 'none' ?>;">
    <div class="content-card" style="max-width: 750px;">
        <h3 class="card-title" style="margin-bottom: 0.5rem;">Add Column to Existing Table</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.5rem;">
            Executes a safe <code>ALTER TABLE `table` ADD COLUMN `col`...</code> migration.
        </p>

        <form method="POST" action="database_manager.php">
            <input type="hidden" name="action" value="add_column">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label">Target Table <span style="color: var(--accent-red);">*</span></label>
                <select name="target_table" id="targetTableSelect" class="form-input" required>
                    <option value="">-- Choose Existing Table --</option>
                    <?php foreach ($tablesList as $t): ?>
                        <option value="<?= htmlspecialchars($t['name']) ?>" <?= $inspectTable === $t['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?> (<?= number_format($t['rows']) ?> rows)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-row" style="margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label class="form-label">New Column Name <span style="color: var(--accent-red);">*</span></label>
                    <input type="text" name="col_name" class="form-input" placeholder="e.g. phone, vip_expires_at, score" required pattern="[a-zA-Z0-9_]+" style="font-family: monospace;">
                </div>
                <div class="form-group">
                    <label class="form-label">Data Type</label>
                    <select name="col_type" class="form-input">
                        <option value="VARCHAR" selected>VARCHAR (Text)</option>
                        <option value="INT">INT (Integer)</option>
                        <option value="BIGINT">BIGINT (Large Integer)</option>
                        <option value="TEXT">TEXT (Long content)</option>
                        <option value="LONGTEXT">LONGTEXT</option>
                        <option value="TINYINT">TINYINT (1 or 0 boolean)</option>
                        <option value="DECIMAL">DECIMAL (Price/Rating)</option>
                        <option value="DATETIME">DATETIME</option>
                        <option value="TIMESTAMP">TIMESTAMP</option>
                        <option value="JSON">JSON</option>
                    </select>
                </div>
            </div>

            <div class="form-row" style="margin-bottom: 1.25rem;">
                <div class="form-group">
                    <label class="form-label">Length / Precision</label>
                    <input type="text" name="col_length" class="form-input" placeholder="255 (or 10,2 for decimal)">
                </div>
                <div class="form-group">
                    <label class="form-label">Default Value</label>
                    <input type="text" name="col_default" class="form-input" placeholder="NONE, NULL, or default string">
                </div>
            </div>

            <div class="form-row" style="margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label">Position in Table</label>
                    <select name="col_position" class="form-input">
                        <option value="">At the End of Table</option>
                        <option value="FIRST">At the Very Beginning (FIRST)</option>
                        <option value="id">After `id`</option>
                    </select>
                </div>
                <div class="form-group" style="display: flex; align-items: center; padding-top: 1.75rem;">
                    <label class="checkbox-card" style="margin: 0; padding: 0.5rem 1rem;">
                        <input type="checkbox" name="col_nullable" value="1" checked>
                        <div class="label-content">
                            <strong>Allow NULL values</strong>
                        </div>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">
                Execute ALTER TABLE & Add Column
            </button>
        </form>
    </div>
</div>

<!-- TAB 4: SQL QUERY CONSOLE -->
<div id="tab-pane-console" class="tab-pane" style="display: <?= $activeTab === 'console' ? 'block' : 'none' ?>;">
    <div class="content-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
            <div>
                <h3 class="card-title">SQL Query Console & Migration Runner</h3>
                <p style="color: var(--text-muted); font-size: 0.85rem;">Run custom SQL queries, DDL modifications, or inspection statements directly.</p>
            </div>
            <!-- Quick Pre-made Snippets -->
            <div style="display: flex; gap: 0.5rem;">
                <button type="button" onclick="setConsoleQuery('SHOW TABLES;')" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                    SHOW TABLES
                </button>
                <button type="button" onclick="setConsoleQuery('SELECT * FROM app_users LIMIT 10;')" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                    SELECT app_users
                </button>
                <button type="button" onclick="setConsoleQuery('OPTIMIZE TABLE content_items, app_users, settings;')" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;">
                    OPTIMIZE TABLES
                </button>
            </div>
        </div>

        <form method="POST" action="database_manager.php">
            <input type="hidden" name="action" value="execute_sql">
            <input type="hidden" name="csrf" value="<?= $csrfToken ?>">

            <div class="form-group" style="margin-bottom: 1rem;">
                <textarea name="sql_query" id="sqlQueryInput" class="form-input" rows="6" style="font-family: 'Courier New', Courier, monospace; font-size: 0.9rem; line-height: 1.5; color: #a5b4fc; background: #0b0e17;" placeholder="e.g. CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);"><?= htmlspecialchars($executedSql) ?></textarea>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.8rem; color: var(--text-dim);">Queries are executed directly via PDO. Be cautious when executing DROP or DELETE queries.</span>
                <button type="submit" class="btn btn-primary">
                    <svg style="width: 16px; height: 16px; fill: currentColor; margin-right: 6px;" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    Run SQL Query
                </button>
            </div>
        </form>

        <!-- Display Query Results if any -->
        <?php if (!empty($queryResult)): ?>
            <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem;">
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin-bottom: 0.75rem;">Query Execution Result:</h4>
                <?php if ($queryResult['type'] === 'select'): ?>
                    <?php if (empty($queryResult['rows'])): ?>
                        <div class="alert alert-info">Query returned 0 rows.</div>
                    <?php else: ?>
                        <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                            <table class="data-table" style="font-size: 0.8rem; font-family: monospace;">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($queryResult['rows'][0]) as $k): ?>
                                            <th><?= htmlspecialchars($k) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queryResult['rows'] as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $v): ?>
                                                <td><?= htmlspecialchars((string)$v) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-success">
                        Query ran successfully in <?= $queryResult['time'] ?>ms. Affected rows: <?= $queryResult['affected'] ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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
.tab-btn:hover {
    color: #fff;
}
.tab-btn.active {
    color: var(--accent-red);
    border-bottom-color: var(--accent-red);
}
</style>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

    const pane = document.getElementById('tab-pane-' + tabId);
    const nav = document.getElementById('tab-nav-' + tabId);
    if (pane) pane.style.display = 'block';
    if (nav) nav.classList.add('active');
}

function addColumnRow() {
    const container = document.getElementById('columnsContainer');
    const tr = document.createElement('tr');
    tr.className = 'col-row';
    tr.innerHTML = `
        <td>
            <input type="text" name="col_name[]" class="form-input" required style="font-family: monospace;" placeholder="column_name">
        </td>
        <td>
            <select name="col_type[]" class="form-input">
                <option value="INT">INT</option>
                <option value="BIGINT">BIGINT</option>
                <option value="VARCHAR" selected>VARCHAR</option>
                <option value="TEXT">TEXT</option>
                <option value="LONGTEXT">LONGTEXT</option>
                <option value="DECIMAL">DECIMAL</option>
                <option value="TINYINT">TINYINT</option>
                <option value="DATETIME">DATETIME</option>
                <option value="TIMESTAMP">TIMESTAMP</option>
                <option value="JSON">JSON</option>
            </select>
        </td>
        <td>
            <input type="text" name="col_length[]" class="form-input" value="255" placeholder="e.g. 255">
        </td>
        <td style="text-align: center;">
            <input type="checkbox" name="col_nullable[]" value="1" checked>
        </td>
        <td>
            <input type="text" name="col_default[]" class="form-input" placeholder="NONE">
        </td>
        <td style="text-align: center;">
            <input type="checkbox" name="col_primary[]" value="1">
        </td>
        <td style="text-align: center;">
            <input type="checkbox" name="col_auto_inc[]" value="1">
        </td>
        <td style="text-align: center;">
            <button type="button" onclick="removeColumnRow(this)" class="btn-icon" style="color: var(--text-dim);" title="Remove Column">&times;</button>
        </td>
    `;
    container.appendChild(tr);
}

function removeColumnRow(btn) {
    const rows = document.querySelectorAll('#columnsContainer tr');
    if (rows.length <= 1) {
        alert('A table must have at least one column.');
        return;
    }
    btn.closest('tr').remove();
}

function prefillAddColumn(tableName) {
    switchTab('column');
    const select = document.getElementById('targetTableSelect');
    if (select) select.value = tableName;
}

function setConsoleQuery(sql) {
    document.getElementById('sqlQueryInput').value = sql;
}

function loadUserTableTemplate() {
    document.getElementById('newTableName').value = 'custom_favorites';
    const container = document.getElementById('columnsContainer');
    container.innerHTML = `
        <tr class="col-row">
            <td><input type="text" name="col_name[]" class="form-input" value="id" required style="font-family: monospace;"></td>
            <td><select name="col_type[]" class="form-input"><option value="INT" selected>INT</option></select></td>
            <td><input type="text" name="col_length[]" class="form-input" value="11"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_nullable[]" value="1"></td>
            <td><input type="text" name="col_default[]" class="form-input" placeholder="NONE"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_primary[]" value="1" checked></td>
            <td style="text-align: center;"><input type="checkbox" name="col_auto_inc[]" value="1" checked></td>
            <td style="text-align: center;"><button type="button" onclick="removeColumnRow(this)" class="btn-icon">&times;</button></td>
        </tr>
        <tr class="col-row">
            <td><input type="text" name="col_name[]" class="form-input" value="user_id" required style="font-family: monospace;"></td>
            <td><select name="col_type[]" class="form-input"><option value="INT" selected>INT</option></select></td>
            <td><input type="text" name="col_length[]" class="form-input" value="11"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_nullable[]" value="1"></td>
            <td><input type="text" name="col_default[]" class="form-input" placeholder="NONE"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_primary[]" value="1"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_auto_inc[]" value="1"></td>
            <td style="text-align: center;"><button type="button" onclick="removeColumnRow(this)" class="btn-icon">&times;</button></td>
        </tr>
        <tr class="col-row">
            <td><input type="text" name="col_name[]" class="form-input" value="content_id" required style="font-family: monospace;"></td>
            <td><select name="col_type[]" class="form-input"><option value="VARCHAR" selected>VARCHAR</option></select></td>
            <td><input type="text" name="col_length[]" class="form-input" value="64"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_nullable[]" value="1"></td>
            <td><input type="text" name="col_default[]" class="form-input" placeholder="NONE"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_primary[]" value="1"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_auto_inc[]" value="1"></td>
            <td style="text-align: center;"><button type="button" onclick="removeColumnRow(this)" class="btn-icon">&times;</button></td>
        </tr>
        <tr class="col-row">
            <td><input type="text" name="col_name[]" class="form-input" value="created_at" required style="font-family: monospace;"></td>
            <td><select name="col_type[]" class="form-input"><option value="TIMESTAMP" selected>TIMESTAMP</option></select></td>
            <td><input type="text" name="col_length[]" class="form-input" placeholder="-"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_nullable[]" value="1"></td>
            <td><input type="text" name="col_default[]" class="form-input" value="CURRENT_TIMESTAMP"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_primary[]" value="1"></td>
            <td style="text-align: center;"><input type="checkbox" name="col_auto_inc[]" value="1"></td>
            <td style="text-align: center;"><button type="button" onclick="removeColumnRow(this)" class="btn-icon">&times;</button></td>
        </tr>
    `;
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
