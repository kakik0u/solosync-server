<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Identifies one durable SyncPack history, independently of server config. */
final class HistoryIdentity
{
    public static function ensure(\PDO $db): string
    {
        $candidate = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO sync_history_identity(id,history_id) VALUES (1,?)
            ON DUPLICATE KEY UPDATE id=id')->execute([$candidate]);
        return self::current($db) ?? throw new \RuntimeException('Sync history identity is missing');
    }

    public static function current(\PDO $db): ?string
    {
        try {
            $value = $db->query('SELECT history_id FROM sync_history_identity WHERE id=1')->fetchColumn();
        } catch (\PDOException $error) {
            // A manually copied update may be serving before its migration ran.
            if ($error->getCode() !== '42S02') throw $error;
            return null;
        }
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) ? $value : null;
    }
}
