<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class PluginManager
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /** @return array<string,array<string,mixed>> */
    public function discover(): array
    {
        $root = $this->config->pluginsPath();
        $result = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            $id = basename($path);
            if (is_link($path) || !preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $id) || !is_file($path . '/plugin.php')) continue;
            $manifestFile = $path . '/manifest.json';
            if (is_file($manifestFile)) {
                try {
                    if (strlen($id) > 31) throw new \RuntimeException('Managed plugin ID is too long: ' . $id);
                    $manifest = json_decode((string)file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
                    if (!is_array($manifest) || ($manifest['id'] ?? null) !== $id
                        || !preg_match('/^\d+\.\d+\.\d+$/D', (string)($manifest['version'] ?? ''))
                        || !is_array($manifest['name'] ?? null) || !is_array($manifest['description'] ?? null)
                        || !is_array($manifest['capabilities'] ?? null) || !array_is_list($manifest['capabilities'])
                        || !is_array($manifest['settings'] ?? null) || !array_is_list($manifest['settings'])) {
                        throw new \RuntimeException('Invalid plugin manifest: ' . $id);
                    }
                    foreach (['name', 'description'] as $localized) {
                        foreach (['ja', 'en'] as $language) {
                            if (!is_string($manifest[$localized][$language] ?? null)) {
                                throw new \RuntimeException('Missing plugin translation: ' . $id);
                            }
                        }
                    }
                    if (isset($manifest['minServerVersion'])
                        && (!is_string($manifest['minServerVersion'])
                            || !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['minServerVersion']))) {
                        throw new \RuntimeException('Invalid minimum server version: ' . $id);
                    }
                    foreach ($manifest['capabilities'] as $capability) {
                        if (!in_array($capability, ['read-model', 'sync-writer', 'events', 'http'], true)) {
                            throw new \RuntimeException('Unsupported plugin capability: ' . $id);
                        }
                    }
                    if (isset($manifest['eventTypes']) && (!is_array($manifest['eventTypes']) || !array_is_list($manifest['eventTypes'])
                        || array_filter($manifest['eventTypes'], static fn(mixed $type): bool => !is_string($type) || $type === ''))) {
                        throw new \RuntimeException('Invalid plugin eventTypes: ' . $id);
                    }
                    $seenFields = [];
                    foreach ($manifest['settings'] as $field) {
                        $key = is_array($field) ? ($field['key'] ?? null) : null;
                        if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9_]{0,63}$/D', $key)
                            || isset($seenFields[$key]) || !in_array($field['type'] ?? null,
                                ['boolean', 'integer', 'text', 'textarea', 'channel'], true)
                            || !is_string($field['label']['ja'] ?? null)
                            || !is_string($field['label']['en'] ?? null)) {
                            throw new \RuntimeException('Invalid plugin setting schema: ' . $id);
                        }
                        $seenFields[$key] = true;
                    }
                    $manifest['legacy'] = false;
                } catch (\Throwable $error) {
                    // One broken third-party manifest must not hide the other plugins.
                    $manifest = ['id' => $id, 'version' => '0.0.0',
                        'name' => ['ja' => $id, 'en' => $id],
                        'description' => ['ja' => 'manifest.jsonを修正してください', 'en' => 'Fix manifest.json'],
                        'capabilities' => [], 'settings' => [], 'legacy' => false,
                        'invalid' => substr($error->getMessage(), 0, 500)];
                }
            } else {
                $manifest = ['id' => $id, 'version' => '0.0.0', 'name' => ['ja' => $id, 'en' => $id],
                    'description' => ['ja' => '従来形式のプラグイン', 'en' => 'Legacy plugin'],
                    'capabilities' => ['events'], 'settings' => [], 'legacy' => true];
            }
            $manifest['path'] = $path;
            $result[$id] = $manifest;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    public function registerDiscovered(): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO plugin_installations(plugin_id,enabled,enabled_at,installed_version)
            VALUES (?,?,?,?)');
        foreach ($this->discover() as $id => $manifest) {
            $legacy = $manifest['legacy'];
            $stmt->execute([$id, $legacy ? 1 : 0, $legacy ? '1970-01-01 00:00:00' : null,
                $legacy ? '0.0.0' : null]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        $this->registerDiscovered();
        $rows = $this->db->query('SELECT plugin_id,enabled,enabled_at,installed_version,last_error FROM plugin_installations')->fetchAll();
        $state = [];
        foreach ($rows as $row) $state[$row['plugin_id']] = $row;
        $deliveryRows = $this->db->query("SELECT plugin_id,status,COUNT(*) AS count FROM plugin_deliveries
            WHERE status IN ('pending','retry','dead') GROUP BY plugin_id,status")->fetchAll();
        $deliveryState = [];
        foreach ($deliveryRows as $delivery) $deliveryState[$delivery['plugin_id']][$delivery['status']] = (int)$delivery['count'];
        $result = [];
        foreach ($this->discover() as $id => $manifest) {
            $row = $state[$id] ?? null;
            $result[] = [
                'id' => $id, 'version' => $manifest['version'], 'name' => $manifest['name'],
                'description' => $manifest['description'], 'capabilities' => $manifest['capabilities'],
                'eventTypes' => $manifest['eventTypes'] ?? [],
                'author' => $manifest['author'] ?? null, 'website' => $manifest['website'] ?? null,
                'minServerVersion' => $manifest['minServerVersion'] ?? null,
                'settingsSchema' => $manifest['settings'], 'legacy' => $manifest['legacy'],
                'enabled' => !isset($manifest['invalid']) && (bool)($row['enabled'] ?? false)
                    && $row['installed_version'] === $manifest['version'],
                'enabledAt' => $row['enabled_at'] ?? null,
                'installedVersion' => $row['installed_version'] ?? null,
                'lastError' => $manifest['invalid'] ?? (($row['enabled'] ?? false) && $row['installed_version'] !== $manifest['version']
                    ? 'プラグインの更新にマイグレーションが必要です。'
                    : ($row['last_error'] ?? null)),
                'delivery' => [
                    'pending' => $deliveryState[$id]['pending'] ?? 0,
                    'retry' => $deliveryState[$id]['retry'] ?? 0,
                    'dead' => $deliveryState[$id]['dead'] ?? 0,
                ],
                'settings' => $this->settings($id),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function manifest(string $id): array
    {
        $manifest = $this->discover()[$id] ?? null;
        if (!$manifest) throw new ApiException(404, 'PLUGIN_NOT_FOUND', 'Plugin not found');
        if (isset($manifest['invalid'])) throw new ApiException(409, 'PLUGIN_MANIFEST_INVALID', $manifest['invalid']);
        return $manifest;
    }

    public function isEnabled(string $id): bool
    {
        $manifest = $this->discover()[$id] ?? null;
        if (!$manifest || isset($manifest['invalid'])) return false;
        $stmt = $this->db->prepare('SELECT enabled,installed_version FROM plugin_installations WHERE plugin_id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) && (int)$row['enabled'] === 1 && $row['installed_version'] === $manifest['version'];
    }

    /** @return array<string,mixed> */
    public function settings(string $id): array
    {
        $stmt = $this->db->prepare('SELECT settings_json FROM plugin_settings WHERE plugin_id=?');
        $stmt->execute([$id]);
        $json = $stmt->fetchColumn();
        if (!is_string($json)) return [];
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function saveSettings(string $id, array $settings): array
    {
        $manifest = $this->manifest($id);
        $this->registerDiscovered();
        $fields = [];
        foreach ($manifest['settings'] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null)) throw new \RuntimeException('Invalid settings schema: ' . $id);
            $fields[$field['key']] = $field;
        }
        foreach ($settings as $key => $value) {
            $field = $fields[$key] ?? null;
            if (!$field) throw new ApiException(400, 'INVALID_SETTING', 'Unknown setting: ' . $key);
            $type = $field['type'] ?? null;
            if (($type === 'boolean' && !is_bool($value))
                || ($type === 'integer' && (!is_int($value) || $value < ($field['min'] ?? PHP_INT_MIN) || $value > ($field['max'] ?? PHP_INT_MAX)))
                || (in_array($type, ['text', 'textarea', 'channel'], true)
                    && (!is_string($value) || strlen($value) > ($field['maxLength'] ?? 4096)))
                || !in_array($type, ['boolean', 'integer', 'text', 'textarea', 'channel'], true)) {
                throw new ApiException(400, 'INVALID_SETTING', 'Invalid setting: ' . $key);
            }
            if ($type === 'channel' && !(new ReadModel($this->db))->channel($value)) {
                throw new ApiException(404, 'CHANNEL_NOT_FOUND', 'Channel setting does not exist');
            }
        }
        $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->db->prepare('INSERT INTO plugin_settings(plugin_id,schema_version,settings_json) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE schema_version=VALUES(schema_version),settings_json=VALUES(settings_json),updated_at=UTC_TIMESTAMP(6)')
            ->execute([$id, (int)($manifest['settingsSchemaVersion'] ?? 1), $json]);
        return $settings;
    }

    public function enable(string $id): void
    {
        $manifest = $this->manifest($id);
        $this->registerDiscovered();
        if ($this->isEnabled($id)) return;
        $serverVersion = ServerVersion::installed();
        if (isset($manifest['minServerVersion'])
            && version_compare($serverVersion, $manifest['minServerVersion'], '<')) {
            throw new ApiException(409, 'PLUGIN_SERVER_VERSION', 'Plugin needs a newer server version');
        }
        $this->db->prepare('UPDATE plugin_installations SET enabled=0 WHERE plugin_id=?')->execute([$id]);
        try {
            $migrations = new ScopedMigrationRunner($this->db);
            $root = dirname(__DIR__, 2);
            if (in_array('read-model', $manifest['capabilities'], true)) {
                $migrations->run('@read-model', $root . '/migrations/read-model');
            }
            if (in_array('sync-writer', $manifest['capabilities'], true)) {
                $migrations->run('@sync-writer', $root . '/migrations/sync-writer');
            }
            $migrations->run($id, $manifest['path'] . '/migrations');
            foreach ($manifest['settings'] as $field) {
                if (is_array($field) && ($field['required'] ?? false) && !array_key_exists($field['key'], $this->settings($id))) {
                    throw new ApiException(409, 'PLUGIN_SETTING_REQUIRED', 'Plugin setting required: ' . $field['key']);
                }
            }
            $this->db->prepare('UPDATE plugin_installations SET enabled=1,enabled_at=UTC_TIMESTAMP(6),installed_version=?,last_error=NULL,updated_at=UTC_TIMESTAMP(6) WHERE plugin_id=?')
                ->execute([$manifest['version'], $id]);
        } catch (\Throwable $error) {
            $this->db->prepare('UPDATE plugin_installations SET enabled=0,last_error=?,updated_at=UTC_TIMESTAMP(6) WHERE plugin_id=?')
                ->execute([substr($error->getMessage(), 0, 1000), $id]);
            throw $error;
        }
    }

    public function disable(string $id): void
    {
        $this->manifest($id);
        $this->db->prepare('UPDATE plugin_installations SET enabled=0,updated_at=UTC_TIMESTAMP(6) WHERE plugin_id=?')->execute([$id]);
        $this->db->prepare("UPDATE plugin_deliveries SET status='skipped' WHERE plugin_id=? AND status IN ('pending','retry')")
            ->execute([$id]);
    }

    public function retryFailedDeliveries(string $id): int
    {
        $this->manifest($id);
        if (!$this->isEnabled($id)) throw new ApiException(409, 'PLUGIN_DISABLED', 'Enable the plugin before retrying');
        $this->db->beginTransaction();
        try {
            $events = $this->db->prepare("SELECT event_id FROM plugin_deliveries WHERE plugin_id=? AND status='dead' FOR UPDATE");
            $events->execute([$id]);
            $ids = array_column($events->fetchAll(), 'event_id');
            $this->db->prepare("UPDATE plugin_deliveries SET status='retry',attempts=0,next_attempt_at=NULL,last_error=NULL
                WHERE plugin_id=? AND status='dead'")->execute([$id]);
            $stmt = $this->db->prepare('UPDATE plugin_outbox SET delivered_at=NULL WHERE event_id=?');
            foreach ($ids as $eventId) $stmt->execute([$eventId]);
            $this->db->commit();
            return count($ids);
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    public function module(string $id): mixed
    {
        $manifest = $this->manifest($id);
        return require $manifest['path'] . '/plugin.php';
    }
}
