<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\PluginHttpRequest;
use Solosync\SyncServer\Core\RichEmbed;
use Solosync\SyncServer\Plugins\PublicChannel\BlocksRenderer;
use Solosync\SyncServer\Plugins\Webhook\Payload;

require_once dirname(__DIR__) . '/plugins/webhook/Payload.php';
require_once dirname(__DIR__) . '/plugins/public_channel/BlocksRenderer.php';

$fixtureMessages = [];
foreach (json_decode((string)file_get_contents(__DIR__ . '/fixtures/rich-embeds.json'), false, 32, JSON_THROW_ON_ERROR) as $fixture) {
    $message = Payload::parse($fixture->request);
    $expected = json_decode(json_encode($fixture->payload, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    check($message['embeds'][0] === $expected, 'Rich Embed fixture mismatch: ' . $fixture->name);
    check(Payload::parse(['content' => $message['content'], 'embeds' => array_map(Payload::toDiscord(...), $message['embeds'])]) === $message,
        'Discord wait response cannot round-trip');
    $fixtureMessages[] = $message;
}
$normalized = Payload::parse(['embeds' => [['title' => 'same', 'fields' => [['value' => 'value', 'name' => 'field']]]]]);
$reordered = Payload::parse(['embeds' => [['fields' => [['name' => 'field', 'inline' => false, 'value' => 'value']], 'type' => 'rich', 'title' => 'same']]]);
check(Payload::fingerprint($normalized) === Payload::fingerprint($reordered), 'Embed fingerprint depends on key order/defaults');
check(Payload::fingerprint($normalized) !== Payload::fingerprint(Payload::parse(['embeds' => [['title' => 'different']]])), 'Embed changes do not affect fingerprint');
check(Payload::fingerprint(Payload::parse(['content' => 'old'])) === hash('sha256', 'old', true), 'Legacy text fingerprint changed');
check(strlen(Payload::content(['content' => str_repeat('a', 32768)])) === 32768, '32 KiB body compatibility lost');
check(count(Payload::parse(['embeds' => array_fill(0, 10, ['title' => 'ok'])])['embeds']) === 10, '10 embeds rejected');
$exact = ['description' => str_repeat('😀', 4096), 'footer' => ['text' => str_repeat('a', 1904)]];
check(count(Payload::parse(['embeds' => [$exact]])['embeds']) === 1, '6000 Unicode characters rejected');
foreach ([
    [['embeds' => array_fill(0, 11, ['title' => 'ok'])], 'embeds'],
    [['embeds' => [$exact, ['title' => 'x']]], 'embeds'],
    [['embeds' => [['title' => str_repeat('x', 257)]]], 'embeds[0].title'],
    [['embeds' => [['description' => str_repeat('x', 4097)]]], 'embeds[0].description'],
    [['embeds' => [['title' => 'test', 'color' => -1]]], 'embeds[0].color'],
    [['embeds' => [['title' => 'test', 'color' => 16777216]]], 'embeds[0].color'],
    [['embeds' => [['title' => 'test', 'color' => '#ffffff']]], 'embeds[0].color'],
    [['embeds' => [['type' => 'video', 'title' => 'test']]], 'embeds[0].type'],
    [['embeds' => [['video' => ['url' => 'https://example.invalid']]]], 'embeds[0].video'],
    [['embeds' => [['image' => ['url' => 'attachment://image.png']]]], 'embeds[0].image.url'],
    [['embeds' => [['image' => ['url' => 'https://example.invalid', 'width' => 100]]]], 'embeds[0].image.width'],
    [['embeds' => [['url' => 'javascript:alert(1)']]], 'embeds[0].url'],
    [['embeds' => [['url' => 'https://user:pass@example.invalid']]], 'embeds[0].url'],
    [['embeds' => [['url' => "https://example.invalid\\@evil.invalid"]]], 'embeds[0].url'],
    [['embeds' => [['title' => "a\nb"]]], 'embeds[0].title'],
    [['embeds' => [['description' => "a\tb"]]], 'embeds[0].description'],
    [['embeds' => [['author' => ['name' => '   ']]]], 'embeds[0].author.name'],
    [['embeds' => [['footer' => ['text' => 'test', 'icon_url' => 'file:///etc/passwd']]]], 'embeds[0].footer.icon_url'],
    [['embeds' => [['fields' => array_fill(0, 26, ['name' => 'n', 'value' => 'v'])]]], 'embeds[0].fields'],
    [['embeds' => [['fields' => [['name' => 'n', 'value' => 'v', 'inline' => 1]]]]], 'embeds[0].fields[0].inline'],
    [['embeds' => [['title' => 'test', 'timestamp' => '2026-02-30T00:00:00Z']]], 'embeds[0].timestamp'],
    [['embeds' => [['title' => 'test', 'timestamp' => '2100-01-01T00:00:00Z']]], 'embeds[0].timestamp'],
    [['embeds' => [['title' => 'test', 'timestamp' => '2026-01-01T00:00:00+24:00']]], 'embeds[0].timestamp'],
    [['embeds' => [[]]], 'embeds[0]'],
    [['content' => 'test', 'blocks_json' => RichEmbed::EMPTY_BLOCKS], 'blocks_json'],
    [['content' => str_repeat('x', 32769)], 'content'],
] as [$body, $path]) {
    $rejected = false;
    try { Payload::parse($body); }
    catch (ApiException $error) { $rejected = $error->status === 400 && str_contains($error->getMessage(), $path); }
    check($rejected, 'Invalid webhook was not rejected with its field path: ' . $path);
}
foreach (['{"embeds":{}}', '{"embeds":[{"title":"ok","fields":{}}]}', '{"embeds":[[]]}', '{"embeds":[{"image":[]}]}', '{"embeds":null}'] as $json) {
    $rejected = false;
    try { Payload::parse((new PluginHttpRequest('POST', '/', [], [], $json, false))->jsonObject()); }
    catch (ApiException $error) { $rejected = $error->status === 400; }
    check($rejected, 'Invalid nested JSON shape accepted: ' . $json);
}
$fixedId = static fn(): string => '00000000-0000-4000-8000-000000000001';
$blocksJson = RichEmbed::encodeBlocks($fixtureMessages[0]['embeds'], $fixedId);
$html = BlocksRenderer::render($blocksJson);
check(str_contains($html, 'class="embed"') && str_contains($html, '#5865f2') && str_contains($html, 'リリース &lt;確認&gt;')
    && str_contains($html, 'class="embed-field inline"') && str_contains($html, 'referrerpolicy="no-referrer"')
    && str_contains($html, 'loading="lazy"') && str_contains($html, '2026-10-13T00:00:00.000Z'), 'Full public Embed card missing fields');
$unsafe = RichEmbed::normalize(['title' => '<script>alert(1)</script>', 'description' => '" onclick="bad',
    'image' => ['url' => 'https://example.invalid/a?x="&y=<test>'], 'author' => ['name' => '<b>author</b>'], 'footer' => ['text' => '<img onerror=bad>']]);
$html = BlocksRenderer::render(RichEmbed::encodeBlocks([$unsafe], $fixedId));
check(!str_contains($html, '<script>') && !str_contains($html, '<img onerror') && str_contains($html, '&lt;script&gt;')
    && str_contains($html, '&amp;y='), 'Public Embed did not escape user values');
$attachment = RichEmbed::normalize(['image' => ['attachmentId' => 'private-attachment']]);
$html = BlocksRenderer::render(RichEmbed::encodeBlocks([$attachment], $fixedId));
check(str_contains($html, '添付画像') && !str_contains($html, 'private-attachment') && !str_contains($html, '<img '), 'Public page exposes managed attachment identifiers');
$summary = '<table>表・グラフの要約</table>';
foreach (['table', 'chart', 'future-block'] as $type) {
    $json = json_encode(['version' => 1, 'blocks' => [['type' => $type, 'schemaVersion' => 1, 'fallbackText' => $summary, 'payload' => ['secret' => 'must-not-leak']]]], JSON_THROW_ON_ERROR);
    $html = BlocksRenderer::render($json);
    check(str_contains($html, '&lt;table&gt;') && !str_contains($html, 'must-not-leak'), 'Unsupported block leaked payload or lost its summary');
}
check(str_contains(BlocksRenderer::render(str_replace('"version":1', '"version":2', $blocksJson)), 'block-summary'), 'Future envelope was rendered as v1');
check(str_contains(BlocksRenderer::render(str_replace('"schemaVersion":1', '"schemaVersion":2', $blocksJson)), 'block-summary'), 'Future Embed schema was rendered as v1');
foreach (['{broken', '{"version":1,"blocks":{}}', '{"version":0,"blocks":[]}', str_repeat('x', 1048577)] as $json) {
    check(str_contains(BlocksRenderer::render($json), '表示できません'), 'Invalid/oversized JSON missing placeholder');
}
check(str_contains(BlocksRenderer::render(null, true), '表示できません'), 'SQL-size-limited blocks silently disappear');
check(BlocksRenderer::render(null) === '' && BlocksRenderer::render(RichEmbed::EMPTY_BLOCKS) === '', 'Legacy text-only message gained placeholder');
$fallback = RichEmbed::fallback(RichEmbed::normalize(['description' => str_repeat('😀', 2000)]));
check(strlen($fallback) === 4096 && preg_match('//u', $fallback) === 1, 'Fallback limit splits UTF-8');
