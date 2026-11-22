<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class AdminService
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        if (!Database::isInstalled($this->db)) return ['installed' => false];
        $instance = $this->db->query('SELECT group_id,generation,head_sequence,used_bytes,quota_bytes FROM sync_instance WHERE id=1')->fetch();
        return [
            'installed' => true,
            'bound' => is_array($instance) && $instance['group_id'] !== null,
            'groupId' => is_array($instance) ? $instance['group_id'] : null,
            'generation' => is_array($instance) ? (string)$instance['generation'] : '1',
            'headSequence' => is_array($instance) ? (string)$instance['head_sequence'] : '0',
            'usedBytes' => is_array($instance) ? (string)$instance['used_bytes'] : '0',
            'quotaBytes' => is_array($instance) ? (string)$instance['quota_bytes'] : '0',
            'devices' => (int)$this->db->query('SELECT COUNT(*) FROM sync_devices WHERE revoked_at IS NULL')->fetchColumn(),
            'packs' => (int)$this->db->query('SELECT COUNT(*) FROM sync_packs')->fetchColumn(),
            'connectionKeys' => (int)$this->db->query('SELECT COUNT(*) FROM sync_connection_secrets WHERE revoked_at IS NULL')->fetchColumn(),
            'pendingPluginEvents' => (int)$this->db->query('SELECT COUNT(*) FROM plugin_outbox WHERE delivered_at IS NULL')->fetchColumn(),
        ];
    }

    /** @return list<string> */
    public function install(string $migrationsPath): array
    {
        $applied = (new MigrationRunner($this->db, $migrationsPath))->run();
        $quota = $this->config->int('quota_bytes', 1024 * 1024, PHP_INT_MAX);
        $stmt = $this->db->prepare('INSERT INTO sync_instance(id,quota_bytes) VALUES (1,?)
            ON DUPLICATE KEY UPDATE quota_bytes=VALUES(quota_bytes)');
        $stmt->execute([$quota]);
        return $applied;
    }

    /** @return array{key:string,id:string,label:string} */
    public function createConnectionKey(string $label): array
    {
        if (!Database::isInstalled($this->db)) throw new ApiException(409, 'NOT_INSTALLED', 'Install the server first');
        $label = trim($label);
        if ($label === '' || strlen($label) > 120) throw new ApiException(400, 'INVALID_LABEL', 'Invalid connection key label');
        $raw = self::base64Url(random_bytes(32));
        $hash = hash('sha256', $raw, true);
        $id = bin2hex(substr(hash('sha256', $raw, true), 0, 8));
        $this->db->prepare('INSERT INTO sync_connection_secrets(secret_hash,secret_id,label) VALUES (?,?,?)')
            ->execute([$hash, $id, $label]);
        return ['key' => $raw, 'id' => $id, 'label' => $label];
    }

    /** @return list<array<string,mixed>> */
    public function connectionKeys(): array
    {
        $rows = $this->db->query('SELECT secret_id,label,created_at,last_used_at,revoked_at
            FROM sync_connection_secrets ORDER BY created_at DESC')->fetchAll();
        return array_map(static fn(array $row): array => [
            'id' => (string)$row['secret_id'],
            'label' => (string)$row['label'],
            'createdAt' => (string)$row['created_at'],
            'lastUsedAt' => $row['last_used_at'],
            'revokedAt' => $row['revoked_at'],
        ], $rows);
    }

    public function revokeConnectionKey(string $id): bool
    {
        if (!preg_match('/^[0-9a-f]{16}$/D', $id)) throw new ApiException(400, 'INVALID_KEY_ID', 'Invalid connection key ID');
        $stmt = $this->db->prepare('UPDATE sync_connection_secrets SET revoked_at=UTC_TIMESTAMP(6)
            WHERE secret_id=? AND revoked_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
