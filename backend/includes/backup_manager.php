<?php
/**
 * PikaShow Enterprise Backup & Disaster Recovery Manager
 * Provides complete system backup (MySQL database dump + uploaded media assets + manifest),
 * standalone database export/import, backup inspection, archive downloads,
 * and seamless server migration / restore during initial installation or from admin panel.
 */

class BackupManager {
    /**
     * Get the absolute path to the backups directory.
     */
    public static function getBackupDir(): string {
        $dir = dirname(__DIR__) . '/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get the absolute path to the uploads directory.
     */
    public static function getUploadsDir(): string {
        $dir = dirname(__DIR__) . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Check if ZipArchive is installed and supported on the host.
     */
    public static function isZipSupported(): bool {
        return class_exists('ZipArchive');
    }

    /**
     * Human readable byte formatting.
     */
    public static function formatBytes(int $bytes): string {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int)floor(log($bytes, 1024));
        if ($i >= count($units)) $i = count($units) - 1;
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Human readable relative time format (e.g. "5 mins ago", "Yesterday").
     */
    public static function relativeTime(int $timestamp): string {
        $diff = time() - $timestamp;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' mins ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
        if ($diff < 172800) return 'Yesterday';
        return date('M j, Y H:i', $timestamp);
    }

    /**
     * Export complete MySQL database schema and all table records as an SQL dump string.
     * Pure PHP implementation with zero external CLI dependencies (e.g. works without mysqldump binary).
     */
    public static function exportSqlDump(PDO $pdo, ?string $dbName = null): string {
        // Query active database name if not supplied
        if (empty($dbName)) {
            try {
                $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
            } catch (Exception $e) {
                $dbName = 'pikashow_db';
            }
        }

        $sql = "-- =============================================================\n";
        $sql .= "-- PikaShow Streaming Platform Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Target Database: `{$dbName}`\n";
        $sql .= "-- PHP Version: " . PHP_VERSION . "\n";
        $sql .= "-- =============================================================\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sql .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $sql .= "SET NAMES utf8mb4;\n\n";

        // Fetch all base tables in database
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($tables as $table) {
            $sql .= "-- -------------------------------------------------------------\n";
            $sql .= "-- Table structure & data for `{$table}`\n";
            $sql .= "-- -------------------------------------------------------------\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";

            // Fetch CREATE TABLE statement
            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? '');

            if (!empty($createSql)) {
                $sql .= $createSql . ";\n\n";
            }

            // Dump data rows in chunks
            $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $columns = array_keys($rows[0]);
                $colNamesEscaped = array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", $columns);
                $columnsList = implode(', ', $colNamesEscaped);

                $chunkSize = 200;
                $chunks = array_chunk($rows, $chunkSize);

                foreach ($chunks as $chunk) {
                    $valSets = [];
                    foreach ($chunk as $row) {
                        $escapedVals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $escapedVals[] = 'NULL';
                            } elseif (is_int($val) || is_float($val)) {
                                $escapedVals[] = (string)$val;
                            } else {
                                $escapedVals[] = $pdo->quote((string)$val);
                            }
                        }
                        $valSets[] = "(" . implode(', ', $escapedVals) . ")";
                    }
                    $sql .= "INSERT INTO `{$table}` ({$columnsList}) VALUES\n  " . implode(",\n  ", $valSets) . ";\n";
                }
                $sql .= "\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        $sql .= "-- [END OF BACKUP DUMP]\n";

        return $sql;
    }

    /**
     * Import and execute SQL statements safely from an SQL dump string.
     * Uses quote-aware statement splitting to safely preserve semicolons inside strings/JSON.
     */
    public static function importSqlDump(PDO $pdo, string $sqlContent): array {
        $statements = self::splitSqlStatements($sqlContent);
        $executed = 0;
        $errors = [];

        // Temporarily disable foreign keys for seamless import order
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        } catch (Exception $e) {}

