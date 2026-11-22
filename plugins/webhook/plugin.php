<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\PluginContext;
use Solosync\SyncServer\Core\PluginHttpRequest;
use Solosync\SyncServer\Core\PluginHttpResponse;
use Solosync\SyncServer\Core\PluginStorage;
use Solosync\SyncServer\Plugins\Webhook\Payload;

require_once __DIR__ . '/Payload.php';

return new class {
    public function handleHttp(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        return $request->admin ? $this->admin($context, $request) : $this->receive($context, $request);
    }

    private function admin(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        $storage = $context->storage();
        if ($request->method === 'GET' && $request->path === '/endpoints') {
            $rows = $storage->all('SELECT endpoint_id,label,channel_id,created_at FROM plugin_webhook_endpoints
                WHERE revoked_at IS NULL ORDER BY created_at DESC');
            return PluginHttpResponse::json(200, ['endpoints' => array_map(static fn(array $r): array => [
                'id' => $r['endpoint_id'], 'label' => $r['label'], 'channelId' => $r['channel_id'],
                'createdAt' => $r['created_at'], 'path' => '/v1/plugins/webhook/' . $r['endpoint_id'],
            ], $rows)]);
        }
        if ($request->method === 'POST' && $request->path === '/endpoints') {
            $body = $request->json();
            $label = trim((string)($body['label'] ?? ''));
            $channelId = $body['channelId'] ?? null;
            if ($label === '' || strlen($label) > 120 || !is_string($channelId) || $channelId === '') {
                throw new ApiException(400, 'INVALID_ENDPOINT', 'Name and channel are required');
            }
            $context->readModel()->step(20);
            if (!$context->readModel()->channel($channelId)) throw new ApiException(404, 'CHANNEL_NOT_FOUND', 'Channel not found');
            $id = bin2hex(random_bytes(16));
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $storage->execute('INSERT INTO plugin_webhook_endpoints(endpoint_id,label,channel_id,secret_hash) VALUES (?,?,?,?)',
                [$id, $label, $channelId, hash('sha256', $secret, true)]);
            return PluginHttpResponse::json(201, ['id' => $id, 'secret' => $secret,
                'path' => '/v1/plugins/webhook/' . $id . '/' . $secret]);
        }
        if ($request->method === 'POST' && preg_match('#^/endpoints/([0-9a-f]{32})/revoke$#D', $request->path, $m)) {
            $changed = $storage->execute('UPDATE plugin_webhook_endpoints SET revoked_at=UTC_TIMESTAMP(6)
                WHERE endpoint_id=? AND revoked_at IS NULL', [$m[1]]);
            return PluginHttpResponse::json(200, ['revoked' => $changed > 0]);
        }
        throw new ApiException(404, 'NOT_FOUND', 'Webhook admin route not found');
    }

    private function receive(PluginContext $context, PluginHttpRequest $request): PluginHttpResponse
    {
        if ($request->method !== 'POST' || !preg_match('#^/([0-9a-f]{32})(?:/([A-Za-z0-9_-]{43}))?$#D', $request->path, $m)) {
            throw new ApiException(404, 'NOT_FOUND', 'Webhook route not found');
        }
        if (!$context->requestIsHttps()) throw new ApiException(400, 'HTTPS_REQUIRED', 'HTTPS is required for webhook requests');
        $storage = $context->storage();
        $endpoint = $storage->first('SELECT endpoint_id,channel_id,secret_hash FROM plugin_webhook_endpoints
            WHERE endpoint_id=? AND revoked_at IS NULL', [$m[1]]);
        if (!$endpoint) throw new ApiException(404, 'ENDPOINT_NOT_FOUND', 'Webhook endpoint not found');
        $authorization = $request->header('authorization');
        $urlToken = $m[2] ?? null;
        $secret = $urlToken ?? (str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '');
        if ($secret === '' || !hash_equals((string)$endpoint['secret_hash'], hash('sha256', $secret, true))) {
            throw new ApiException(401, 'INVALID_WEBHOOK_SECRET', 'Invalid webhook secret');
        }
        if (!str_starts_with(strtolower($request->header('content-type')), 'application/json')) {
            throw new ApiException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Expected application/json');
        }
        $key = $request->header('idempotency-key');
        if (strlen($key) > 128) throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key is too long');
        $message = Payload::parse($request->jsonObject());
        $content = $message['content'];
        $wait = $request->query['wait'] ?? 'false';
        if (!in_array($wait, ['true', 'false', '1', '0'], true)) {
            throw new ApiException(400, 'INVALID_QUERY', 'wait must be true or false');
        }
        if (!$context->readModel()->status()['ready']) $context->readModel()->step(10);
        // Omitted keys create separate posts, including repeated identical text.
        $keyHash = $key === '' ? random_bytes(32) : hash('sha256', $key, true);
        $contentHash = Payload::fingerprint($message);
        $maxPerMinute = $context->settings['maxPerMinute'] ?? 30;
        $maxPerMinute = is_int($maxPerMinute) ? max(1, min(120, $maxPerMinute)) : 30;
        $result = $context->writer()->createMessage($storage, (string)$endpoint['channel_id'], $content,
            static function (PluginStorage $store) use ($endpoint, $keyHash, $contentHash, $maxPerMinute): ?array {
                $locked = $store->first('SELECT endpoint_id,secret_hash FROM plugin_webhook_endpoints
                    WHERE endpoint_id=? AND revoked_at IS NULL FOR UPDATE', [$endpoint['endpoint_id']]);
                if (!$locked) throw new ApiException(404, 'ENDPOINT_NOT_FOUND', 'Webhook endpoint not found');
                if (!hash_equals((string)$locked['secret_hash'], (string)$endpoint['secret_hash'])) {
                    throw new ApiException(401, 'INVALID_WEBHOOK_SECRET', 'Invalid webhook secret');
                }
                $store->execute('DELETE FROM plugin_webhook_requests
                    WHERE endpoint_id=? AND created_at<UTC_TIMESTAMP(6) - INTERVAL 30 DAY LIMIT 100',
                    [$endpoint['endpoint_id']]);
                $existing = $store->first('SELECT content_hash,message_id,pack_id FROM plugin_webhook_requests
                    WHERE endpoint_id=? AND idempotency_hash=?', [$endpoint['endpoint_id'], $keyHash]);
                if ($existing) {
                    if (!hash_equals((string)$existing['content_hash'], $contentHash)) {
                        throw new ApiException(409, 'IDEMPOTENCY_CONFLICT', 'Idempotency-Key was used for different content');
                    }
                    return ['messageId' => $existing['message_id'], 'packId' => $existing['pack_id'], 'created' => false];
                }
                $count = $store->first('SELECT COUNT(*) AS count FROM plugin_webhook_requests
                    WHERE endpoint_id=? AND created_at > UTC_TIMESTAMP(6) - INTERVAL 1 MINUTE', [$endpoint['endpoint_id']]);
                if ((int)($count['count'] ?? 0) >= $maxPerMinute) {
                    throw new ApiException(429, 'WEBHOOK_RATE_LIMIT', 'Webhook rate limit exceeded');
                }
                return null;
            },
            static function (PluginStorage $store, array $created) use ($endpoint, $keyHash, $contentHash): void {
                $store->execute('INSERT INTO plugin_webhook_requests
                    (endpoint_id,idempotency_hash,content_hash,message_id,pack_id) VALUES (?,?,?,?,?)',
                    [$endpoint['endpoint_id'], $keyHash, $contentHash, $created['messageId'], $created['packId']]);
            }, $message['embeds']);
        if ($urlToken !== null) {
            if ($wait === 'false' || $wait === '0') return new PluginHttpResponse(204, '');
            return PluginHttpResponse::json(200, ['id' => $result['messageId'], 'channel_id' => $endpoint['channel_id'],
                'webhook_id' => $endpoint['endpoint_id'], 'content' => $content, 'attachments' => [],
                'embeds' => array_map(Payload::toDiscord(...), $message['embeds'])]);
        }
        // Preserve the original Bearer endpoint response for existing senders.
        return PluginHttpResponse::json($result['created'] ? 201 : 200,
            ['messageId' => $result['messageId'], 'packId' => $result['packId']]);
    }
};
