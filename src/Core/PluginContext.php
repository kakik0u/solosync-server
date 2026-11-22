<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Host services exposed to a trusted plugin at request/event time. */
final class PluginContext
{
    /** @param array<string,mixed> $settings */
    public function __construct(
        private readonly \PDO $db,
        private readonly Config $config,
        public readonly string $pluginId,
        public readonly array $settings,
        public readonly array $capabilities,
    ) {}

    public function storage(): PluginStorage { return new PluginStorage($this->db, $this->pluginId); }
    public function allowsOrigin(string $origin): bool { return $origin === '' || in_array($origin, $this->config->allowedOrigins(), true); }
    public function requestIsHttps(): bool { return $this->config->requestIsHttps(); }
    public function readModel(): ReadModel
    {
        if (!in_array('read-model', $this->capabilities, true)) throw new \LogicException('Plugin did not declare read-model');
        return new ReadModel($this->db);
    }

    public function writer(): ServerPackWriter
    {
        if (!in_array('sync-writer', $this->capabilities, true)) throw new \LogicException('Plugin did not declare sync-writer');
        return new ServerPackWriter($this->db, $this->config);
    }
}
