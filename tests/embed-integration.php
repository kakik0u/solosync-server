<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\AutoUpdater;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\MaintenanceService;
use Solosync\SyncServer\Core\PluginContext;
use Solosync\SyncServer\Core\PluginHttpRequest;
use Solosync\SyncServer\Core\RichEmbed;
use Solosync\SyncServer\Plugins\Webhook\Payload;

$fixtures = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rich-embeds.json'), true, 32, JSON_THROW_ON_ERROR);
$endpoint = json_decode($webhook->handleHttp($webhookContext, new PluginHttpRequest('POST', '/endpoints', [], [],
    json_encode(['label' => 'embeds', 'channelId' => 'channel-1'], JSON_THROW_ON_ERROR), true))->body, true, 32, JSON_THROW_ON_ERROR);
$urlPath = substr($endpoint['path'], strlen('/v1/plugins/webhook'));
$embedBody = $fixtures[0]['request'];
$embedBody['embeds'][] = ['title' => 'Second Embed'];
$embedRequest = new PluginHttpRequest('POST', $urlPath, ['wait' => 'true'],
    ['content-type' => 'application/json', 'idempotency-key' => 'embed-integration'], json_encode($embedBody, JSON_THROW_ON_ERROR), false);
$created = $webhook->handleHttp($webhookContext, $embedRequest);
$repeated = $webhook->handleHttp($webhookContext, $embedRequest);
$response = json_decode($created->body, true, 32, JSON_THROW_ON_ERROR);
if ($created->status !== 200 || $repeated->body !== $created->body || $response['content'] !== '' || count($response['embeds']) !== 2) {
    throw new RuntimeException('Embed-only webhook wait/retry failed');
}
$record = $db->prepare('SELECT pack_id FROM plugin_webhook_requests WHERE message_id=?');
$record->execute([$response['id']]);
$embedPackId = $record->fetchColumn();
$embedPack = json_decode($repo->get($embedPackId), true, 32, JSON_THROW_ON_ERROR);
$blockJson = $embedPack['operations'][0]['data']['blocks_json'];
$blockEnvelope = json_decode($blockJson, true, 32, JSON_THROW_ON_ERROR);
if (count($blockEnvelope['blocks']) !== 2 || $blockEnvelope['blocks'][0]['payload'] !== Payload::parse($embedBody)['embeds'][0]
    || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $blockEnvelope['blocks'][0]['id'])
    || $blockEnvelope['blocks'][0]['id'] === $blockEnvelope['blocks'][1]['id']) {
    throw new RuntimeException('Server Embed blocks do not match Solocord envelope/UUID contract');
}
$changed = $embedBody;
$changed['embeds'][0]['description'] = 'changed';
$conflict = false;
try { $webhook->handleHttp($webhookContext, new PluginHttpRequest('POST', $urlPath, [], $embedRequest->headers, json_encode($changed, JSON_THROW_ON_ERROR), false)); }
catch (ApiException $error) { $conflict = $error->apiCode === 'IDEMPOTENCY_CONFLICT'; }
if (!$conflict) throw new RuntimeException('Same-key Embed change did not conflict');
$model->step(100);
$projected = $db->prepare('SELECT blocks_json FROM projection_messages WHERE id=?');
$projected->execute([$response['id']]);
if ($projected->fetchColumn() !== $blockJson) throw new RuntimeException('Embed JSON was not projected intact');
$page = $public->handleHttp($publicContext, new PluginHttpRequest('GET', '/' . $rotatedShare['id'], [], [], '', false));
if (!str_contains($page->body, 'リリース &lt;確認&gt;') || !str_contains($page->body, 'Second Embed')
    || strpos($page->body, 'リリース &lt;確認&gt;') > strpos($page->body, 'Second Embed')
    || ($page->headers['Referrer-Policy'] ?? null) !== 'no-referrer'
    || !str_contains($page->headers['Content-Security-Policy'] ?? '', 'img-src http: https:')) {
    throw new RuntimeException('Public Embed card order, escaping or response policy failed');
}
if (($artifactDir = getenv('SOLOSYNC_TEST_ARTIFACT_DIR')) !== false && $artifactDir !== '') {
    file_put_contents($artifactDir . '/public-preview.html', $page->body);
    file_put_contents($artifactDir . '/server-blocks.json', $blockJson);
}

// A plugin using the original five arguments remains compatible. The new
// optional sixth argument uses Solocord payloads, not arbitrary blocks JSON.
$writerResult = $webhookContext->writer()->createMessage($webhookContext->storage(), 'channel-1', 'Mixed body 😀', null, null,
    [['title' => 'Managed image', 'image' => ['attachmentId' => 'private-image']]]);
