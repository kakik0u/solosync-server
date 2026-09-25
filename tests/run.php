<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\AdminAuth;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\GroupMetadata;
use Solosync\SyncServer\Core\SyncPackValidator;

require dirname(__DIR__) . '/src/bootstrap.php';

function check(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}

$groupKey = str_repeat('a', 64);
$groupId = hash('sha256', "Solosync/v2/group\0" . $groupKey);
$metadata = GroupMetadata::validate([
    'formatVersion' => 1,
    'groupId' => $groupId,
    'groupKey' => $groupKey,
    'createdAtMs' => 1234,
    'relayUrl' => 'wss://relay.example',
]);
check($metadata['groupId'] === $groupId, 'Group metadata validation failed');

$legacyGroupKey = 'legacy-group-key-123456';
$legacyMetadata = GroupMetadata::validate([
    'formatVersion' => 1,
    'groupId' => hash('sha256', "Solosync/v2/group\0" . $legacyGroupKey),
    'groupKey' => $legacyGroupKey,
    'createdAtMs' => 1234,
]);
check($legacyMetadata['groupKey'] === $legacyGroupKey, 'Legacy v2 Group key compatibility failed');

$badMetadataRejected = false;
try { GroupMetadata::validate([...$metadata, 'groupId' => str_repeat('0', 64)]); }
catch (ApiException $error) { $badMetadataRejected = $error->apiCode === 'INVALID_GROUP_METADATA'; }
check($badMetadataRejected, 'Mismatched Group ID was accepted');

$invalidGroupKeyRejected = false;
try {
    GroupMetadata::validate([
        'formatVersion' => 1,
        'groupId' => hash('sha256', "Solosync/v2/group\0invalid key with spaces"),
        'groupKey' => 'invalid key with spaces',
        'createdAtMs' => 1234,
    ]);
} catch (ApiException $error) {
    $invalidGroupKeyRejected = $error->apiCode === 'INVALID_GROUP_METADATA';
}
check($invalidGroupKeyRejected, 'Invalid Group key syntax was accepted');

$pack = [
    'formatVersion' => 1,
    'groupId' => $groupId,
    'packId' => 'pack-1',
    'uploaderDeviceId' => 'device-1',
    'createdAtMs' => 1234,
    'fromLocalCursor' => 0,
    'toLocalCursor' => 1,
    'operations' => [[
        'operationId' => 'op-1', 'originDeviceId' => 'device-1', 'table' => 'messages',
        'rowId' => 'message-1', 'operation' => 'UPSERT', 'executedAtMs' => 1234, 'logicalClock' => 1,
    ]],
];
$decoded = SyncPackValidator::decode(json_encode($pack, JSON_THROW_ON_ERROR), 'pack-1', $groupId);
check($decoded['packId'] === 'pack-1', 'SyncPack validation failed');

$sharedHostingHtaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
check(is_string($sharedHostingHtaccess), 'Shared-hosting .htaccess is missing');
$wellKnownRule = strpos($sharedHostingHtaccess, 'RewriteRule ^\\.well-known(?:/|$) - [L,NC]');
$hiddenFileRule = strpos($sharedHostingHtaccess, 'RewriteRule (^|/)\\. - [F,L]');
check(
    $wellKnownRule !== false && $hiddenFileRule !== false && $wellKnownRule < $hiddenFileRule,
    'Shared-hosting .htaccess must allow .well-known before blocking hidden paths',
);

$adminPassword = 'test-admin-password';
$adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
check(is_string($adminHash), 'Unable to create admin test password hash');
$adminConfigPath = tempnam(sys_get_temp_dir(), 'solosync-admin-');
check(is_string($adminConfigPath), 'Unable to create admin test config');
$changedAdminConfigPath = tempnam(sys_get_temp_dir(), 'solosync-admin-changed-');
check(is_string($changedAdminConfigPath), 'Unable to create changed admin test config');
$adminConfig = [
    'database' => ['dsn' => 'mysql:host=127.0.0.1;dbname=unused', 'user' => 'unused', 'password' => 'unused'],
    'server_identity' => str_repeat('a', 64),
    'admin_password_hash' => $adminHash,
    'require_https' => false,
    'trust_forwarded_proto' => false,
    'allowed_origins' => [],
];
file_put_contents($adminConfigPath, "<?php\nreturn " . var_export($adminConfig, true) . ";\n");
try {
    $auth = new AdminAuth(Config::load($adminConfigPath));
    $badPasswordRejected = false;
    try { $auth->login('wrong-password', '/solosync'); }
    catch (ApiException $error) { $badPasswordRejected = $error->apiCode === 'INVALID_ADMIN_CREDENTIALS'; }
    check($badPasswordRejected, 'Admin login accepted an invalid password');

    $issued = $auth->login($adminPassword, '/solosync');
    check(
        preg_match('/^[A-Za-z0-9_-]{32}$/D', $issued['csrf']) === 1 && $issued['expiresAt'] > time(),
        'Admin login did not issue a valid session descriptor',
    );

    $csrf = str_repeat('A', 32);
    $payload = rtrim(strtr(base64_encode(json_encode([
        'v' => 1,
        'exp' => time() + 3600,
        'csrf' => $csrf,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $signingKeyMethod = new ReflectionMethod(AdminAuth::class, 'signingKey');
    $signingKey = $signingKeyMethod->invoke($auth);
    check(is_string($signingKey), 'Unable to derive admin session signing key');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $signingKey, true)), '+/', '-_'), '=');
    $_COOKIE['solosync_admin_session'] = $payload . '.' . $signature;
    $session = $auth->session();
    check($session !== null && $session['csrf'] === $csrf, 'Valid admin session was rejected');

    $_SERVER['HTTP_X_SOLOSYNC_ADMIN'] = $csrf;
    $auth->requireMutationHeader();
    $_SERVER['HTTP_X_SOLOSYNC_ADMIN'] = str_repeat('B', 32);
    $badCsrfRejected = false;
    try { $auth->requireMutationHeader(); }
    catch (ApiException $error) { $badCsrfRejected = $error->apiCode === 'ADMIN_CONFIRMATION_REQUIRED'; }
    check($badCsrfRejected, 'Admin mutation accepted an invalid CSRF token');

    $_COOKIE['solosync_admin_session'] = $payload . '.' . ($signature[0] === 'A' ? 'B' : 'A') . substr($signature, 1);
    check($auth->session() === null, 'Tampered admin session was accepted');

    $changedConfig = $adminConfig;
    $changedConfig['admin_password_hash'] = password_hash('changed-admin-password', PASSWORD_DEFAULT);
    file_put_contents($changedAdminConfigPath, "<?php\nreturn " . var_export($changedConfig, true) . ";\n");
    $_COOKIE['solosync_admin_session'] = $payload . '.' . $signature;
    check(
        (new AdminAuth(Config::load($changedAdminConfigPath)))->session() === null,
        'Changing the admin password did not invalidate the previous session',
    );
} finally {
    unset($_COOKIE['solosync_admin_session'], $_SERVER['HTTP_X_SOLOSYNC_ADMIN']);
    @unlink($adminConfigPath);
    @unlink($changedAdminConfigPath);
}

echo "PHP core tests passed.\n";
