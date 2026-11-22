<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Rebuildable channel/message view derived from immutable SyncPacks. */
final class ReadModel
{
    public const REVISION = 5;
    public function __construct(private readonly \PDO $db) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $row = $this->db->query('SELECT p.group_id,p.generation,p.sequence,p.status,p.last_error,
            i.group_id AS current_group_id,i.generation AS current_generation,i.head_sequence
            FROM projection_state p JOIN sync_instance i ON i.id=1 WHERE p.id=1')->fetch();
        if (!$row) throw new ApiException(503, 'READ_MODEL_NOT_READY', 'Read model is not initialized');
        $revision = $this->revision();
        return [
            'groupId' => $row['group_id'], 'generation' => (string)$row['generation'],
            'sequence' => (string)$row['sequence'], 'headSequence' => (string)$row['head_sequence'],
            'status' => $row['status'], 'lastError' => $row['last_error'],
            'revision' => $revision,
            'ready' => $row['group_id'] !== null && $row['group_id'] === $row['current_group_id']
                && (string)$row['generation'] === (string)$row['current_generation']
                && (string)$row['sequence'] === (string)$row['head_sequence'] && $row['status'] === 'ready'
                && $revision === self::REVISION,
        ];
    }

    /** Admin-only diagnostics; counting all rows is intentionally off the public path. */
    public function diagnostics(): array
    {
        $status = $this->status();
        $status['channels'] = (int)$this->db->query('SELECT COUNT(*) FROM projection_channels')->fetchColumn();
        $status['messages'] = (int)$this->db->query('SELECT COUNT(*) FROM projection_messages')->fetchColumn();
        $status['orphanMessages'] = (int)$this->db->query(
            'SELECT COUNT(*) FROM projection_messages m LEFT JOIN projection_channels c ON c.id=m.channel_id WHERE c.id IS NULL'
        )->fetchColumn();
        return $status;
    }

    private function revision(): int
    {
        try { return (int)$this->db->query('SELECT revision FROM projection_revision WHERE id=1')->fetchColumn(); }
        catch (\PDOException $error) {
            if ($error->getCode() !== '42S02') throw $error;
            return -1;
        }
    }

    public function requireReady(): void
    {
        if (!$this->status()['ready']) throw new ApiException(503, 'READ_MODEL_CATCHING_UP', 'Sync data is still being prepared');
    }

    /** Reset only derived rows; immutable SyncPacks remain the source of truth. */
    public function rebuild(): array
    {
        if ((int)$this->db->query("SELECT GET_LOCK('solosync_read_model',30)")->fetchColumn() !== 1) {
            throw new ApiException(503, 'READ_MODEL_BUSY', 'Read model is busy');
        }
        try {
            $instance = $this->db->query('SELECT group_id,generation FROM sync_instance WHERE id=1')->fetch();
            if (!is_array($instance) || !is_string($instance['group_id'])) {
                throw new ApiException(409, 'GROUP_NOT_BOUND', 'Server is not bound to a Group');
            }
            $this->resetForGroup($instance['group_id'], (string)$instance['generation']);
            return $this->status();
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK('solosync_read_model')"); }
            catch (\Throwable) { /* connection teardown releases the lock */ }
        }
    }

