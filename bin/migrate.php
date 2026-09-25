<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\AdminService;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\Database;

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$config = Config::load($root . '/private/config.php');
$db = Database::connect($config);
$applied = (new AdminService($db, $config))->install($root . '/migrations');
echo json_encode(['appliedMigrations' => $applied], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
