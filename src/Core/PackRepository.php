<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class PackRepository
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /** @return array{items:list<array{remoteId:string,packId:string,digest:string}>,nextCursor:string,hasMore:bool} */
    public function list(string $after, int $limit): array
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,19})$/D', $after)) throw new ApiException(400, 'INVALID_CURSOR', 'Invalid pack cursor');
        $maximum = $this->config->int('max_page_size', 1, 1000);
        if ($limit < 1 || $limit > $maximum) throw new ApiException(400, 'INVALID_LIMIT', 'Invalid page limit');
        $stmt = $this->db->prepare('SELECT pack_id,digest,sequence FROM sync_packs
            WHERE sequence > ? ORDER BY sequence ASC LIMIT ' . ($limit + 1));
        $stmt->execute([$after]);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $next = $after;
        $items = [];
        foreach ($rows as $row) {
            $next = (string)$row['sequence'];
            $items[] = [
                'remoteId' => (string)$row['pack_id'],
                'packId' => (string)$row['pack_id'],
                'digest' => (string)$row['digest'],
            ];
        }
        return ['items' => $items, 'nextCursor' => $next, 'hasMore' => $hasMore];
    }

    public function get(string $packId): string
    {
        $this->validatePackId($packId);
        $stmt = $this->db->prepare('SELECT bytes FROM sync_packs WHERE pack_id=?');
        $stmt->execute([$packId]);
        $bytes = $stmt->fetchColumn();
        if (!is_string($bytes)) throw new ApiException(404, 'PACK_NOT_FOUND', 'Pack not found');
        return $bytes;
    }

    /** @return array{created:bool,remoteId:string,packId:string,digest:string,sequence:string} */
    public function put(string $packId, string $bytes, string $declaredDigest, string $authenticatedDeviceId): array
    {
        $this->validatePackId($packId);
        if (!preg_match('/^[0-9a-f]{64}$/D', $declaredDigest)) throw new ApiException(400, 'INVALID_DIGEST', 'Invalid pack digest');
        $maximum = $this->config->int('max_pack_bytes', 1024, 64 * 1024 * 1024);
        if (strlen($bytes) > $maximum) throw new ApiException(413, 'PACK_TOO_LARGE', 'Pack exceeds server limit');
        $digest = hash('sha256', $bytes);
        if (!hash_equals($declaredDigest, $digest)) throw new ApiException(400, 'DIGEST_MISMATCH', 'Pack digest mismatch');

        $instance = $this->db->query('SELECT group_id FROM sync_instance WHERE id=1')->fetch();
        $groupId = is_array($instance) ? $instance['group_id'] : null;
        if (!is_string($groupId)) throw new ApiException(409, 'GROUP_NOT_BOUND', 'Server is not bound to a Group');
        $pack = SyncPackValidator::decode($bytes, $packId, $groupId);
        if (!hash_equals($authenticatedDeviceId, (string)$pack['uploaderDeviceId'])) {
            throw new ApiException(403, 'UPLOADER_MISMATCH', 'Pack uploader does not match the authenticated device');
        }

        $this->db->beginTransaction();
        try {
            $stateStmt = $this->db->query('SELECT head_sequence,used_bytes,quota_bytes FROM sync_instance WHERE id=1 FOR UPDATE');
            $state = $stateStmt->fetch();
            if (!$state) throw new ApiException(409, 'NOT_INSTALLED', 'Server instance is not initialized');

            $existingStmt = $this->db->prepare('SELECT digest,bytes,sequence FROM sync_packs WHERE pack_id=?');
            $existingStmt->execute([$packId]);
            $existing = $existingStmt->fetch();
            if ($existing) {
                if (!hash_equals((string)$existing['digest'], $digest) || !hash_equals((string)$existing['bytes'], $bytes)) {
                    throw new ApiException(409, 'PACK_CONFLICT', 'Pack ID already exists with different content');
                }
                $this->db->commit();
                return ['created' => false, 'remoteId' => $packId, 'packId' => $packId,
                    'digest' => $digest, 'sequence' => (string)$existing['sequence']];
            }

            $byteLength = strlen($bytes);
            if ((int)$state['used_bytes'] + $byteLength > (int)$state['quota_bytes']) {
                throw new ApiException(507, 'QUOTA_EXCEEDED', 'Solosync server quota exceeded');
            }
            $sequence = (int)$state['head_sequence'] + 1;
            $this->db->prepare('INSERT INTO sync_packs(pack_id,sequence,digest,byte_length,uploader_device_id,bytes)
                VALUES (?,?,?,?,?,?)')->execute([$packId, $sequence, $digest, $byteLength, $authenticatedDeviceId, $bytes]);
            $this->db->prepare('UPDATE sync_instance SET head_sequence=?,used_bytes=used_bytes+?,updated_at=UTC_TIMESTAMP(6) WHERE id=1')
                ->execute([$sequence, $byteLength]);
            $this->queueEvent('pack.stored', $packId, [
                'packId' => $packId, 'digest' => $digest, 'sequence' => (string)$sequence,
                'uploaderDeviceId' => $authenticatedDeviceId, 'byteLength' => $byteLength,
            ]);
            $this->queueEvent('operations.stored', $packId, [
                'packId' => $packId, 'uploaderDeviceId' => $authenticatedDeviceId,
                'operations' => $pack['operations'],
            ]);
            $this->db->commit();
            return ['created' => true, 'remoteId' => $packId, 'packId' => $packId,
                'digest' => $digest, 'sequence' => (string)$sequence];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $payload */
    private function queueEvent(string $type, string $key, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->prepare('INSERT INTO plugin_outbox(event_type,event_key,payload) VALUES (?,?,?)')
            ->execute([$type, $key, $json]);
    }

    private function validatePackId(string $packId): void
    {
        if ($packId === '' || strlen($packId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/D', $packId)) {
            throw new ApiException(400, 'INVALID_PACK_ID', 'Invalid pack ID');
        }
    }
}