    /** @return array<string,mixed> */
    public function step(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        if ($this->revision() < 0) {
            throw new ApiException(503, 'READ_MODEL_MIGRATION_REQUIRED', 'Run the read-model migration first');
        }
        if ((int)$this->db->query("SELECT GET_LOCK('solosync_read_model',0)")->fetchColumn() !== 1) return $this->status();
        try {
            $instance = $this->db->query('SELECT group_id,generation FROM sync_instance WHERE id=1')->fetch();
            if (!is_array($instance) || !is_string($instance['group_id'])) {
                throw new ApiException(409, 'GROUP_NOT_BOUND', 'Server is not bound to a Group');
            }
            $state = $this->db->query('SELECT group_id,generation FROM projection_state WHERE id=1')->fetch();
            if ($this->revision() !== self::REVISION || !$state || $state['group_id'] !== $instance['group_id']
                || (string)$state['generation'] !== (string)$instance['generation']) {
                $this->resetForGroup($instance['group_id'], (string)$instance['generation']);
            }
            $sequence = (string)$this->db->query('SELECT sequence FROM projection_state WHERE id=1')->fetchColumn();
            $stmt = $this->db->prepare('SELECT pack_id,sequence,bytes FROM sync_packs WHERE sequence>? ORDER BY sequence ASC LIMIT ' . $limit);
            $stmt->execute([$sequence]);
            $packs = $stmt->fetchAll();
            foreach ($packs as $pack) {
                try {
                    $decoded = SyncPackValidator::decode((string)$pack['bytes'], (string)$pack['pack_id'], $instance['group_id']);
                    $this->db->beginTransaction();
                    foreach ($decoded['operations'] as $operation) $this->apply($operation);
                    $this->db->prepare("UPDATE projection_state SET sequence=?,status='building',last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=1")
                        ->execute([$pack['sequence']]);
                    $this->db->commit();
                } catch (\Throwable $error) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    $this->db->prepare("UPDATE projection_state SET status='error',last_error=?,updated_at=UTC_TIMESTAMP(6) WHERE id=1")
                        ->execute([substr($error->getMessage(), 0, 1000)]);
                    throw $error;
                }
            }
            $head = (string)$this->db->query('SELECT head_sequence FROM sync_instance WHERE id=1')->fetchColumn();
            $this->db->prepare('UPDATE projection_state SET status=IF(sequence=?,\'ready\',\'building\'),
                updated_at=UTC_TIMESTAMP(6) WHERE id=1')->execute([$head]);
            return $this->status();
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK('solosync_read_model')"); }
            catch (\Throwable) { /* connection teardown releases the lock */ }
        }
    }

