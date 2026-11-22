<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Plugins\Webhook;

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\RichEmbed;

/** The supported text/Rich Embed subset of Discord Execute Webhook JSON. */
final class Payload
{
    public static function content(array $body): string
    {
        return self::parse($body)['content'];
    }

    /** @return array{content:string,embeds:list<array<string,mixed>>} */
    public static function parse(array|\stdClass $body): array
    {
        if ($body instanceof \stdClass) $body = get_object_vars($body);
        $content = array_key_exists('content', $body) ? $body['content'] : '';
        if (!is_string($content) || strlen($content) > 32768) {
            RichEmbed::invalid('content', 'Expected a string, at most 32 KiB');
        }
        foreach (['attachments', 'components'] as $field) {
            if (array_key_exists($field, $body) && $body[$field] !== []) {
                throw new ApiException(400, 'UNSUPPORTED_WEBHOOK_FIELD', $field . ' must be an empty array');
            }
        }
        foreach (['poll', 'thread_name', 'blocks', 'blocks_json'] as $field) {
            if (array_key_exists($field, $body)) {
                throw new ApiException(400, 'UNSUPPORTED_WEBHOOK_FIELD', $field . ' is not supported');
            }
        }
        // Discord sender metadata is accepted, but does not change the Solocord
        // author or trigger mentions/TTS. In particular, avatar URLs are never fetched.
        foreach (['username', 'avatar_url'] as $field) {
            if (array_key_exists($field, $body) && !is_string($body[$field])) {
                throw new ApiException(400, 'INVALID_MESSAGE', $field . ' must be a string');
            }
        }
        if (array_key_exists('tts', $body) && !is_bool($body['tts'])) {
            throw new ApiException(400, 'INVALID_MESSAGE', 'tts must be a boolean');
        }
        if (array_key_exists('allowed_mentions', $body) && !is_array($body['allowed_mentions']) && !$body['allowed_mentions'] instanceof \stdClass) {
            throw new ApiException(400, 'INVALID_MESSAGE', 'allowed_mentions must be an object');
        }
        if (array_key_exists('flags', $body) && (!is_int($body['flags']) || $body['flags'] < 0
            || ($body['flags'] & ~(4 | 4096)) !== 0)) {
            throw new ApiException(400, 'UNSUPPORTED_WEBHOOK_FIELD', 'Only text-message flags are accepted');
        }
        $values = array_key_exists('embeds', $body) ? $body['embeds'] : [];
        if (!is_array($values) || !array_is_list($values) || count($values) > 10) RichEmbed::invalid('embeds', 'Expected at most 10 embeds');
        $embeds = [];
        foreach ($values as $i => $value) $embeds[] = self::fromDiscord($value, 'embeds[' . $i . ']');
        if (array_sum(array_map(RichEmbed::textLength(...), $embeds)) > 6000) RichEmbed::invalid('embeds', 'Combined text exceeds 6000 characters');
        if (trim($content) === '' && $embeds === []) RichEmbed::invalid('content', 'Expected text or at least one Embed');
        return ['content' => $content, 'embeds' => $embeds];
    }

    private static function fromDiscord(mixed $value, string $path): array
    {
        $embed = RichEmbed::object($value, $path, ['type', 'title', 'description', 'url', 'timestamp', 'color', 'author', 'footer', 'image', 'thumbnail', 'fields']);
        if (array_key_exists('type', $embed) && $embed['type'] !== 'rich') RichEmbed::invalid($path . '.type', 'Only rich embeds are supported');
        unset($embed['type']);
        if (array_key_exists('color', $embed)) {
            if (!is_int($embed['color']) || $embed['color'] < 0 || $embed['color'] > 0xffffff) RichEmbed::invalid($path . '.color', 'Expected an integer from 0 to 16777215');
            $embed['color'] = sprintf('#%06x', $embed['color']);
        }
        foreach (['image', 'thumbnail'] as $key) {
            if (array_key_exists($key, $embed)) {
                $image = RichEmbed::object($embed[$key], $path . '.' . $key, ['url']);
                $embed[$key] = ['url' => RichEmbed::url($image['url'] ?? null, $path . '.' . $key . '.url')];
            }
        }
        foreach (['author', 'footer'] as $key) {
            if (!array_key_exists($key, $embed)) continue;
            $part = RichEmbed::object($embed[$key], $path . '.' . $key, $key === 'author' ? ['name', 'url', 'icon_url'] : ['text', 'icon_url']);
            if (array_key_exists('icon_url', $part)) {
                $part['icon'] = ['url' => RichEmbed::url($part['icon_url'], $path . '.' . $key . '.icon_url')];
                unset($part['icon_url']);
            }
            $embed[$key] = $part;
        }
        return RichEmbed::normalize($embed, $path);
    }

    public static function toDiscord(array $embed): array
    {
        $result = ['type' => 'rich', ...$embed];
        if (isset($result['color'])) $result['color'] = hexdec(substr($result['color'], 1));
        foreach (['author', 'footer'] as $key) {
            if (isset($result[$key]['icon'])) {
                $result[$key]['icon_url'] = $result[$key]['icon']['url'];
                unset($result[$key]['icon']);
            }
        }
        return $result;
    }

    /** Random block IDs never participate in a request fingerprint. */
    public static function fingerprint(array $message): string
    {
        // Keep existing text-only idempotency records valid across the update.
        return hash('sha256', $message['embeds'] === [] ? $message['content']
            : "rich-embed-v1\0" . json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
    }
}
