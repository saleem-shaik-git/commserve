<?php
require_once __DIR__ . '/bootstrap.php';

final class Database {
    private static ?PDO $pdo = null;
    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST','127.0.0.1'), env('DB_PORT','3306'), env('DB_NAME','commserve'));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
        }
        self::$pdo = new PDO($dsn, (string)env('DB_USER','root'), (string)env('DB_PASS',''), $options);
        self::ensureRequiredTables(self::$pdo);
        return self::$pdo;
    }

    /**
     * Apply pending migrations when Phase 5 tables are missing (common after importing schema.sql only).
     */
    private static function ensureRequiredTables(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute(['billers']);
            if ((int)$stmt->fetchColumn() > 0) {
                return;
            }
            require_once __DIR__ . '/Database/MigrationRunner.php';
            (new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'))->migrate();
        } catch (Throwable $e) {
            error_log('CommServe auto-migrate: ' . $e->getMessage());
            self::applyPhase5SafetyNet($pdo);
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(['billers']);
        if ((int)$stmt->fetchColumn() === 0) {
            self::applyPhase5SafetyNet($pdo);
        }
        self::ensureOptionalColumn($pdo, 'beneficiaries', 'nickname', 'VARCHAR(80) NULL');
    }

    private static function ensureOptionalColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
        }
    }

    private static function applyPhase5SafetyNet(PDO $pdo): void
    {
        $file = dirname(__DIR__) . '/database/migrations/007_ensure_phase5_tables.sql';
        if (!is_file($file)) {
            return;
        }
        $sql = file_get_contents($file);
        if ($sql === false || $sql === '') {
            return;
        }
        $pdo->exec($sql);
    }
}