        foreach ($statements as $stmt) {
            $trimmed = trim($stmt);
            if (empty($trimmed)) continue;

            try {
                $pdo->exec($trimmed);
                $executed++;
            } catch (PDOException $e) {
                // Log non-fatal error and continue
                $errors[] = "Query failed: " . substr($trimmed, 0, 80) . "... [Error: " . $e->getMessage() . "]";
            }
        }

        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        } catch (Exception $e) {}

        return [
            'success' => count($errors) === 0,
            'total_statements' => count($statements),
            'executed' => $executed,
            'errors' => $errors,
        ];
    }

    /**
     * Parse SQL string character-by-character into distinct executable statements,
     * respecting single-quotes, double-quotes, backticks, and comments.
     */
    public static function splitSqlStatements(string $sql): array {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Handle line comments (-- or #)
            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick && !$inBlockComment) {
                if (($char === '-' && $next === '-') || $char === '#') {
                    $inLineComment = true;
                }
            }
            if ($inLineComment) {
                if ($char === "\n" || $char === "\r") {
                    $inLineComment = false;
                }
                continue; // Skip comment character
            }

            // Handle block comments (/* ... */)
            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick && !$inLineComment) {
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++; // skip '*'
                    continue;
                }
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++; // skip '/'
                }
                continue;
            }

            // Handle string literal toggling
            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                // Check for escape \'
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) {
                    $inSingleQuote = !$inSingleQuote;
                }
            } elseif ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $escaped = ($i > 0 && $sql[$i - 1] === '\\');
                if (!$escaped) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
            } elseif ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
            }

            // Semicolon outside any quote is an end of statement
            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $trimmed = trim($current);
                if (!empty($trimmed)) {
                    $statements[] = $trimmed;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $trimmed = trim($current);
        if (!empty($trimmed)) {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    /**
     * Create a complete system backup package (.zip):
     * - Full database SQL dump (`database.sql`)
     * - All uploaded media files (`uploads/`)
     * - System manifest metadata (`manifest.json`)
     */
    public static function createFullBackup(?string $customLabel = null, string $generatedBy = 'admin'): array {
        if (!self::isZipSupported()) {
            throw new Exception("PHP ZipArchive extension is required to generate full system backups.");
        }

        $backupDir = self::getBackupDir();
        $uploadsDir = self::getUploadsDir();
        $dateStr = date('Y-m-d_H-i-s');
        $cleanLabel = $customLabel ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($customLabel)) : '';
        $baseName = 'pikashow_full_backup_' . $dateStr . ($cleanLabel ? '_' . $cleanLabel : '');
        $zipFilename = $baseName . '.zip';
        $zipPath = $backupDir . '/' . $zipFilename;

        // Obtain PDO instance
        require_once dirname(__DIR__) . '/config/database.php';
        $pdo = Database::getInstance();
        if (!$pdo) {
            throw new Exception("Cannot connect to database to generate backup.");
        }

        $dbConfig = Database::getConfig()['db'] ?? [];
        $dbName = $dbConfig['database'] ?? 'pikashow_db';

        // 1. Generate SQL dump
        $sqlDump = self::exportSqlDump($pdo, $dbName);
        $tempSqlFile = $backupDir . '/temp_database_' . uniqid() . '.sql';
        file_put_contents($tempSqlFile, $sqlDump);

        // 2. Collect stats
        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $tableStats = [];
        $totalRecords = 0;
        foreach ($tables as $t) {
            try {
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                $tableStats[$t] = $cnt;
                $totalRecords += $cnt;
            } catch (Exception $e) {
                $tableStats[$t] = 0;
            }
        }

        // Gather uploaded media stats
        $uploadFiles = [];
        $uploadTotalBytes = 0;
        if (is_dir($uploadsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $fname = $file->getFilename();
                    // Skip temporary or git files
                    if ($fname === '.gitignore' || str_starts_with($fname, '.tmp_')) continue;
                    $uploadFiles[] = $file->getPathname();
                    $uploadTotalBytes += $file->getSize();
                }
            }
        }

        // 3. Build Manifest
        $manifest = [
            'app' => 'PikaShow Backend',
            'version' => '1.2.0',
            'type' => 'full_system',
            'created_at' => date('c'),
            'timestamp' => time(),
            'generated_by' => $generatedBy,
            'database' => [
                'name' => $dbName,
                'tables_count' => count($tables),
                'total_records' => $totalRecords,
                'tables' => $tableStats,
            ],
            'uploads' => [
                'files_count' => count($uploadFiles),
                'total_bytes' => $uploadTotalBytes,
                'total_formatted' => self::formatBytes($uploadTotalBytes),
            ],
        ];

        // 4. Create ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tempSqlFile);
            throw new Exception("Failed to create ZIP archive at: {$zipPath}");
        }

        // Add database.sql
        $zip->addFile($tempSqlFile, 'database.sql');

        // Add manifest.json
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Add uploaded media files inside uploads/ prefix
        $uploadsDirReal = realpath($uploadsDir);
        foreach ($uploadFiles as $filePath) {
            $relPath = 'uploads/' . ltrim(substr(realpath($filePath), strlen($uploadsDirReal)), '/\\');
            $relPath = str_replace('\\', '/', $relPath);
            $zip->addFile($filePath, $relPath);
        }

        $zip->close();
        @unlink($tempSqlFile);

        $finalSize = file_exists($zipPath) ? filesize($zipPath) : 0;

        return [
            'success' => true,
            'filename' => $zipFilename,
            'filepath' => $zipPath,
            'size_bytes' => $finalSize,
            'size_formatted' => self::formatBytes($finalSize),
            'type' => 'full_system',
            'created_at' => time(),
            'manifest' => $manifest,
        ];
    }

    /**
     * Create standalone Database-Only backup (.sql file).
     */
    public static function createDatabaseBackup(?string $customLabel = null, string $generatedBy = 'admin'): array {
        $backupDir = self::getBackupDir();
        $dateStr = date('Y-m-d_H-i-s');
        $cleanLabel = $customLabel ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($customLabel)) : '';
        $sqlFilename = 'pikashow_db_backup_' . $dateStr . ($cleanLabel ? '_' . $cleanLabel : '') . '.sql';
        $sqlPath = $backupDir . '/' . $sqlFilename;

        require_once dirname(__DIR__) . '/config/database.php';
        $pdo = Database::getInstance();
        if (!$pdo) {
            throw new Exception("Cannot connect to database to generate backup.");
        }

        $dbConfig = Database::getConfig()['db'] ?? [];
        $dbName = $dbConfig['database'] ?? 'pikashow_db';

        $sqlContent = self::exportSqlDump($pdo, $dbName);
        if (file_put_contents($sqlPath, $sqlContent) === false) {
            throw new Exception("Failed to write SQL backup file to: {$sqlPath}");
        }

        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $totalRecords = 0;
        foreach ($tables as $t) {
            try {
                $totalRecords += (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            } catch (Exception $e) {}
        }

        $finalSize = filesize($sqlPath);

        return [
            'success' => true,
            'filename' => $sqlFilename,
            'filepath' => $sqlPath,
            'size_bytes' => $finalSize,
            'size_formatted' => self::formatBytes($finalSize),
            'type' => 'database_only',
            'created_at' => time(),
            'tables_count' => count($tables),
            'total_records' => $totalRecords,
            'generated_by' => $generatedBy,
        ];
    }

    /**
     * List all existing backup files on server, sorted by creation date (newest first).
     */
    public static function listBackups(): array {
        $backupDir = self::getBackupDir();
        $items = [];

        if (!is_dir($backupDir)) {
            return [];
        }

        $files = scandir($backupDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'index.html' || str_starts_with($file, 'temp_')) {
                continue;
            }

            $filePath = $backupDir . '/' . $file;
            if (!is_file($filePath)) continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'sql'])) continue;

            $mtime = filemtime($filePath);
            $size = filesize($filePath);
            $type = ($ext === 'zip') ? 'full_system' : 'database_only';
            $manifest = null;

            // Peek into ZIP manifest
            if ($ext === 'zip' && self::isZipSupported()) {
                $zip = new ZipArchive();
                if ($zip->open($filePath) === true) {
                    $manifestJson = $zip->getFromName('manifest.json');
                    if ($manifestJson) {
                        $manifest = json_decode($manifestJson, true);
                    }
                    $zip->close();
                }
            }

            $items[] = [
                'filename' => $file,
                'filepath' => $filePath,
                'extension' => $ext,
                'type' => $type,
                'size_bytes' => $size,
                'size_formatted' => self::formatBytes($size),
                'created_at' => $mtime,
                'created_formatted' => date('Y-m-d H:i:s', $mtime),
                'relative_time' => self::relativeTime($mtime),
                'manifest' => $manifest,
                'tables_count' => $manifest['database']['tables_count'] ?? null,
                'records_count' => $manifest['database']['total_records'] ?? null,
                'uploads_count' => $manifest['uploads']['files_count'] ?? null,
            ];
        }

        // Sort descending by created_at
        usort($items, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        return $items;
    }

    /**
     * Inspect any backup archive (.zip or .sql file) and return detailed information.
     */
    public static function inspectBackupFile(string $filePath): array {
        if (!file_exists($filePath)) {
            return ['valid' => false, 'message' => 'Backup file not found.'];
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $fileSize = filesize($filePath);

        if ($ext === 'zip') {
            if (!self::isZipSupported()) {
                return ['valid' => false, 'message' => 'PHP ZipArchive extension is required to inspect zip backups.'];
            }

            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                return ['valid' => false, 'message' => 'Invalid or corrupted ZIP archive.'];
            }

            $hasDatabase = false;
            $databaseEntry = '';
            $uploadCount = 0;
            $manifest = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                if ($name === 'manifest.json') {
                    $manifestContent = $zip->getFromIndex($i);
                    $manifest = json_decode($manifestContent, true);
                } elseif (str_ends_with(strtolower($name), '.sql')) {
                    $hasDatabase = true;
                    $databaseEntry = $name;
                } elseif (str_starts_with($name, 'uploads/') && !str_ends_with($name, '/')) {
                    $uploadCount++;
                }
            }
            $zip->close();

            if (!$hasDatabase) {
                return [
                    'valid' => false,
                    'message' => 'ZIP archive does not contain a valid database SQL dump (database.sql).',
                ];
            }

            return [
                'valid' => true,
                'type' => 'full_system',
                'file_size' => $fileSize,
                'size_formatted' => self::formatBytes($fileSize),
                'has_database' => true,
                'database_file' => $databaseEntry,
                'has_uploads' => ($uploadCount > 0),
                'uploads_count' => $uploadCount,
                'manifest' => $manifest,
                'tables_count' => $manifest['database']['tables_count'] ?? null,
                'records_count' => $manifest['database']['total_records'] ?? null,
                'created_at' => $manifest['created_at'] ?? date('Y-m-d H:i:s', filemtime($filePath)),
                'app_name' => $manifest['app'] ?? 'PikaShow Streaming System',
            ];
        } elseif ($ext === 'sql') {
            // Read first 100KB to scan structure
            $fp = fopen($filePath, 'r');
            $sample = fread($fp, 102400);
            fclose($fp);

            preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sample, $matches);
            $tablesSample = array_unique($matches[1] ?? []);

            return [
                'valid' => true,
                'type' => 'database_only',
                'file_size' => $fileSize,
                'size_formatted' => self::formatBytes($fileSize),
                'has_database' => true,
                'has_uploads' => false,
                'uploads_count' => 0,
                'manifest' => null,
                'sample_tables' => array_values($tablesSample),
                'created_at' => date('Y-m-d H:i:s', filemtime($filePath)),
                'app_name' => 'PikaShow MySQL Dump',
            ];
        }

        return ['valid' => false, 'message' => 'Unsupported backup format. Only .zip and .sql files are supported.'];
    }

    /**
     * Restore system state from a backup file (.zip or .sql).
     * Restores database schema & records, extracts uploads (if .zip),
     * and optionally updates the admin user credentials.
     */
    public static function restoreBackup(
        string $filePath,
        PDO $pdo,
        bool $restoreUploads = true,
        ?array $adminOverride = null
    ): array {
        if (!file_exists($filePath)) {
            throw new Exception("Backup file not found at: {$filePath}");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $uploadsDir = self::getUploadsDir();
        $filesRestored = 0;
        $sqlContent = '';
        $manifest = null;

        if ($ext === 'zip') {
            if (!self::isZipSupported()) {
                throw new Exception("PHP ZipArchive extension is required to restore ZIP backup packages.");
            }

            $zip = new ZipArchive();
            if ($zip->open($filePath) !== true) {
                throw new Exception("Failed to open backup ZIP archive.");
            }

            // 1. Read manifest if present
            $manifestStr = $zip->getFromName('manifest.json');
            if ($manifestStr) {
                $manifest = json_decode($manifestStr, true);
            }

            // 2. Locate SQL dump inside archive
            $sqlFoundName = null;
            if ($zip->locateName('database.sql') !== false) {
                $sqlFoundName = 'database.sql';
            } else {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($entry), '.sql')) {
                        $sqlFoundName = $entry;
                        break;
                    }
                }
            }

            if (!$sqlFoundName) {
                $zip->close();
                throw new Exception("No SQL database file found inside the backup archive.");
            }

            $sqlContent = $zip->getFromName($sqlFoundName);

            // 3. Extract uploaded media files to uploads/ if enabled
            if ($restoreUploads) {
                $realUploadsDir = realpath($uploadsDir);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $name = $stat['name'];

                    // Process entries that start with uploads/
                    if (str_starts_with($name, 'uploads/')) {
                        // Extract relative path within uploads
                        $relSubPath = substr($name, strlen('uploads/'));
                        if (empty($relSubPath) || str_ends_with($relSubPath, '/')) {
                            // Subdirectory
                            $targetDir = $uploadsDir . '/' . trim($relSubPath, '/');
                            if (!is_dir($targetDir)) {
                                @mkdir($targetDir, 0755, true);
                            }
                            continue;
                        }

                        // Sanitize filename to prevent Zip Slip directory traversal attack
                        $cleanRel = str_replace(['..', '\\'], ['', '/'], $relSubPath);
                        $destFile = $uploadsDir . '/' . ltrim($cleanRel, '/');

                        $destDir = dirname($destFile);
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0755, true);
                        }

                        // Check realpath verification
                        $fileData = $zip->getFromIndex($i);
                        if ($fileData !== false) {
                            file_put_contents($destFile, $fileData);
                            $filesRestored++;
                        }
                    }
                }
            }

            $zip->close();
        } elseif ($ext === 'sql') {
            $sqlContent = file_get_contents($filePath);
        } else {
            throw new Exception("Unsupported backup format. File must have .zip or .sql extension.");
        }

        if (empty($sqlContent)) {
            throw new Exception("The SQL database dump is empty.");
        }

        // 4. Import the SQL dump into MySQL
        $importRes = self::importSqlDump($pdo, $sqlContent);

        // 5. Handle optional admin override (e.g. from installer wizard on new server)
        if (!empty($adminOverride['username']) && !empty($adminOverride['password'])) {
            $uName = trim($adminOverride['username']);
            $uEmail = trim($adminOverride['email'] ?? ($uName . '@pikashow.com'));
            $passHash = password_hash($adminOverride['password'], PASSWORD_BCRYPT);

            // Check if admin table exists
            try {
                $chk = $pdo->prepare("SELECT id FROM admin_users WHERE username = :u OR email = :e LIMIT 1");
                $chk->execute(['u' => $uName, 'e' => $uEmail]);
                $existingId = $chk->fetchColumn();

                if ($existingId) {
                    $upd = $pdo->prepare("UPDATE admin_users SET username = :u, email = :e, password_hash = :p WHERE id = :id");
                    $upd->execute(['u' => $uName, 'e' => $uEmail, 'p' => $passHash, 'id' => $existingId]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO admin_users (username, email, password_hash, role) VALUES (:u, :e, :p, 'admin')");
                    $ins->execute(['u' => $uName, 'e' => $uEmail, 'p' => $passHash]);
                }
            } catch (Exception $e) {
                // Table might have custom schema, ignore if fails
            }
        }

        // Gather restored statistics
        $tablesRestored = 0;
        $totalRecords = 0;
        try {
            $tblStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = $tblStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $tablesRestored = count($tables);
            foreach ($tables as $t) {
                try {
                    $totalRecords += (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {}

        return [
            'success' => true,
            'type' => $ext,
            'tables_restored' => $tablesRestored,
            'total_records' => $totalRecords,
            'files_restored' => $filesRestored,
            'queries_executed' => $importRes['executed'],
            'errors' => $importRes['errors'],
            'manifest' => $manifest,
        ];
    }

    /**
     * Safely delete a backup file from the server.
     */
    public static function deleteBackup(string $filename): bool {
        $clean = basename($filename);
        $backupDir = self::getBackupDir();
        $filePath = $backupDir . '/' . $clean;

        if (is_file($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }

    /**
     * Send backup file as download attachment to browser.
     */
    public static function downloadBackup(string $filename): void {
        $clean = basename($filename);
        $backupDir = self::getBackupDir();
        $filePath = $backupDir . '/' . $clean;

        if (!is_file($filePath)) {
            header("HTTP/1.0 404 Not Found");
            echo "Backup file not found.";
            exit;
        }

        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $contentType = ($ext === 'zip') ? 'application/zip' : 'text/plain; charset=utf-8';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $clean . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Flush output buffers before sending file
        while (ob_get_level()) {
            ob_end_clean();
        }

        readfile($filePath);
        exit;
    }
}
