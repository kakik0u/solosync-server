<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\RichEmbed;
use Solosync\SyncServer\Plugins\Webhook\Payload;

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/plugins/webhook/Payload.php';

$result = [];
foreach (json_decode((string)file_get_contents(__DIR__ . '/fixtures/rich-embeds.json'), false, 32, JSON_THROW_ON_ERROR) as $fixture) {
    $message = Payload::parse($fixture->request);
    $expected = json_decode(json_encode($fixture->payload, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
    if ($message['embeds'][0] !== $expected) throw new RuntimeException('Fixture mismatch: ' . $fixture->name);
    $result[] = ['name' => $fixture->name, 'blocksJson' => RichEmbed::encodeBlocks($message['embeds'],
        static fn(): string => '00000000-0000-4000-8000-000000000001')];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
