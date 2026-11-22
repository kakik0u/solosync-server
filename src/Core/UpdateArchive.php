<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Validates and installs a verified Release ZIP while retaining a file backup. */
final class UpdateArchive
{
    private const MAX_FILES = 400;
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 32 * 1024 * 1024;
    private const REQUIRED = ['.htaccess', 'VERSION', 'public/.htaccess', 'public/index.php',
        'public/admin.html', 'public/admin.js', 'public/admin.css', 'src/bootstrap.php',
        'src/Core/ServerVersion.php', 'src/Core/AutoUpdater.php', 'src/Core/UpdateArchive.php',
        'bin/migrate.php', 'migrations/001_initial.sql', 'migrations/002_plugins.sql'];

    public function __construct(private readonly string $root) {}

    /** @param callable():void $migrate @return array{backup:string,files:int} */
    public function install(string $zipPath, string $version, callable $migrate): array
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP zip extension is required');
        $private = $this->root . '/private';
        if (!is_dir($private) || !is_writable($private)) throw new \RuntimeException('private/ is not writable');
        $nonce = bin2hex(random_bytes(8));
        $stage = $private . '/.update-stage-' . $nonce;
        if (!mkdir($stage, 0700)) throw new \RuntimeException('Cannot create update staging directory');
        $replaced = [];
        $backup = '';
        try {
            $files = $this->stageZip($zipPath, $stage, $version);
            $backup = $private . '/update-backups/' . gmdate('Ymd-His') . '-' . $nonce;
            if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0700, true)) {
                throw new \RuntimeException('Cannot create update backup directory');
            }
            if (!mkdir($backup, 0700)) throw new \RuntimeException('Cannot create update backup');
            foreach ($files as $relative) {
                $target = $this->root . '/' . $relative;
                $this->assertSafeTarget($relative);
                if (is_file($target)) {
                    $saved = $backup . '/' . $relative;
                    $this->ensureDirectory(dirname($saved), 0700);
                    if (!copy($target, $saved)) throw new \RuntimeException('Cannot back up ' . $relative);
                    chmod($saved, 0600);
                }
            }
            foreach ($files as $relative) {
                if ($relative === 'public/index.php' || $relative === 'VERSION') continue;
                $this->replace($stage, $relative);
                $replaced[] = $relative;
            }
            $migrate();
            foreach (['public/index.php', 'VERSION'] as $relative) {
                $this->replace($stage, $relative);
                $replaced[] = $relative;
            }
            foreach ($replaced as $relative) {
                if (str_ends_with($relative, '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($this->root . '/' . $relative, true);
                }
            }
            return ['backup' => $backup, 'files' => count($files)];
        } catch (\Throwable $error) {
            $rollbackFailed = [];
            foreach (array_reverse($replaced) as $relative) {
                $target = $this->root . '/' . $relative;
                $saved = $backup . '/' . $relative;
                if (is_file($saved)) {
                    if (!copy($saved, $target)) $rollbackFailed[] = $relative;
                } elseif (is_file($target) && !unlink($target)) {
                    $rollbackFailed[] = $relative;
                }
                if (str_ends_with($relative, '.php') && function_exists('opcache_invalidate')) {
                    @opcache_invalidate($target, true);
                }
            }
            if ($rollbackFailed) {
                error_log('[Solosync update] Rollback incomplete: ' . implode(', ', $rollbackFailed));
                throw new \RuntimeException('Update failed and file rollback was incomplete; restore from private/update-backups', 0, $error);
            }
            throw $error;
        } finally {
            $this->removeTree($stage);
        }
    }

    /** @return list<string> */
    private function stageZip(string $zipPath, string $stage, string $version): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) throw new \RuntimeException('Release ZIP cannot be opened');
        try {
            if ($zip->numFiles < count(self::REQUIRED) || $zip->numFiles > self::MAX_FILES) {
                throw new \RuntimeException('Release ZIP has an unexpected file count');
            }
            $files = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? ($stat['name'] ?? null) : null;
                if (!is_string($name) || !str_starts_with($name, 'solosync-server/')) {
                    throw new \RuntimeException('Release ZIP contains a path outside solosync-server/');
                }
                $relative = substr($name, strlen('solosync-server/'));
                if (!$this->allowedPath($relative) || isset($files[$relative])) {
                    throw new \RuntimeException('Release ZIP contains an unexpected or duplicate path');
                }
                $size = (int)($stat['size'] ?? -1);
                $total += $size;
                if ($size < 0 || $size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new \RuntimeException('Release ZIP exceeds the extraction limit');
                }
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === \ZipArchive::OPSYS_UNIX) {
                    $type = (($attributes >> 16) & 0170000);
                    if ($type !== 0 && $type !== 0100000) {
                        throw new \RuntimeException('Release ZIP contains a non-regular file');
                    }
                }
                $input = $zip->getStream($name);
                if ($input === false) throw new \RuntimeException('Cannot read Release ZIP entry');
                $outputPath = $stage . '/' . $relative;
                $this->ensureDirectory(dirname($outputPath), 0700);
                $output = fopen($outputPath, 'wb');
                if ($output === false) { fclose($input); throw new \RuntimeException('Cannot stage Release ZIP entry'); }
                try {
                    $copied = stream_copy_to_stream($input, $output, $size + 1);
                    if ($copied !== $size) throw new \RuntimeException('Release ZIP entry has an invalid size');
                } finally {
                    fclose($input);
                    fclose($output);
                }
                $files[$relative] = true;
            }
            foreach (self::REQUIRED as $required) {
                if (!isset($files[$required])) throw new \RuntimeException('Release ZIP is missing ' . $required);
            }
            if (trim((string)file_get_contents($stage . '/VERSION')) !== $version) {
                throw new \RuntimeException('Release ZIP version does not match the Release tag');
            }
            return array_keys($files);
        } finally {
            $zip->close();
        }
    }

    private function allowedPath(string $relative): bool
    {
        if (in_array($relative, ['plugins/webhook/Payload.php', 'plugins/public_channel/BlocksRenderer.php', 'plugins/mcp/Notes.php', 'plugins/mcp/Protocol.php', 'plugins/mcp/Bridge.php'], true)) return true;
        if ($relative === '' || strlen($relative) > 240 || str_contains($relative, '\\')
            || !preg_match('#^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$#D', $relative)) return false;
        foreach (explode('/', $relative) as $part) if ($part === '.' || $part === '..') return false;
        if (in_array($relative, ['.htaccess', 'README.md', 'LICENSE', 'VERSION', 'private/config.example.php'], true)) return true;
        if (preg_match('#^src/(?:bootstrap\.php|Core/[A-Za-z][A-Za-z0-9]*\.php)$#D', $relative)) return true;
        if (preg_match('#^public/(?:\.htaccess|[A-Za-z][A-Za-z0-9_-]*\.(?:php|html|js|css))$#D', $relative)) return true;
        if (preg_match('#^bin/[A-Za-z][A-Za-z0-9_-]*\.php$#D', $relative)) return true;
        if (preg_match('#^migrations/(?:[0-9]{3}_[A-Za-z0-9_-]+|(?:read-model|sync-writer)/[0-9]{3}_[A-Za-z0-9_-]+)\.sql$#D', $relative)) return true;
        if (preg_match('#^docs/plugins/[A-Za-z0-9_-]+\.md$#D', $relative)) return true;
        if (preg_match('#^plugins/([a-z][a-z0-9_]{0,30})/(?:plugin\.php|manifest\.json|migrations/[0-9]{3}_[A-Za-z0-9_-]+\.sql)$#D', $relative, $m)) {
            $existing = $this->root . '/plugins/' . $m[1];
            return in_array($m[1], ['example', 'webhook', 'public_channel', 'mcp'], true) || !file_exists($existing);
        }
        return false;
    }

    private function assertSafeTarget(string $relative): void
    {
        $parts = explode('/', $relative);
        $path = $this->root;
        foreach ($parts as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new \RuntimeException('Update target is a symlink: ' . $relative);
        }
        if (file_exists($path) && !is_file($path)) throw new \RuntimeException('Update target is not a file: ' . $relative);
        $parent = dirname($path);
        while (!is_dir($parent) && $parent !== $this->root) $parent = dirname($parent);
        if (!is_writable($parent)) throw new \RuntimeException('Update target is not writable: ' . $relative);
    }

    private function replace(string $stage, string $relative): void
    {
        $source = $stage . '/' . $relative;
        $target = $this->root . '/' . $relative;
        $this->ensureDirectory(dirname($target), 0775);
        $mode = is_file($target) ? (fileperms($target) & 0777) : 0644;
        chmod($source, $mode);
        if (!rename($source, $target)) throw new \RuntimeException('Cannot install ' . $relative);
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Cannot create update directory');
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($path);
    }
}