    /** @return list<array<string,mixed>> */
    public function channels(): array
    {
        $this->requireReady();
        return $this->db->query('SELECT c.id,c.name,c.guild_id,c.is_hidden,d.category_id,d.topic
            FROM projection_channels c LEFT JOIN projection_channel_details d ON d.channel_id=c.id
            ORDER BY c.name,c.id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function guilds(): array
    {
        $this->requireReady();
        return $this->db->query('SELECT id,name FROM projection_guilds ORDER BY name,id')->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function categories(string $guildId): array
    {
        $this->requireReady();
        $stmt = $this->db->prepare('SELECT id,guild_id,name,sort_order FROM projection_categories
            WHERE guild_id=? ORDER BY sort_order,id LIMIT 500');
        $stmt->execute([$guildId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function tags(int $limit = 200): array
    {
        $this->requireReady();
        $limit = max(1, min(500, $limit));
        return $this->db->query('SELECT id,name,category FROM projection_tags ORDER BY name,id LIMIT ' . $limit)->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function messageTags(string $messageId): array
    {
        $this->requireReady();
        $stmt = $this->db->prepare('SELECT t.id,t.name,t.category,mt.source
            FROM projection_message_tags mt
            JOIN projection_messages m ON m.id=mt.message_id
            JOIN projection_tags t ON t.id=mt.tag_id
            WHERE mt.message_id=? AND m.archived_at IS NULL ORDER BY t.name,t.id LIMIT 200');
        $stmt->execute([$messageId]);
        return $stmt->fetchAll();
    }

    public function channel(string $id): ?array
    {
        $this->requireReady();
        $stmt = $this->db->prepare('SELECT c.id,c.name,c.guild_id,c.is_hidden,d.category_id,d.topic
            FROM projection_channels c LEFT JOIN projection_channel_details d ON d.channel_id=c.id WHERE c.id=? AND c.archived_at IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function messages(string $channelId, int $limit, ?string $before = null, int $maxChars = 16384): array
    {
        $this->requireReady();
        $limit = max(1, min(100, $limit));
        $maxChars = max(1024, min(65536, $maxChars));
        $sql = 'SELECT m.id,LEFT(m.content,' . $maxChars . ') AS content,
            (CHAR_LENGTH(m.content)>' . $maxChars . ') AS truncated,
            m.timestamp,m.timestamp_ms,m.edited_timestamp,COALESCE(p.name,\'メモ\') AS author,
            CASE WHEN OCTET_LENGTH(m.blocks_json)<=' . RichEmbed::MAX_BLOCK_BYTES . ' THEN m.blocks_json ELSE NULL END AS blocks_json,
            COALESCE(OCTET_LENGTH(m.blocks_json)>' . RichEmbed::MAX_BLOCK_BYTES . ',0) AS blocks_truncated
            FROM projection_messages m JOIN projection_channels c ON c.id=m.channel_id LEFT JOIN projection_profiles p ON p.id=m.user_id
            WHERE m.channel_id=? AND m.archived_at IS NULL AND c.archived_at IS NULL';
        $params = [$channelId];
        if ($before !== null) {
            $decoded = base64_decode(strtr($before, '-_', '+/') . str_repeat('=', (4 - strlen($before) % 4) % 4), true);
            $cursor = is_string($decoded) ? json_decode($decoded, true) : null;
            if (!is_array($cursor) || count($cursor) !== 2 || !is_int($cursor[0]) || !is_string($cursor[1])
                || strlen($cursor[1]) > 255) {
                throw new ApiException(400, 'INVALID_CURSOR', 'Invalid page cursor');
            }
            $sql .= ' AND (m.timestamp_ms<? OR (m.timestamp_ms=? AND m.id<?))';
            array_push($params, $cursor[0], $cursor[0], $cursor[1]);
        }
        $sql .= ' ORDER BY m.timestamp_ms DESC,m.id DESC LIMIT ' . $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function resetForGroup(string $groupId, string $generation): void
    {
        $this->db->beginTransaction();
        try {
            foreach (['projection_record_versions','projection_message_tags','projection_tags',
                'projection_messages','projection_channel_details','projection_channels',
                'projection_categories','projection_guilds',
                'projection_profiles'] as $table) {
                $this->db->exec('DELETE FROM ' . $table);
            }
            $this->db->prepare('UPDATE projection_revision SET revision=? WHERE id=1')->execute([self::REVISION]);
            $this->db->prepare("UPDATE projection_state SET group_id=?,generation=?,sequence=0,status='building',last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE id=1")
                ->execute([$groupId, $generation]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $operation */
    private function apply(array $operation): void
    {
        $table = $operation['table'];
        $rowId = $operation['rowId'];
        if (!in_array($table, ['guilds', 'channel_categories', 'channels', 'messages',
            'user_profiles', 'tags', 'message_tags'], true) || strlen($rowId) > 255
            || strlen((string)$operation['operationId']) > 128
            || strlen((string)$operation['originDeviceId']) > 128) return;
        $data = $operation['data'] ?? null;
        if ($operation['operation'] === 'UPSERT' && (!is_array($data)
            || ($table !== 'message_tags' && (string)($data['id'] ?? '') !== $rowId)
            || ($table === 'message_tags' && (string)($data['message_id'] ?? '') . "\x1f"
                . (string)($data['tag_id'] ?? '') !== $rowId))) return;
        $stmt = $this->db->prepare('SELECT operation_id,origin_device_id,executed_at_ms,logical_clock
            FROM projection_record_versions WHERE table_name=? AND row_id=? FOR UPDATE');
        $stmt->execute([$table, $rowId]);
        $previous = $stmt->fetch();
        $incoming = [(int)$operation['executedAtMs'], (int)$operation['logicalClock'],
            (string)$operation['originDeviceId'], (string)$operation['operationId']];
        if (is_array($previous)) {
            $existing = [(int)$previous['executed_at_ms'], (int)$previous['logical_clock'],
                (string)$previous['origin_device_id'], (string)$previous['operation_id']];
            if (self::compareVersions($existing, $incoming) >= 0) return;
        }
        if ($operation['operation'] === 'DELETE') {
            $this->deleteRow($table, $rowId);
        } elseif (!$this->upsertRow($table, $rowId, $data)) {
            return;
        }
        $this->db->prepare('INSERT INTO projection_record_versions
            (table_name,row_id,operation_id,origin_device_id,executed_at_ms,logical_clock,deleted)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operation_id=VALUES(operation_id),
            origin_device_id=VALUES(origin_device_id),executed_at_ms=VALUES(executed_at_ms),
            logical_clock=VALUES(logical_clock),deleted=VALUES(deleted)')
            ->execute([$table, $rowId, $operation['operationId'], $operation['originDeviceId'],
                $operation['executedAtMs'], $operation['logicalClock'], $operation['operation'] === 'DELETE' ? 1 : 0]);
    }

    /** @param list<int|string> $a @param list<int|string> $b */
    public static function compareVersions(array $a, array $b): int
    {
        for ($i = 0; $i < 2; $i++) {
            $compare = $a[$i] <=> $b[$i];
            if ($compare !== 0) return $compare;
        }
        for ($i = 2; $i < 4; $i++) {
            $compare = strcmp((string)$a[$i], (string)$b[$i]);
            if ($compare !== 0) return $compare;
        }
        return 0;
    }

    private function deleteRow(string $table, string $id): void
    {
        if ($table === 'message_tags') {
            $parts = explode("\x1f", $id);
            if (count($parts) === 2) {
                $this->db->prepare('DELETE FROM projection_message_tags WHERE message_id=? AND tag_id=?')
                    ->execute($parts);
            }
            return;
        }
        if ($table === 'channels') {
            $this->db->prepare('DELETE FROM projection_channel_details WHERE channel_id=?')->execute([$id]);
        }
        $physical = ['guilds' => 'projection_guilds',
            'channel_categories' => 'projection_categories', 'channels' => 'projection_channels',
            'messages' => 'projection_messages', 'user_profiles' => 'projection_profiles',
            'tags' => 'projection_tags'][$table];
        $this->db->prepare('DELETE FROM ' . $physical . ' WHERE id=?')->execute([$id]);
    }

    /** @param array<string,mixed> $data */
    private function upsertRow(string $table, string $id, array $data): bool
    {
        if ($table === 'guilds') {
            if (!is_string($data['name'] ?? null) || strlen($data['name']) > 255) return false;
            $this->db->prepare('INSERT INTO projection_guilds(id,name) VALUES (?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name)')->execute([$id, $data['name']]);
            return true;
        }
        if ($table === 'channel_categories') {
            if (!is_string($data['name'] ?? null) || strlen($data['name']) > 255
                || !is_string($data['guild_id'] ?? null) || strlen($data['guild_id']) > 255) return false;
            $this->db->prepare('INSERT INTO projection_categories(id,guild_id,name,sort_order) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE guild_id=VALUES(guild_id),name=VALUES(name),sort_order=VALUES(sort_order)')
                ->execute([$id, $data['guild_id'], $data['name'], (int)($data['sort_order'] ?? 0)]);
            return true;
        }
        if ($table === 'tags') {
            if (!is_string($data['name'] ?? null) || strlen($data['name']) > 255) return false;
            $category = is_string($data['category'] ?? null) ? $data['category'] : 'general';
            if (strlen($category) > 40) return false;
            $this->db->prepare('INSERT INTO projection_tags(id,name,category) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category)')
                ->execute([$id, $data['name'], $category]);
            return true;
        }
        if ($table === 'message_tags') {
            $messageId = (string)($data['message_id'] ?? '');
            $tagId = (string)($data['tag_id'] ?? '');
            if ($messageId === '' || $tagId === '' || strlen($messageId) > 255 || strlen($tagId) > 255) return false;
            $source = ($data['source'] ?? null) === 'auto' ? 'auto' : 'manual';
            $this->db->prepare('INSERT INTO projection_message_tags(message_id,tag_id,source) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE source=VALUES(source)')->execute([$messageId, $tagId, $source]);
            return true;
        }
        if ($table === 'channels') {
            if (!is_string($data['name'] ?? null) || strlen($data['name']) > 255) return false;
            $categoryId = is_string($data['category_id'] ?? null) ? $data['category_id'] : null;
            $topic = is_string($data['topic'] ?? null) ? $data['topic'] : null;
            if (($categoryId !== null && strlen($categoryId) > 255)
                || ($topic !== null && strlen($topic) > 65535)) return false;
            $this->db->prepare('INSERT INTO projection_channels(id,name,guild_id,is_hidden,archived_at) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE name=VALUES(name),guild_id=VALUES(guild_id),is_hidden=VALUES(is_hidden),archived_at=IF(?,VALUES(archived_at),archived_at)')
                ->execute([$id, $data['name'], is_string($data['guild_id'] ?? null) ? $data['guild_id'] : null,
                    (int)($data['is_hidden'] ?? 0) ? 1 : 0, is_string($data['archived_at'] ?? null) ? $data['archived_at'] : null, (int)array_key_exists('archived_at',$data)]);
            $this->db->prepare('INSERT INTO projection_channel_details(channel_id,category_id,topic)
                VALUES (?,?,?) ON DUPLICATE KEY UPDATE category_id=VALUES(category_id),topic=VALUES(topic)')
                ->execute([$id, $categoryId, $topic]);
            return true;
        }
        if ($table === 'user_profiles') {
            if (!is_string($data['name'] ?? null) || strlen($data['name']) > 255) return false;
            $this->db->prepare('INSERT INTO projection_profiles(id,name) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)')
                ->execute([$id, $data['name']]);
            return true;
        }
        if (!is_string($data['channel_id'] ?? null) || !is_string($data['content'] ?? null)
            || !is_string($data['timestamp'] ?? null) || strlen($data['timestamp']) > 40
            || strlen($data['channel_id']) > 255
            || (is_string($data['edited_timestamp'] ?? null) && strlen($data['edited_timestamp']) > 40)
            || (is_string($data['user_id'] ?? null) && strlen($data['user_id']) > 255)) return false;
        try { $stamp = new \DateTimeImmutable($data['timestamp'], new \DateTimeZone('UTC')); }
        catch (\Exception) { return false; }
        $timestampMs = (int)$stamp->format('U') * 1000 + (int)$stamp->format('v');
        $hasBlocks = array_key_exists('blocks_json', $data);
        // Retain opaque future/corrupt JSON. The public renderer can fall back
        // without rejecting the rest of this message or changing source packs.
        $blocksJson = !$hasBlocks || $data['blocks_json'] === null ? null : (is_string($data['blocks_json']) ? $data['blocks_json']
            : json_encode($data['blocks_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->db->prepare('INSERT INTO projection_messages(id,channel_id,content,timestamp,timestamp_ms,edited_timestamp,user_id,blocks_json,reference_message_id,archived_at)
            VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),content=VALUES(content),
            timestamp=VALUES(timestamp),timestamp_ms=VALUES(timestamp_ms),edited_timestamp=VALUES(edited_timestamp),user_id=VALUES(user_id),
            blocks_json=IF(?,VALUES(blocks_json),blocks_json),reference_message_id=IF(?,VALUES(reference_message_id),reference_message_id),archived_at=IF(?,VALUES(archived_at),archived_at)')
            ->execute([$id, $data['channel_id'], $data['content'], $data['timestamp'], $timestampMs,
                is_string($data['edited_timestamp'] ?? null) ? $data['edited_timestamp'] : null,
                is_string($data['user_id'] ?? null) ? $data['user_id'] : null, $blocksJson, is_string($data['reference_message_id'] ?? null) ? $data['reference_message_id'] : null, is_string($data['archived_at'] ?? null) ? $data['archived_at'] : null, (int)$hasBlocks, (int)array_key_exists('reference_message_id',$data), (int)array_key_exists('archived_at',$data)]);
        return true;
    }
}
