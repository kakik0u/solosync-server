<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Applies versioned SQL belonging to one plugin or a shared capability. */
final class ScopedMigrationRunner
{
    public function __construct(private readonly \PDO $db) {}

    /** @return list<string> */
    public function run(string $scope, string $path): array
    {
        if (!preg_match('/^@?[a-z][a-z0-9_-]{0,62}$/D', $scope)) throw new \InvalidArgumentException('Invalid migration scope');
        $locked = (int)$this->db->query("SELECT GET_LOCK('solosync_plugin_schema',30)")->fetchColumn() === 1;
        if (!$locked) throw new \RuntimeException('Could not acquire plugin migration lock');
        try {
            $paths = glob(rtrim($path, DIRECTORY_SEPARATOR) . '/*.sql') ?: [];
            sort($paths, SORT_STRING);
            $applied = [];
            foreach ($paths as $file) {
                $version = basename($file);
                $sql = file_get_contents($file);
                if (!is_string($sql)) throw new \RuntimeException('Cannot read plugin migration: ' . $version);
                $checksum = hash('sha256', $sql);
                $stmt = $this->db->prepare('SELECT checksum FROM plugin_schema_migrations WHERE plugin_id=? AND version=?');
                $stmt->execute([$scope, $version]);
                $previous = $stmt->fetchColumn();
                if ($previous !== false) {
                    if (!hash_equals((string)$previous, $checksum)) throw new \RuntimeException('Plugin migration checksum mismatch: ' . $scope . '/' . $version);
                    continue;
                }
                foreach (self::statements($sql) as $statement) $this->db->exec($statement);
                $this->db->prepare('INSERT INTO plugin_schema_migrations(plugin_id,version,checksum) VALUES (?,?,?)')
                    ->execute([$scope, $version, $checksum]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK('solosync_plugin_schema')"); }
            catch (\Throwable) { /* connection teardown also releases the lock */ }
        }
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
    }
}
