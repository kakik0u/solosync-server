<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\Database;
use Solosync\SyncServer\Core\PluginRunner;

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$config = Config::load($root . '/private/config.php');
$db = Database::connect($config);
echo json_encode((new PluginRunner($db, $config))->run(100), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
