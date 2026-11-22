<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ServerVersion;
use Solosync\SyncServer\Core\UpdateArchive;

require dirname(__DIR__) . '/src/bootstrap.php';

function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function removeTestTree(string $path): void {
    if (!is_dir($path)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}
function makeTestZip(string $path, string $version, bool $traversal = false): void {
    $zip = new ZipArchive();
    verify($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create test ZIP');
    foreach (['.htaccess', 'public/.htaccess', 'public/index.php', 'public/admin.html', 'public/admin.js',
        'public/admin.css', 'src/bootstrap.php', 'src/Core/ServerVersion.php',
        'src/Core/AutoUpdater.php', 'src/Core/UpdateArchive.php', 'bin/migrate.php',
        'migrations/001_initial.sql', 'migrations/002_plugins.sql'] as $name) {
        $zip->addFromString('solosync-server/' . $name, 'new ' . $name);
    }
    $zip->addFromString('solosync-server/VERSION', $version);
    if ($traversal) $zip->addFromString('solosync-server/public/../private/config.php', 'malicious');
    verify($zip->close(), 'Cannot close test ZIP');
}

$base = sys_get_temp_dir() . '/solosync-update-test-' . bin2hex(random_bytes(6));
mkdir($base, 0700);
try {
    mkdir($base . '/private', 0700);
    mkdir($base . '/public', 0700);
    mkdir($base . '/plugins', 0700);
    mkdir($base . '/plugins/custom', 0700);
    file_put_contents($base . '/private/config.php', 'keep config');
    file_put_contents($base . '/plugins/custom/plugin.php', 'keep plugin');
    file_put_contents($base . '/public/index.php', 'old front controller');
    verify(ServerVersion::installed($base) === ServerVersion::FALLBACK, 'Missing VERSION caused invalid version');
    verify(!ServerVersion::versionFileValid($base), 'Missing VERSION was considered valid');
    foreach (['webhook', 'public_channel'] as $id) {
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/plugins/' . $id . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        verify(version_compare(ServerVersion::installed($base), $manifest['minServerVersion'], '>='),
            'Missing VERSION incorrectly rejects built-in plugin ' . $id);
    }
    $zipPath = $base . '/package.zip';
    makeTestZip($zipPath, '1.0.1');
    $ranMigration = false;
    $result = (new UpdateArchive($base))->install($zipPath, '1.0.1', static function () use (&$ranMigration, $base): void {
        verify(is_file($base . '/migrations/001_initial.sql'), 'Migration file missing from installation');
        $ranMigration = true;
    });
    verify($ranMigration && $result['files'] === 14, 'Update did not apply all files');
    verify(file_get_contents($base . '/public/index.php') === 'new public/index.php', 'Front controller not updated');
    verify(ServerVersion::installed($base) === '1.0.1', 'VERSION not updated');
    verify(file_get_contents($base . '/private/config.php') === 'keep config', 'Config overwritten');
    verify(file_get_contents($base . '/plugins/custom/plugin.php') === 'keep plugin', 'Custom plugin overwritten');
    verify(file_get_contents($result['backup'] . '/public/index.php') === 'old front controller', 'Old code backup missing');
    makeTestZip($zipPath, '1.0.2');
    $failed = false;
    try { (new UpdateArchive($base))->install($zipPath, '1.0.2', static function (): void { throw new RuntimeException('migration failed'); }); }
    catch (RuntimeException $error) { $failed = $error->getMessage() === 'migration failed'; }
    verify($failed, 'Failed migration did not fail update');
    verify(ServerVersion::installed($base) === '1.0.1', 'Failed migration changed VERSION');
    verify(file_get_contents($base . '/public/index.php') === 'new public/index.php', 'Failed migration changed front controller');
    makeTestZip($zipPath, '1.0.2', true);
    $rejected = false;
    try { (new UpdateArchive($base))->install($zipPath, '1.0.2', static function (): void {}); }
    catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'unexpected or duplicate path'); }
    verify($rejected, 'Traversal path was accepted');
    verify(file_get_contents($base . '/private/config.php') === 'keep config', 'Traversal changed private config');
    if (isset($argv[1])) {
        $releaseZip = realpath($argv[1]);
        verify($releaseZip !== false, 'Release ZIP argument does not exist');
        $version = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));
        $actual = (new UpdateArchive($base))->install($releaseZip, $version, static function (): void {});
        verify($actual['files'] > 20, 'Actual release ZIP has too few files');
        verify(is_file($base . '/public/admin.html'), 'Actual release ZIP did not install admin UI');
        verify(file_get_contents($base . '/private/config.php') === 'keep config', 'Actual ZIP changed config');
        verify(file_get_contents($base . '/plugins/custom/plugin.php') === 'keep plugin', 'Actual ZIP changed custom plugin');
    }
} finally {
    removeTestTree($base);
}
echo "Update archive tests passed.\n";
