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
            if ($version === '002_migration_runner') continue;
            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Unable to read migration: ' . basename($file));
            $checksum = hash('sha256', $sql);
            $stmt = $this->db->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
            $stmt->execute([$version]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                if (!hash_equals((string)$existing, $checksum)) throw new RuntimeException('Migration checksum changed: ' . $version);
                continue;
            }
            $this->db->beginTransaction();
            try {
                $statements = $this->splitSql($sql);
                foreach ($statements as $statement) {
                    $trimmed = trim($statement);
                    if ($trimmed === '') continue;
                    $this->db->exec($trimmed);
                    if (!$this->db->inTransaction() && $trimmed !== end($statements)) {
                        $this->db->beginTransaction();
                    }
                }
                if ($this->db->inTransaction()) {
                    $this->db->commit();
                }
                $stmt = $this->db->prepare('INSERT INTO schema_migrations(version,description,checksum) VALUES(?,?,?)');
                $stmt->execute([$version, $version, $checksum]);
                $applied[] = $version;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    try { $this->db->rollBack(); } catch (Throwable $ignored) {}
                }
                throw new RuntimeException('Migration failed: ' . $version . ': ' . $e->getMessage(), 0, $e);
            }
        }
        return $applied;
    }
    private function splitSql(string $sql): array
    {
        $lines = explode("\n", $sql);
        $cleaned = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if (str_starts_with($trim, '--')) continue;
            $cleaned[] = $line;
        }
        $sql = implode("\n", $cleaned);
        $parts = preg_split('/;\s*[\r\n]+/', $sql);
        if ($parts === false) return [$sql];
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $result[] = $part;
        }
        return $result ?: [$sql];
    }
    private function ensureMetadataTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    }
    private function baselineLegacyPhase2IfPresent(): void
    {
        if (!$this->columnExists('users','transaction_pin_hash') || !$this->columnExists('transactions','idempotency_key') || !$this->tableExists('transaction_otp_challenges')) return;
        $version='001_phase2_consolidated';
        $stmt=$this->db->prepare('SELECT 1 FROM schema_migrations WHERE version=?');$stmt->execute([$version]);if($stmt->fetchColumn())return;
        $file=rtrim($this->directory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$version.'.sql';if(!is_file($file))return;
        $sql=file_get_contents($file);if($sql===false)return;
        $stmt=$this->db->prepare('INSERT INTO schema_migrations(version,description,checksum) VALUES(?,?,?)');$stmt->execute([$version,'Baseline existing Phase 2/2B schema',hash('sha256',$sql)]);
    }
    private function columnExists(string $table,string $column):bool{$stmt=$this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$stmt->execute([$table,$column]);return (int)$stmt->fetchColumn()>0;}
    private function tableExists(string $table):bool{$stmt=$this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$stmt->execute([$table]);return (int)$stmt->fetchColumn()>0;}
}