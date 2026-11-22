<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Creates ordinary SyncPacks on behalf of trusted server plugins. */
final class ServerPackWriter
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /**
     * Both callbacks run in the same transaction as the new pack. A before callback may
     * return an existing result to make an external request idempotent.
     * @param null|callable(PluginStorage):?array<string,mixed> $before
     * @param null|callable(PluginStorage,array<string,mixed>):void $after
     * @param list<array<string,mixed>> $embeds Solocord RichEmbed v1 payloads
     * @return array<string,mixed>
     */
    public function createMessage(PluginStorage $storage, string $channelId, string $content,
        ?callable $before = null, ?callable $after = null, array $embeds = []): array
    {
        if (!array_is_list($embeds) || count($embeds) > 16) RichEmbed::invalid('embeds', 'Expected at most 16 embeds');
        foreach ($embeds as $i => $embed) $embeds[$i] = RichEmbed::normalize($embed, 'embeds[' . $i . ']');
        if ($channelId === '' || strlen($channelId) > 255 || ($content === '' && $embeds === []) || strlen($content) > 32768) {
            throw new ApiException(400, 'INVALID_MESSAGE', 'Invalid message content or channel');
        }
        if ($this->db->inTransaction()) throw new \LogicException('Server pack writer requires an outermost transaction');
        $this->db->beginTransaction();
        try {
            $instance = $this->db->query('SELECT group_id,head_sequence FROM sync_instance WHERE id=1 FOR UPDATE')->fetch();
            if (!$instance || !is_string($instance['group_id'])) throw new ApiException(409, 'GROUP_NOT_BOUND', 'Server is not bound to a Group');
            if ($before) {
                $existing = $before($storage);
                if ($existing !== null) {
                    $this->db->commit();
                    return $existing;
                }
            }
            $projection = $this->db->query('SELECT group_id,sequence,status FROM projection_state WHERE id=1 FOR UPDATE')->fetch();
            if (!$projection || $projection['group_id'] !== $instance['group_id']
                || (string)$projection['sequence'] !== (string)$instance['head_sequence'] || $projection['status'] !== 'ready') {
                throw new ApiException(503, 'READ_MODEL_CATCHING_UP', 'Sync data is still being prepared');
            }
            $stmt = $this->db->prepare('SELECT id FROM projection_channels WHERE id=?');
            $stmt->execute([$channelId]);
            if ($stmt->fetchColumn() === false) throw new ApiException(409, 'CHANNEL_NOT_FOUND', 'Channel is not available');

            $writer = $this->db->query('SELECT device_id,local_cursor FROM server_writer_state WHERE id=1 FOR UPDATE')->fetch();
            if (!$writer) {
                $deviceId = 'solosync-server-' . substr($this->config->string('server_identity'), 0, 32);
                $this->db->prepare('INSERT IGNORE INTO sync_devices(device_id,display_name) VALUES (?,?)')
                    ->execute([$deviceId, 'Solosync Server']);
                $this->db->prepare('INSERT INTO server_writer_state(id,device_id,local_cursor) VALUES (1,?,0)')
                    ->execute([$deviceId]);
                $writer = ['device_id' => $deviceId, 'local_cursor' => 0];
            }
            $nowMs = (int)floor(microtime(true) * 1000);
            $stamp = gmdate('Y-m-d\TH:i:s', intdiv($nowMs, 1000)) . sprintf('.%03dZ', $nowMs % 1000);
            $messageId = self::uuid();
            $packId = self::uuid();
            $blocksJson = RichEmbed::encodeBlocks($embeds, self::uuid(...));
            $operation = [
                'operationId' => self::uuid(), 'originDeviceId' => $writer['device_id'],
                'table' => 'messages', 'rowId' => $messageId, 'operation' => 'UPSERT',
                'executedAtMs' => $nowMs, 'logicalClock' => 1,
                'data' => ['id' => $messageId, 'channel_id' => $channelId, 'content' => $content,
                    'timestamp' => $stamp, 'edited_timestamp' => null, 'is_pinned' => 0,
                    'reference_message_id' => null, 'user_id' => null, 'attachment_type' => null, 'blocks_json' => $blocksJson],
            ];
            $pack = ['formatVersion' => 1, 'groupId' => $instance['group_id'], 'packId' => $packId,
                'uploaderDeviceId' => $writer['device_id'], 'createdAtMs' => $nowMs,
                'fromLocalCursor' => (int)$writer['local_cursor'], 'toLocalCursor' => (int)$writer['local_cursor'] + 1,
                'operations' => [$operation]];
            $bytes = json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $saved = (new PackRepository($this->db, $this->config))->put($packId, $bytes, hash('sha256', $bytes), $writer['device_id']);
            $this->db->prepare('UPDATE server_writer_state SET local_cursor=local_cursor+1 WHERE id=1')->execute();
            $result = ['messageId' => $messageId, 'packId' => $packId, 'sequence' => $saved['sequence'], 'created' => true];
            if ($after) $after($storage, $result);
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return sprintf('%s-%s-%s-%s-%s', bin2hex(substr($bytes, 0, 4)), bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)), bin2hex(substr($bytes, 8, 2)), bin2hex(substr($bytes, 10)));
    }
}
