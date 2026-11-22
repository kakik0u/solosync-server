<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Trusted plugins use this for their own tables; it is not a PHP sandbox. */
final class PluginStorage
{
    public function __construct(private readonly \PDO $db, private readonly string $pluginId) {}

    public function table(string $suffix): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $suffix)) throw new \InvalidArgumentException('Invalid plugin table suffix');
        $name = 'plugin_' . $this->pluginId . '_' . $suffix;
        if (strlen($name) > 64) throw new \InvalidArgumentException('Plugin table name is too long');
        return $name;
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param list<mixed> $params @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** A plugin may use this for its own rows; it cannot nest the host pack-writer transaction. */
    public function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) throw new \LogicException('Plugin transaction is already active');
        $this->db->beginTransaction();
        try {
            $result = $work($this);
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
}
