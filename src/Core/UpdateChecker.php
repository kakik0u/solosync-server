<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** Admin-only, best-effort check against this project's public GitHub releases. */
final class UpdateChecker
{
    private const API_URL = 'https://api.github.com/repos/kakik0u/solosync-server/releases/latest';
    private const RELEASE_PREFIX = 'https://github.com/kakik0u/solosync-server/releases/';

    public function __construct(private readonly \PDO $db) {}

    /** @return array<string,mixed> */
    public function status(): array
    {
        $row = $this->db->query('SELECT latest_tag,release_url,etag,checked_at,last_error FROM update_check_cache WHERE id=1')->fetch();
        $installed = ServerVersion::installed();
        $latest = is_array($row) ? $row['latest_tag'] : null;
        return ['installedVersion' => $installed, 'versionFileMissing' => !ServerVersion::versionFileValid(), 'latestVersion' => $latest,
            'updateAvailable' => is_string($latest) && ($row['last_error'] ?? null) === null
                && version_compare(ltrim($latest, 'v'), ltrim($installed, 'v'), '>'),
            'releaseUrl' => is_array($row) ? $row['release_url'] : null,
            'checkedAt' => is_array($row) ? $row['checked_at'] : null,
            'error' => is_array($row) ? $row['last_error'] : null];
    }

    /** @return array<string,mixed> */
    public function check(): array
    {
        $previous = $this->db->query('SELECT etag FROM update_check_cache WHERE id=1')->fetch();
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: Solosync-Server',
            'X-GitHub-Api-Version: 2026-03-10',
        ];
        if (is_array($previous) && is_string($previous['etag']) && $previous['etag'] !== '') {
            $headers[] = 'If-None-Match: ' . str_replace(["\r", "\n"], '', $previous['etag']);
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 5,
            'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $body = @file_get_contents(self::API_URL, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0] ?? '', $m)) $status = (int)$m[1];
        try {
            if ($status === 304) {
                $this->db->exec("UPDATE update_check_cache SET checked_at=UTC_TIMESTAMP(6),last_error=NULL WHERE id=1");
                return $this->status();
            }
            if ($status !== 200 || !is_string($body) || strlen($body) > 262144) {
                throw new \RuntimeException('GitHub release check returned HTTP ' . $status);
            }
            $release = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $tag = $release['tag_name'] ?? null;
            $url = $release['html_url'] ?? null;
            if (!is_string($tag) || !preg_match('/^v?\d+\.\d+\.\d+$/D', $tag)
                || !is_string($url) || !str_starts_with($url, self::RELEASE_PREFIX)
                || ($release['draft'] ?? true) || ($release['prerelease'] ?? true)) {
                throw new \RuntimeException('GitHub release metadata is invalid');
            }
            $etag = null;
            foreach ($responseHeaders as $header) {
                if (stripos($header, 'ETag:') === 0) $etag = trim(substr($header, 5));
            }
            $this->db->prepare('INSERT INTO update_check_cache(id,latest_tag,release_url,etag,checked_at,last_error)
                VALUES (1,?,?,?,UTC_TIMESTAMP(6),NULL) ON DUPLICATE KEY UPDATE
                latest_tag=VALUES(latest_tag),release_url=VALUES(release_url),etag=VALUES(etag),
                checked_at=VALUES(checked_at),last_error=NULL')->execute([$tag, $url, $etag]);
        } catch (\Throwable $error) {
            $this->db->prepare('INSERT INTO update_check_cache(id,checked_at,last_error)
                VALUES (1,UTC_TIMESTAMP(6),?) ON DUPLICATE KEY UPDATE checked_at=VALUES(checked_at),last_error=VALUES(last_error)')
                ->execute([substr($error->getMessage(), 0, 1000)]);
        }
        return $this->status();
    }
}