$model->step(100);
$page = $public->handleHttp($publicContext, new PluginHttpRequest('GET', '/' . $rotatedShare['id'], [], [], '', false));
if (!str_contains($page->body, 'Mixed body 😀') || !str_contains($page->body, '添付画像') || str_contains($page->body, 'private-image')) {
    throw new RuntimeException('Plugin writer mixed body/attachment placeholder failed');
}

// Exercise snapshots through actual packs and LWW, never direct projection writes.
$updateMessage = static function (array $fields, string $operation = 'UPSERT') use ($repo, $groupId, $response, $embedPack): void {
    static $cursor = 100;
    $cursor++;
    $id = 'embed-update-' . $cursor;
    $data = [...$embedPack['operations'][0]['data'], ...$fields];
    if (!array_key_exists('blocks_json', $fields)) unset($data['blocks_json']);
    $pack = ['formatVersion' => 1, 'groupId' => $groupId, 'packId' => $id, 'uploaderDeviceId' => 'device-a',
        'createdAtMs' => 2000 + $cursor, 'fromLocalCursor' => $cursor - 1, 'toLocalCursor' => $cursor,
        'operations' => [['operationId' => $id, 'originDeviceId' => 'device-a', 'table' => 'messages', 'rowId' => $response['id'],
            'operation' => $operation, 'executedAtMs' => $embedPack['createdAtMs'] + $cursor, 'logicalClock' => $cursor, 'data' => $data]]];
    $bytes = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $repo->put($id, $bytes, hash('sha256', $bytes), 'device-a');
};
$updateMessage(['content' => 'Edited body, omitted blocks']);
$model->step(100);

$projected->execute([$response['id']]);
if ($projected->fetchColumn() !== $blockJson) throw new RuntimeException('Omitted blocks erased existing Embed');
$updateMessage(['blocks_json' => RichEmbed::EMPTY_BLOCKS]);
$model->step(100);
$projected->execute([$response['id']]);
if ($projected->fetchColumn() !== RichEmbed::EMPTY_BLOCKS) throw new RuntimeException('Explicit empty blocks did not clear Embed');
$updateMessage(['blocks_json' => '{broken', 'content' => 'Body survives corrupt blocks']);
$model->step(100);
$page = $public->handleHttp($publicContext, new PluginHttpRequest('GET', '/' . $rotatedShare['id'], [], [], '', false));
if (!str_contains($page->body, 'Body survives corrupt blocks') || !str_contains($page->body, '表示できません')) {
    throw new RuntimeException('Corrupt blocks broke body/public page');
}
$updateMessage(['blocks_json' => str_repeat('x', 1048577), 'content' => 'Body survives oversized blocks']);
$model->step(100);
$messages = $model->messages('channel-1', 100);
$large = array_values(array_filter($messages, static fn(array $m): bool => $m['id'] === $response['id']))[0];
if ($large['blocks_json'] !== null || !$large['blocks_truncated'] || !str_contains($large['content'], 'oversized')) {
    throw new RuntimeException('Read model returned oversized JSON or hid its body');
}
$updateMessage(['blocks_json' => $blockJson]);
$model->step(100);

// Simulate revision 2 on an already-enabled installation. Both the updater's
// real migration callback and cron maintenance must add the column/replay packs.
$downgradeProjection = static function () use ($db): void {
    $db->exec('ALTER TABLE projection_messages DROP COLUMN blocks_json');
    $db->exec("DELETE FROM plugin_schema_migrations WHERE plugin_id='@read-model' AND version='003_message_blocks.sql'");
    $db->exec('UPDATE projection_revision SET revision=2 WHERE id=1');
};
$downgradeProjection();
$updateRoot = sys_get_temp_dir() . '/solosync-upgrade-' . bin2hex(random_bytes(6));
mkdir($updateRoot, 0700);
mkdir($updateRoot . '/private', 0700);
mkdir($updateRoot . '/migrations', 0700);
symlink($root . '/migrations/read-model', $updateRoot . '/migrations/read-model');
copy($tmp, $updateRoot . '/private/config.php');
try {
    (new ReflectionMethod(AutoUpdater::class, 'applyMigrations'))->invoke(new AutoUpdater($db, $updateRoot));
    if (!$manager->isEnabled('webhook') || !$manager->isEnabled('public_channel')) throw new RuntimeException('Upgrade disabled existing plugins');
    $model->step(100);
    $projected->execute([$response['id']]);
    if (!$model->status()['ready'] || $projected->fetchColumn() !== $blockJson) throw new RuntimeException('Updater migration/replay lost Embed');
} finally {
    unlink($updateRoot . '/private/config.php');
    unlink($updateRoot . '/migrations/read-model');
    rmdir($updateRoot . '/private');
    rmdir($updateRoot . '/migrations');
    rmdir($updateRoot);
}
$downgradeProjection();
(new MaintenanceService($db, $config))->run(100);
$projected->execute([$response['id']]);
if (!$model->status()['ready'] || $model->status()['revision'] !== \Solosync\SyncServer\Core\ReadModel::REVISION || $projected->fetchColumn() !== $blockJson) {
    throw new RuntimeException('Maintenance schema upgrade/replay lost Embed');
}
$updateMessage([], 'DELETE');
$model->step(100);
$projected->execute([$response['id']]);
if ($projected->fetchColumn() !== false) throw new RuntimeException('Deleted Embed message remained projected');
$model->rebuild();
$model->step(100);
$projected->execute([$response['id']]);
if ($projected->fetchColumn() !== false) throw new RuntimeException('Rebuild resurrected deleted Embed message');

