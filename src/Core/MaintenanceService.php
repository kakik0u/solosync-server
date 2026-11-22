<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Bounded shared-host maintenance; safe to call from cron or the admin API. */
final class MaintenanceService
{
    public function __construct(private readonly \PDO $db, private readonly Config $config) {}

    /** @return array<string,mixed> */
    public function run(int $limit = 50): array
    {
        $result = ['events' => (new PluginRunner($this->db, $this->config))->run($limit)];
        $model = new ReadModel($this->db);
        try { $status = $model->status(); }
        catch (\PDOException) {
            // The read-model capability has not been enabled yet.
            $result['projection'] = ['initialized' => false];
            return $result;
        }
        if (($status['revision'] ?? 0) < ReadModel::REVISION) {
            (new ScopedMigrationRunner($this->db))->run(
                '@read-model', dirname(__DIR__, 2) . '/migrations/read-model'
            );
        }
        $result['projection'] = $status['groupId'] !== null || $status['headSequence'] !== '0'
            ? $model->step(min(100, max(1, $limit))) : $status;
        return $result;
    }
}
