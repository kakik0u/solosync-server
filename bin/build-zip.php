<?php
declare(strict_types=1);

// An explicit release manifest prevents Git metadata, credentials, and local
// plugins from being copied into a public distribution archive.
$releaseFiles = [
    '.htaccess',
    'README.md',
    'LICENSE',
    'bin/check.php',
    'bin/maintenance.php',
    'bin/migrate.php',
    'migrations/001_initial.sql',
    'plugins/example/plugin.php',
    'private/config.example.php',
    'public/.htaccess',
    'public/admin.css',
    'public/admin.html',
    'public/admin.js',
    'public/index.php',
    'public/setup.php',
    'src/bootstrap.php',
    'src/Core/AdminAuth.php',
    'src/Core/AdminService.php',
    'src/Core/ApiException.php',
    'src/Core/Auth.php',
    'src/Core/Config.php',
    'src/Core/Database.php',
    'src/Core/DeviceService.php',
    'src/Core/GroupMetadata.php',
    'src/Core/MigrationRunner.php',
    'src/Core/PackRepository.php',
    'src/Core/PluginRunner.php',
    'src/Core/SessionService.php',
    'src/Core/SyncPackValidator.php',
];

$root = dirname(__DIR__);
$dist = $root . '/dist';
if (!is_dir($dist) && !mkdir($dist, 0775, true) && !is_dir($dist)) {
    throw new RuntimeException('Cannot create dist');
}
$target = $dist . '/solosync-server.zip';
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create zip');
}

try {
    foreach ($releaseFiles as $relative) {
        $path = $root . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Release file is missing or is a symlink: ' . $relative);
        }
        if (!$zip->addFile($path, 'solosync-server/' . $relative)) {
            throw new RuntimeException('Cannot add release file: ' . $relative);
        }
    }
} catch (Throwable $error) {
    $zip->close();
    @unlink($target);
    throw $error;
}
if (!$zip->close()) {
    @unlink($target);
    throw new RuntimeException('Cannot finish zip');
}

echo $target, PHP_EOL;
