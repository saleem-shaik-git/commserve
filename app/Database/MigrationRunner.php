<?php

declare(strict_types=1);

final class MigrationRunner
{
    public function __construct(private PDO $db, private string $directory) {}

    public function migrate(): array
    {
        $this->ensureMetadataTable();
        $this->baselineLegacyPhase2IfPresent();
        $files = glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);
        $applied = [];
        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            if ($version === '002_migration_runner') {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration: ' . basename($file));
            }
            $checksum = hash('sha256', $sql);
            $stmt = $this->db->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
            $stmt->execute([$version]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals((string)$existing, $checksum)) {
                    // Allow repaired files to replace a never-completed / failed apply
                    // (Phase 5 repairs; 010 rewritten for MySQL 8 portability).
                    if (!in_array($version, ['005_payments_beneficiaries', '006_phase5_payment_hardening', '010_phase8_notifications_schema_repair'], true)) {
                        throw new RuntimeException('Migration checksum changed: ' . $version);
                    }
                    $this->db->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([$version]);
                } else {
                    continue;
                }
            }
            try {
                $this->execStatements($sql);
                $stmt = $this->db->prepare('INSERT INTO schema_migrations(version,description,checksum) VALUES(?,?,?)');
                $stmt->execute([$version, $version, $checksum]);
                $applied[] = $version;
            } catch (Throwable $e) {
                throw new RuntimeException('Migration failed: ' . $version . ': ' . $e->getMessage(), 0, $e);
            }
        }
        return $applied;
    }

    /**
     * Run statements one at a time. Duplicate column/table/key is treated as already applied.
     */
    private function execStatements(string $sql): void
    {
        foreach ($this->splitStatements($sql) as $statement) {
            try {
                $this->db->exec($statement);
            } catch (PDOException $e) {
                if ($this->isAlreadyExists($e)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*[\r\n]+/', $sql) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            $part = rtrim($part, "; \t\n\r\0\x0B");
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    private function isAlreadyExists(PDOException $e): bool
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        // 1050 table exists, 1060 duplicate column, 1061 duplicate key name, 1022/1062 duplicate key
        return in_array($code, [1050, 1060, 1061, 1022, 1062], true)
            || str_contains(strtolower($e->getMessage()), 'duplicate column')
            || str_contains(strtolower($e->getMessage()), 'already exists');
    }

    private function ensureMetadataTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    }

    private function baselineLegacyPhase2IfPresent(): void
    {
        if (!$this->columnExists('users', 'transaction_pin_hash') || !$this->columnExists('transactions', 'idempotency_key') || !$this->tableExists('transaction_otp_challenges')) {
            return;
        }
        $version = '001_phase2_consolidated';
        $stmt = $this->db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');
        $stmt->execute([$version]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $file = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $version . '.sql';
        if (!is_file($file)) {
            return;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO schema_migrations(version,description,checksum) VALUES(?,?,?)');
        $stmt->execute([$version, 'Baseline existing Phase 2/2B schema', hash('sha256', $sql)]);
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
