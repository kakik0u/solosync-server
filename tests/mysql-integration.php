<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\AdminService;
use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\Database;
use Solosync\SyncServer\Core\DeviceService;
use Solosync\SyncServer\Core\PackRepository;
use Solosync\SyncServer\Core\SessionService;

$dsn = getenv('SOLOSYNC_TEST_MYSQL_DSN') ?: '';
$user = getenv('SOLOSYNC_TEST_MYSQL_USER') ?: '';
$password = getenv('SOLOSYNC_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '') { fwrite(STDERR, "SKIP: set SOLOSYNC_TEST_MYSQL_DSN to run MySQL/MariaDB integration tests.\n"); exit(77); }

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$tmp = tempnam(sys_get_temp_dir(), 'solosync-config-');
if ($tmp === false) throw new RuntimeException('Cannot create test config');
$serverId = bin2hex(random_bytes(32));
$configPhp = '<?php return ' . var_export([
    'database' => ['dsn' => $dsn, 'user' => $user, 'password' => $password],
    'server_identity' => $serverId,
    'admin_password_hash' => password_hash('test', PASSWORD_DEFAULT),
    'require_https' => false, 'trust_forwarded_proto' => false, 'allowed_origins' => [],
    'max_pack_bytes' => 16 * 1024 * 1024, 'max_page_size' => 200, 'minimum_poll_ms' => 1000,
    'quota_bytes' => 1024 * 1024 * 1024, 'plugins_path' => $root . '/plugins',
], true) . ';';
file_put_contents($tmp, $configPhp);
$config = Config::load($tmp);
$db = Database::connect($config);
foreach (['plugin_outbox','sync_packs','sync_credentials','sync_connection_secrets','sync_devices','sync_instance','schema_migrations'] as $table) {
    $db->exec("DROP TABLE IF EXISTS {$table}");
}
$admin = new AdminService($db, $config);
$admin->install($root . '/migrations');
$key = $admin->createConnectionKey('integration')['key'];
$groupKey = bin2hex(random_bytes(32));
$groupId = hash('sha256', "Solosync/v2/group\0" . $groupKey);
$session = new SessionService($db);
$first = $session->exchange(['connectionKey' => $key, 'deviceId' => 'device-a', 'group' => [
    'formatVersion' => 1, 'groupId' => $groupId, 'groupKey' => $groupKey, 'createdAtMs' => 1,
]]);
if ($first['groupId'] !== $groupId) throw new RuntimeException('Initial bind failed');
$recovered = $session->exchange(['connectionKey' => $key, 'deviceId' => 'device-b']);
if ($recovered['groupId'] !== $groupId || $session->group()['groupKey'] !== $groupKey) throw new RuntimeException('Recovery exchange failed');

$pack = [
    'formatVersion' => 1, 'groupId' => $groupId, 'packId' => 'pack-1', 'uploaderDeviceId' => 'device-a',
    'createdAtMs' => 1, 'fromLocalCursor' => 0, 'toLocalCursor' => 1,
    'operations' => [['operationId' => 'op-1','originDeviceId' => 'device-a','table' => 'messages',
        'rowId' => 'm1','operation' => 'UPSERT','executedAtMs' => 1,'logicalClock' => 1]],
];
$bytes = json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$repo = new PackRepository($db, $config);
$put = $repo->put('pack-1', $bytes, hash('sha256', $bytes), 'device-a');
if (!$put['created'] || $repo->get('pack-1') !== $bytes) throw new RuntimeException('Pack put/get failed');
$again = $repo->put('pack-1', $bytes, hash('sha256', $bytes), 'device-a');
if ($again['created']) throw new RuntimeException('Idempotent pack retry created a duplicate');
$page = $repo->list('0', 10);
if (count($page['items']) !== 1 || $page['nextCursor'] !== '1') throw new RuntimeException('Commit cursor list failed');

$db->exec('UPDATE sync_instance SET quota_bytes=used_bytes WHERE id=1');
$quotaPack = $pack;
$quotaPack['packId'] = 'pack-quota';
$quotaPack['toLocalCursor'] = 2;
$quotaPack['operations'][0]['operationId'] = 'op-quota';
$quotaPack['operations'][0]['rowId'] = 'm-quota';
$quotaBytes = json_encode($quotaPack, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$quotaRejected = false;
try {
    $repo->put('pack-quota', $quotaBytes, hash('sha256', $quotaBytes), 'device-a');
} catch (ApiException $error) {
    $quotaRejected = $error->status === 507 && $error->apiCode === 'QUOTA_EXCEEDED';
}
if (!$quotaRejected) throw new RuntimeException('Quota exhaustion was not rejected with QUOTA_EXCEEDED');

(new DeviceService($db))->revoke('device-b');
if ((int)$db->query("SELECT COUNT(*) FROM plugin_outbox WHERE event_type IN ('pack.stored','operations.stored','device.revoked')")->fetchColumn() !== 3) {
    throw new RuntimeException('Plugin outbox events missing');
}
unlink($tmp);
echo "MySQL/MariaDB integration tests passed.\n";
