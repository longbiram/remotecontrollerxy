<?php
/**
 * Database connection manager for PikaShow Backend
 */

class Database {
    private static ?PDO $instance = null;
    private static ?array $config = null;

    public static function getConfigFile(): string {
        return __DIR__ . '/config.php';
    }

    public static function isInstalled(): bool {
        $configFile = self::getConfigFile();
        if (!file_exists($configFile)) {
            return false;
        }
        $config = require $configFile;
        if (!is_array($config) || empty($config['db']['database'])) {
            return false;
        }
        return true;
    }

    public static function getConfig(): array {
        if (self::$config === null) {
            $configFile = self::getConfigFile();
            if (file_exists($configFile)) {
                self::$config = require $configFile;
            } else {
                self::$config = [];
            }
        }
        return self::$config;
    }

    public static function getInstance(): ?PDO {
        if (self::$instance === null) {
            if (!self::isInstalled()) {
                return null;
            }
            $config = self::getConfig()['db'];
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 3306;
            $db   = $config['database'] ?? '';
            $user = $config['username'] ?? 'root';
            $pass = $config['password'] ?? '';
            $charset = $config['charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log('Database Connection Error: ' . $e->getMessage());
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    public static function testConnection(string $host, int $port, string $user, string $pass, ?string $database = null): array {
        try {
            $dsn = "mysql:host={$host};port={$port}";
            if (!empty($database)) {
                $dsn .= ";dbname={$database}";
            }
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            return ['success' => true, 'message' => 'Connection successful!'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
