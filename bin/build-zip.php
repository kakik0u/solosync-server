<?php
declare(strict_types=1);

// An explicit release manifest prevents Git metadata, credentials, and local
// plugins from being copied into a public distribution archive.
$releaseFiles = [
    '.htaccess',
    'README.md',
    'VERSION',
    'LICENSE',
    'docs/plugins/README.md',
    'docs/plugins/api.md',
    'docs/plugins/manifest.md',
    'docs/plugins/migration.md',
    'docs/plugins/public-channel.md',
    'docs/plugins/webhook.md',
    'docs/plugins/mcp.md',
    'bin/check.php',
    'bin/maintenance.php',
    'bin/migrate.php',
    'migrations/001_initial.sql',
    'migrations/002_plugins.sql',
    'migrations/003_history_identity.sql',
    'migrations/read-model/001_initial.sql',
    'migrations/read-model/002_structured_domain.sql',
    'migrations/read-model/003_message_blocks.sql',
    'migrations/read-model/004_message_replies.sql',
    'migrations/read-model/005_archive.sql',
    'migrations/sync-writer/001_initial.sql',
    'plugins/example/plugin.php',
    'plugins/mcp/manifest.json',
    'plugins/mcp/plugin.php',
    'plugins/mcp/Notes.php',
    'plugins/mcp/Protocol.php',
    'plugins/mcp/Bridge.php',
    'plugins/mcp/migrations/001_initial.sql',
    'plugins/webhook/manifest.json',
    'plugins/webhook/plugin.php',
    'plugins/webhook/Payload.php',
    'plugins/webhook/migrations/001_initial.sql',
    'plugins/public_channel/manifest.json',
    'plugins/public_channel/plugin.php',
    'plugins/public_channel/BlocksRenderer.php',
    'plugins/public_channel/migrations/001_initial.sql',
    'private/config.example.php',
    'public/.htaccess',
    'public/admin.css',
    'public/admin.html',
    'public/admin.js',
    'public/index.php',
    'public/setup.php',
    'src/bootstrap.php',
    'src/Core/AdminAuth.php',
    'src/Core/AutoUpdater.php',
    'src/Core/AdminService.php',
    'src/Core/ApiException.php',
    'src/Core/Auth.php',
    'src/Core/Config.php',
    'src/Core/Database.php',
    'src/Core/DeviceService.php',
    'src/Core/GroupMetadata.php',
    'src/Core/HistoryIdentity.php',
    'src/Core/MaintenanceService.php',
    'src/Core/MigrationRunner.php',
    'src/Core/PackRepository.php',
    'src/Core/PluginContext.php',
    'src/Core/PluginHttpRequest.php',
    'src/Core/PluginHttpResponse.php',
    'src/Core/PluginManager.php',
    'src/Core/PluginRunner.php',
    'src/Core/PluginStorage.php',
    'src/Core/ReadModel.php',
    'src/Core/RichEmbed.php',
    'src/Core/ScopedMigrationRunner.php',
    'src/Core/ServerPackWriter.php',
    'src/Core/ServerVersion.php',
    'src/Core/SessionService.php',
    'src/Core/SyncPackValidator.php',
    'src/Core/UpdateChecker.php',
    'src/Core/UpdateArchive.php',
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
