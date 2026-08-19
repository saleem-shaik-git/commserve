<?php

declare(strict_types=1);

final class MigrationRunner
{
    public function __construct(private PDO $db, private string $directory) {}

    public function migrate(): array
    {
        $this->ensureMetadataTable();
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
                $this->db->exec($sql);
                $stmt = $this->db->prepare('INSERT INTO schema_migrations(version,description,checksum) VALUES(?,?,?)');
                $stmt->execute([$version, $version, $checksum]);
                $this->db->commit();
                $applied[] = $version;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw new RuntimeException('Migration failed: ' . $version . ': ' . $e->getMessage(), 0, $e);
            }
        }
        return $applied;
    }

    private function ensureMetadataTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, description VARCHAR(255) NOT NULL, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)');
    }
}
