<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class SessionService
{
    public function __construct(private readonly \PDO $db) {}

    /** @param array<string,mixed> $input @return array{accessToken:string,groupId:string} */
    public function exchange(array $input): array
    {
        $connectionKey = $input['connectionKey'] ?? null;
        $deviceId = $input['deviceId'] ?? null;
        $deviceName = $input['deviceName'] ?? null;
        if (!is_string($connectionKey) || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $connectionKey)
            || !is_string($deviceId) || !preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $deviceId)
            || ($deviceName !== null && (!is_string($deviceName) || strlen($deviceName) > 160))) {
            throw new ApiException(400, 'INVALID_SESSION_REQUEST', 'Invalid session exchange request');
        }
        $providedGroup = null;
        if (array_key_exists('group', $input)) {
            if (!is_array($input['group'])) throw new ApiException(400, 'INVALID_GROUP_METADATA', 'Invalid Group metadata');
            $providedGroup = GroupMetadata::validate($input['group']);
        }
        $secretHash = hash('sha256', $connectionKey, true);

        $this->db->beginTransaction();
        try {
            $secret = $this->db->prepare('SELECT secret_hash FROM sync_connection_secrets
                WHERE secret_hash=? AND revoked_at IS NULL FOR UPDATE');
            $secret->execute([$secretHash]);
            if (!$secret->fetch()) throw new ApiException(401, 'INVALID_CONNECTION_KEY', 'Connection key is invalid or revoked');

            $instanceStmt = $this->db->query('SELECT group_id,group_metadata FROM sync_instance WHERE id=1 FOR UPDATE');
            $instance = $instanceStmt->fetch();
            if (!$instance) throw new ApiException(409, 'NOT_INSTALLED', 'Server instance is not initialized');
            $groupId = $instance['group_id'];
            if ($groupId === null) {
                if ($providedGroup === null) {
                    throw new ApiException(409, 'GROUP_METADATA_REQUIRED', 'Group metadata is required for the first binding');
                }
                $groupId = $providedGroup['groupId'];
                $encoded = json_encode($providedGroup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->db->prepare('UPDATE sync_instance SET group_id=?,group_metadata=?,generation=1,updated_at=UTC_TIMESTAMP(6) WHERE id=1')
                    ->execute([$groupId, $encoded]);
            } elseif ($providedGroup !== null) {
                $stored = json_decode((string)$instance['group_metadata'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($stored) || !GroupMetadata::sameIdentity($stored, $providedGroup)) {
                    throw new ApiException(409, 'INSTANCE_GROUP_MISMATCH', 'This server is bound to another Solosync Group');
                }
            }

            $this->db->prepare('INSERT INTO sync_devices(device_id,display_name,revoked_at)
                VALUES (?,?,NULL)
                ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),revoked_at=NULL,last_seen_at=UTC_TIMESTAMP(6)')
                ->execute([$deviceId, $deviceName]);
            $this->db->prepare('UPDATE sync_credentials SET revoked_at=UTC_TIMESTAMP(6)
                WHERE device_id=? AND revoked_at IS NULL')->execute([$deviceId]);
            $token = self::base64Url(random_bytes(32));
            $this->db->prepare('INSERT INTO sync_credentials(token_hash,device_id) VALUES (?,?)')
                ->execute([hash('sha256', $token, true), $deviceId]);
            $this->db->prepare('UPDATE sync_connection_secrets SET last_used_at=UTC_TIMESTAMP(6) WHERE secret_hash=?')
                ->execute([$secretHash]);
            $this->db->commit();
            return ['accessToken' => $token, 'groupId' => (string)$groupId];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        } finally {
            $connectionKey = '';
        }
    }

    /** @return array<string,mixed> */
    public function group(): array
    {
        $row = $this->db->query('SELECT group_metadata FROM sync_instance WHERE id=1')->fetch();
        if (!$row || !is_string($row['group_metadata'])) throw new ApiException(409, 'GROUP_NOT_BOUND', 'Server is not bound to a Group');
        $group = json_decode($row['group_metadata'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($group)) throw new \RuntimeException('Stored Group metadata is invalid');
        return GroupMetadata::validate($group);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
