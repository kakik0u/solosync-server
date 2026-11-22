<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class PluginRunner
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /** @return array{scanned:int,delivered:int,retried:int} */
    public function run(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->query('SELECT event_id,event_type,event_key,payload,attempts FROM plugin_outbox
            WHERE delivered_at IS NULL ORDER BY event_id ASC LIMIT ' . $limit)->fetchAll();
        $plugins = $this->loadPlugins();
        $delivered = 0;
        $retried = 0;
        foreach ($rows as $row) {
            $event = [
                'eventId' => (string)$row['event_id'],
                'type' => (string)$row['event_type'],
                'key' => (string)$row['event_key'],
                'payload' => json_decode((string)$row['payload'], true, 128, JSON_THROW_ON_ERROR),
            ];
            try {
                foreach ($plugins as $plugin) $plugin($event);
                $this->db->prepare('UPDATE plugin_outbox SET delivered_at=UTC_TIMESTAMP(6),last_error=NULL WHERE event_id=?')
                    ->execute([$row['event_id']]);
                $delivered++;
            } catch (\Throwable $error) {
                $this->db->prepare('UPDATE plugin_outbox SET attempts=attempts+1,last_error=? WHERE event_id=?')
                    ->execute([substr($error->getMessage(), 0, 1000), $row['event_id']]);
                $retried++;
            }
        }
        return ['scanned' => count($rows), 'delivered' => $delivered, 'retried' => $retried];
    }

    /** @return list<callable(array<string,mixed>):void> */
    private function loadPlugins(): array
    {
        $root = $this->config->pluginsPath();
        if (!is_dir($root)) return [];
        $paths = glob($root . '/*/plugin.php') ?: [];
        sort($paths, SORT_STRING);
        $plugins = [];
        foreach ($paths as $path) {
            $plugin = require $path;
            if (!is_callable($plugin)) throw new \RuntimeException('Plugin must return a callable: ' . basename(dirname($path)));
            $plugins[] = $plugin;
        }
        return $plugins;
    }
}
