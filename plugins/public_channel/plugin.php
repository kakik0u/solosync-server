<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\PluginContext;
use Solosync\SyncServer\Core\PluginHttpRequest;
use Solosync\SyncServer\Core\PluginHttpResponse;
use Solosync\SyncServer\Plugins\PublicChannel\BlocksRenderer;

require_once __DIR__ . '/BlocksRenderer.php';

return new class {
    public function handleHttp(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        return $request->admin ? $this->admin($context, $request) : $this->page($context, $request);
    }

    private function admin(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        $storage = $context->storage();
        if ($request->method === 'GET' && $request->path === '/shares') {
            $rows = $storage->all('SELECT public_id,channel_id,label,enabled,created_at FROM plugin_public_channel_shares
                ORDER BY created_at DESC');
            return PluginHttpResponse::json(200, ['shares' => array_map(static fn(array $r): array => [
                'id' => $r['public_id'], 'channelId' => $r['channel_id'], 'label' => $r['label'],
                'enabled' => (bool)$r['enabled'], 'createdAt' => $r['created_at'],
                'path' => '/p/public_channel/' . $r['public_id'],
            ], $rows)]);
        }
        if ($request->method === 'POST' && $request->path === '/shares') {
            $body = $request->json();
            $channelId = $body['channelId'] ?? null;
            if (!is_string($channelId) || $channelId === '') throw new ApiException(400, 'INVALID_CHANNEL', 'Channel is required');
            $context->readModel()->step(20);
            $channel = $context->readModel()->channel($channelId);
            if (!$channel) throw new ApiException(404, 'CHANNEL_NOT_FOUND', 'Channel not found');
            $label = trim((string)($body['label'] ?? $channel['name']));
            if ($label === '' || strlen($label) > 120) throw new ApiException(400, 'INVALID_LABEL', 'Invalid label');
            $id = bin2hex(random_bytes(16));
            $storage->execute('INSERT INTO plugin_public_channel_shares(public_id,channel_id,label) VALUES (?,?,?)',
                [$id, $channelId, $label]);
            return PluginHttpResponse::json(201, ['id' => $id, 'path' => '/p/public_channel/' . $id]);
        }
        if ($request->method === 'POST' && preg_match('#^/shares/([0-9a-f]{32})/disable$#D', $request->path, $m)) {
            $changed = $storage->execute('UPDATE plugin_public_channel_shares SET enabled=0,updated_at=UTC_TIMESTAMP(6)
                WHERE public_id=? AND enabled=1', [$m[1]]);
            return PluginHttpResponse::json(200, ['disabled' => $changed > 0]);
        }
        if ($request->method === 'POST' && preg_match('#^/shares/([0-9a-f]{32})/enable$#D', $request->path, $m)) {
            $share = $storage->first('SELECT channel_id FROM plugin_public_channel_shares WHERE public_id=?', [$m[1]]);
            if (!$share || !$context->readModel()->channel((string)$share['channel_id'])) {
                throw new ApiException(404, 'CHANNEL_NOT_FOUND', 'Channel not found');
            }
            $storage->execute('UPDATE plugin_public_channel_shares SET enabled=1,updated_at=UTC_TIMESTAMP(6) WHERE public_id=?', [$m[1]]);
            return PluginHttpResponse::json(200, ['enabled' => true]);
        }
        if ($request->method === 'POST' && preg_match('#^/shares/([0-9a-f]{32})/rotate$#D', $request->path, $m)) {
            $newId = bin2hex(random_bytes(16));
            $changed = $storage->execute('UPDATE plugin_public_channel_shares SET public_id=?,updated_at=UTC_TIMESTAMP(6)
                WHERE public_id=?', [$newId, $m[1]]);
            if ($changed === 0) throw new ApiException(404, 'SHARE_NOT_FOUND', 'Share not found');
            return PluginHttpResponse::json(200, ['id' => $newId, 'path' => '/p/public_channel/' . $newId]);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Public channel admin route not found');
    }

    private function page(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        if ($request->method !== 'GET' || !preg_match('#^/([0-9a-f]{32})$#D', $request->path, $m)) {
            throw new ApiException(404, 'NOT_FOUND', 'Public channel not found');
        }
        $share = $context->storage()->first('SELECT channel_id,label FROM plugin_public_channel_shares
            WHERE public_id=? AND enabled=1', [$m[1]]);
        if (!$share) throw new ApiException(404, 'SHARE_NOT_FOUND', 'Public channel not found');
        $model = $context->readModel();
        if (!$model->status()['ready']) $model->step(10);
        $channel = $model->channel((string)$share['channel_id']);
        if (!$channel) throw new ApiException(404, 'CHANNEL_NOT_FOUND', 'Public channel not found');
        $before = $request->query['before'] ?? null;
        if ($before !== null && (!is_string($before) || strlen($before) > 256)) {
            throw new ApiException(400, 'INVALID_CURSOR', 'Invalid page cursor');
        }
        $messages = $model->messages((string)$share['channel_id'], 41, $before);
        $more = count($messages) > 40;
        $messages = array_slice($messages, 0, 40);
        $title = self::escape((string)$channel['name']);
        $html = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $title . ' - Solosync</title><style>' . BlocksRenderer::styles() . '</style></head><body>'
            . '<main><h1>' . $title . '</h1><p>読み取り専用の公開チャンネル</p>';
        foreach ($messages as $message) {
            $html .= '<article><h2>' . self::escape((string)$message['author']) . '</h2><time>'
                . self::escape((string)$message['timestamp']) . '</time><p>'
                . nl2br(self::escape((string)$message['content']), false)
                . ($message['truncated'] ? '<br>（長いメモは省略しています）' : '') . '</p>'
                . BlocksRenderer::render($message['blocks_json'], (bool)$message['blocks_truncated']) . '</article><hr>';
        }
        if ($more && $messages) {
            $last = end($messages);
            $cursor = rtrim(strtr(base64_encode(json_encode([(int)$last['timestamp_ms'], $last['id']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $html .= '<a href="?before=' . rawurlencode($cursor) . '">前のメモ</a>';
        }
        $html .= '</main></body></html>';
        return new PluginHttpResponse(200, $html, 'text/html; charset=utf-8', [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src http: https:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
};
