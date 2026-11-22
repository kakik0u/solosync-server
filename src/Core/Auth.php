<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class Auth
{
    public function __construct(private readonly \PDO $db) {}

    /** @return array{device_id:string,group_id:string} */
    public function requireCredential(?string $header = null): array
    {
        $header ??= (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D', $header, $match)) {
            throw new ApiException(401, 'AUTH_REQUIRED', 'Authentication required');
        }
        $hash = hash('sha256', $match[1], true);
        $stmt = $this->db->prepare('SELECT c.device_id,i.group_id
            FROM sync_credentials c
            JOIN sync_devices d ON d.device_id=c.device_id
            JOIN sync_instance i ON i.id=1
            WHERE c.token_hash=? AND c.revoked_at IS NULL AND d.revoked_at IS NULL AND i.group_id IS NOT NULL
            LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!$row) throw new ApiException(401, 'AUTH_REQUIRED', 'Credential is invalid or revoked');
        $this->db->prepare('UPDATE sync_credentials SET last_used_at=UTC_TIMESTAMP(6) WHERE token_hash=?')
            ->execute([$hash]);
        return ['device_id' => (string)$row['device_id'], 'group_id' => (string)$row['group_id']];
    }
}
