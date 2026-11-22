<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class Database
{
    public static function connect(Config $config): \PDO
    {
        $db = $config->database();
        $pdo = new \PDO($db['dsn'], $db['user'], $db['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (str_starts_with(strtolower($db['dsn']), 'mysql:')) {
            $pdo->exec("SET time_zone = '+00:00'");
        }
        return $pdo;
    }

    public static function isInstalled(\PDO $db): bool
    {
        try {
            $db->query('SELECT id FROM sync_instance WHERE id=1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }
}
