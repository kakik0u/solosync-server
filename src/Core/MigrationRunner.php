<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class MigrationRunner
{
    private const LOCK_NAME = 'solosync_v2_schema_migration';

    public function __construct(private readonly \PDO $db, private readonly string $migrationsPath) {}

    /** @return list<string> */
    public function run(): array
    {
        $this->acquireLock();
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
              version VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
              checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB");
            $paths = glob(rtrim($this->migrationsPath, DIRECTORY_SEPARATOR) . '/*.sql') ?: [];
            sort($paths, SORT_STRING);
            $applied = [];
            foreach ($paths as $path) {
                if ($this->applyFile($path)) $applied[] = basename($path);
            }
            return $applied;
        } finally {
            $this->releaseLock();
        }
    }

    private function applyFile(string $path): bool
    {
        $version = basename($path);
        $sql = file_get_contents($path);
        if (!is_string($sql)) throw new \RuntimeException("Cannot read migration {$version}");
        $checksum = hash('sha256', $sql);
        $stmt = $this->db->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
        $stmt->execute([$version]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            if (!hash_equals((string)$existing, $checksum)) {
                throw new \RuntimeException("Migration checksum mismatch: {$version}");
            }
            return false;
        }
        foreach ($this->statements($sql) as $statement) $this->db->exec($statement);
        $this->db->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)')
            ->execute([$version, $checksum]);
        return true;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
    }

    private function acquireLock(): void
    {
        $stmt = $this->db->query("SELECT GET_LOCK('" . self::LOCK_NAME . "',30)");
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('Could not acquire migration lock');
    }

    private function releaseLock(): void
    {
        try { $this->db->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')"); }
        catch (\Throwable) { /* connection teardown releases the lock */ }
    }
}
