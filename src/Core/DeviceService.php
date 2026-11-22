<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class DeviceService
{
    public function __construct(private readonly \PDO $db) {}

    /** @return array{deviceId:string,revoked:bool} */
    public function revoke(string $deviceId): array
    {
        if ($deviceId === '' || strlen($deviceId) > 128) throw new ApiException(400, 'INVALID_DEVICE_ID', 'Invalid device ID');
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE sync_devices SET revoked_at=UTC_TIMESTAMP(6) WHERE device_id=? AND revoked_at IS NULL');
            $stmt->execute([$deviceId]);
            $revoked = $stmt->rowCount() > 0;
            $this->db->prepare('UPDATE sync_credentials SET revoked_at=UTC_TIMESTAMP(6) WHERE device_id=? AND revoked_at IS NULL')
                ->execute([$deviceId]);
            if ($revoked) {
                $payload = json_encode(['deviceId' => $deviceId], JSON_THROW_ON_ERROR);
                $this->db->prepare("INSERT INTO plugin_outbox(event_type,event_key,payload) VALUES ('device.revoked',?,?)")
                    ->execute([$deviceId, $payload]);
            }
            $this->db->commit();
            return ['deviceId' => $deviceId, 'revoked' => $revoked];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
