<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Installs only a verified, newer release from the official Solosync repository. */
final class AutoUpdater
{
    private const RELEASE_API = 'https://api.github.com/repos/kakik0u/solosync-server/releases/latest';
    private const MAX_ZIP_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly \PDO $db, private readonly string $root) {}

    /** @return array{available:bool,reason:string,installedVersion:string} */
    public function preflight(): array
    {
        $installed = ServerVersion::installed($this->root);
        $reason = '';
        if (!extension_loaded('curl')) $reason = 'PHP cURL 拡張が必要です。';
        elseif (!class_exists(\ZipArchive::class)) $reason = 'PHP zip 拡張が必要です。';
        elseif (!is_dir($this->root . '/private') || !is_writable($this->root . '/private')) $reason = 'private/ に書き込めません。';
        elseif (($free = disk_free_space($this->root)) === false || $free < 100 * 1024 * 1024) $reason = '更新用の空き容量を確認できないか、不足しています（100 MB 必要）。';
        elseif (!is_writable($this->root)) $reason = 'サーバーファイルを置換する権限がありません。';
        elseif (!is_writable($this->root . '/public') || !is_writable($this->root . '/src')) {
            $reason = 'public/ または src/ に書き込めません。';
        }
        return ['available' => $reason === '', 'reason' => $reason, 'installedVersion' => $installed];
    }

    /** @return array{version:string,files:int,backup:string,migrations:list<string>} */
    public function install(string $expectedTag): array
    {
        $preflight = $this->preflight();
        if (!$preflight['available']) throw new \RuntimeException($preflight['reason']);
        if (!preg_match('/^v?\d+\.\d+\.\d+$/D', $expectedTag)) throw new \RuntimeException('Invalid release tag');
        $lock = fopen($this->root . '/private/update.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another update is already running');
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) @set_time_limit(120);
        $zipPath = $this->root . '/private/.update-download-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $release = $this->release();
            $tag = $release['tag_name'];
            $version = ltrim($tag, 'v');
            if ($tag !== $expectedTag) throw new \RuntimeException('The latest release changed. Check for updates again.');
            if (version_compare($version, ServerVersion::installed($this->root), '<=')) {
                throw new \RuntimeException('This release is already installed');
            }
            $asset = $this->asset($release, $tag);
            $this->download($asset['browser_download_url'], $zipPath, $asset['size']);
            if (!hash_equals(substr($asset['digest'], 7), hash_file('sha256', $zipPath))) {
                throw new \RuntimeException('Release ZIP SHA-256 digest did not match GitHub metadata');
            }
            $migrations = [];
            $result = (new UpdateArchive($this->root))->install($zipPath, $version,
                function () use (&$migrations): void {
                    $migrations = $this->applyMigrations();
                });
            return ['version' => $version, 'files' => $result['files'], 'backup' => $result['backup'],
                'migrations' => $migrations];
        } finally {
            if (is_file($zipPath)) unlink($zipPath);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Use the same scoped-schema upgrade as a manual bin/migrate.php run. */
    private function applyMigrations(): array
    {
        return (new AdminService($this->db, Config::load($this->root . '/private/config.php')))
            ->install($this->root . '/migrations');
    }

    /** @return array<string,mixed> */
    private function release(): array
    {
        $response = $this->request(self::RELEASE_API, null, 262144);
        $release = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($release) || ($release['draft'] ?? true) || ($release['prerelease'] ?? true)
            || !is_string($release['tag_name'] ?? null)
            || !preg_match('/^v?\d+\.\d+\.\d+$/D', $release['tag_name'])) {
            throw new \RuntimeException('GitHub release metadata is invalid');
        }
        return $release;
    }

    /** @param array<string,mixed> $release @return array{browser_download_url:string,size:int,digest:string} */
    private function asset(array $release, string $tag): array
    {
        $name = 'solosync-server-' . $tag . '.zip';
        foreach ($release['assets'] ?? [] as $asset) {
            if (!is_array($asset) || ($asset['name'] ?? null) !== $name) continue;
            $url = $asset['browser_download_url'] ?? null;
            $size = $asset['size'] ?? null;
            $digest = $asset['digest'] ?? null;
            $expectedUrl = 'https://github.com/kakik0u/solosync-server/releases/download/' . $tag . '/' . $name;
            if ($url !== $expectedUrl || !is_int($size) || $size < 1 || $size > self::MAX_ZIP_BYTES
                || !is_string($digest) || !preg_match('/^sha256:[a-f0-9]{64}$/D', $digest)) {
                throw new \RuntimeException('Release ZIP has no verifiable SHA-256 digest or exceeds the size limit');
            }
            return ['browser_download_url' => $url, 'size' => $size, 'digest' => $digest];
        }
        throw new \RuntimeException('No official server ZIP exists in the latest release');
    }

    private function download(string $url, string $path, int $expectedSize): void
    {
        $file = fopen($path, 'wb');
        if ($file === false) throw new \RuntimeException('Cannot create update download');
        try {
            $this->request($url, $file, self::MAX_ZIP_BYTES);
        } finally {
            fclose($file);
        }
        if (filesize($path) !== $expectedSize) throw new \RuntimeException('Release ZIP size did not match GitHub metadata');
    }

    /** @param resource|null $output */
    private function request(string $url, $output, int $maxBytes): string
    {
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https'
                || !in_array(parse_url($url, PHP_URL_HOST),
                    ['api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) {
                throw new \RuntimeException('Release download redirected to an untrusted host');
            }
            $curl = curl_init($url);
            if ($curl === false) throw new \RuntimeException('Cannot start GitHub request');
            $body = '';
            $bytes = 0;
            $location = null;
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_USERAGENT => 'Solosync-Server-Updater',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2026-03-10'],
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$location): int {
                    if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$bytes, $output, $maxBytes): int {
                    $length = strlen($chunk);
                    $bytes += $length;
                    if ($bytes > $maxBytes) return 0;
                    if ($output !== null) return fwrite($output, $chunk) ?: 0;
                    $body .= $chunk;
                    return $length;
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'https');
            else curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            try {
                $ok = curl_exec($curl);
                $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if (!$ok) throw new \RuntimeException('GitHub download failed (HTTP ' . $status . ')');
            } finally {
                curl_close($curl);
            }
            if ($status === 200) return $body;
            if (!in_array($status, [301, 302, 303, 307, 308], true) || !is_string($location)) {
                throw new \RuntimeException('GitHub download failed (HTTP ' . $status . ')');
            }
            $url = $location;
            if ($output !== null) { ftruncate($output, 0); rewind($output); }
        }
        throw new \RuntimeException('Release download redirected too many times');
    }
}