// Revoke after a valid payload is constructed; revocation remains effective.
$webhook->handleHttp($webhookContext, new PluginHttpRequest('POST', '/endpoints/' . $endpoint['id'] . '/revoke', [], [], '{}', true));
$rejected = false;
try { $webhook->handleHttp($webhookContext, $embedRequest); }
catch (ApiException $error) { $rejected = $error->apiCode === 'ENDPOINT_NOT_FOUND'; }
if (!$rejected) throw new RuntimeException('Revoked endpoint accepted Embed');

// Count the text and Embed paths together under the same endpoint rate limit.
$rateContext = new PluginContext($db, $config, 'webhook', ['maxPerMinute' => 1], ['read-model','sync-writer','http']);
$rateEndpoint = json_decode($webhook->handleHttp($rateContext, new PluginHttpRequest('POST', '/endpoints', [], [],
    json_encode(['label' => 'rate', 'channelId' => 'channel-1'], JSON_THROW_ON_ERROR), true))->body, true, 32, JSON_THROW_ON_ERROR);
$ratePath = substr($rateEndpoint['path'], strlen('/v1/plugins/webhook'));
$webhook->handleHttp($rateContext, new PluginHttpRequest('POST', $ratePath, [], ['content-type' => 'application/json'], '{"content":"first"}', false));
$rateRejected = false;
try { $webhook->handleHttp($rateContext, new PluginHttpRequest('POST', $ratePath, [], ['content-type' => 'application/json'], json_encode($embedBody, JSON_THROW_ON_ERROR), false)); }
catch (ApiException $error) { $rateRejected = $error->apiCode === 'WEBHOOK_RATE_LIMIT'; }
if (!$rateRejected) throw new RuntimeException('Embed bypassed shared webhook rate limit');
$model->step(100);

// Public paging still uses the existing timestamp/id cursor with block data.
$paginationPack = ['formatVersion' => 1, 'groupId' => $groupId, 'packId' => 'embed-paging', 'uploaderDeviceId' => 'device-a',
    'createdAtMs' => 5000, 'fromLocalCursor' => 200, 'toLocalCursor' => 242, 'operations' => []];
for ($i = 1; $i <= 42; $i++) {
    $id = sprintf('PAGE-%02d', $i);
    $paginationPack['operations'][] = ['operationId' => $id, 'originDeviceId' => 'device-a', 'table' => 'messages', 'rowId' => $id,
        'operation' => 'UPSERT', 'executedAtMs' => 5000 + $i, 'logicalClock' => 1,
        'data' => ['id' => $id, 'channel_id' => 'channel-1', 'content' => $id, 'timestamp' => sprintf('2026-12-01T00:00:%02d.000Z', $i),
            'blocks_json' => $i === 42 ? $blockJson : RichEmbed::EMPTY_BLOCKS]];
}
$bytes = json_encode($paginationPack, JSON_THROW_ON_ERROR);
$repo->put('embed-paging', $bytes, hash('sha256', $bytes), 'device-a');
$model->step(100);
$firstPage = $public->handleHttp($publicContext, new PluginHttpRequest('GET', '/' . $rotatedShare['id'], [], [], '', false));
if (substr_count($firstPage->body, '<article>') !== 40 || !preg_match('/href="\?before=([^"]+)"/', $firstPage->body, $match)
    || !str_contains($firstPage->body, 'リリース &lt;確認&gt;') || str_contains($firstPage->body, 'PAGE-02')) {
    throw new RuntimeException('Public Embed pagination first page failed');
}
$nextPage = $public->handleHttp($publicContext, new PluginHttpRequest('GET', '/' . $rotatedShare['id'],
    ['before' => rawurldecode($match[1])], [], '', false));
if (!str_contains($nextPage->body, 'PAGE-02') || !str_contains($nextPage->body, 'PAGE-01') || str_contains($nextPage->body, 'PAGE-03')) {
    throw new RuntimeException('Public Embed pagination duplicated or skipped messages');
}
