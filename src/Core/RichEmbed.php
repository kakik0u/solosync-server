<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** The RichEmbed v1 subset shared with Solocord; no generic block registry. */
final class RichEmbed
{
    public const EMPTY_BLOCKS = '{"version":1,"blocks":[]}';
    public const MAX_BLOCK_BYTES = 1048576;

    public static function invalid(string $path, string $reason): never
    {
        throw new ApiException(400, 'INVALID_MESSAGE', $path . ': ' . $reason);
    }

    public static function object(mixed $value, string $path, array $keys): array
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (!is_array($value) || ($value !== [] && array_is_list($value))) self::invalid($path, 'Expected an object');
        foreach ($value as $key => $_) if (!in_array($key, $keys, true)) self::invalid($path . '.' . $key, 'Unsupported field');
        return $value;
    }

    public static function text(mixed $value, string $path, int $limit, bool $required = true, bool $multiline = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || ($required && preg_match('/^[\s\x{FEFF}]*$/uD', $value))) self::invalid($path, 'Expected text');
        if (self::length($value) > $limit) self::invalid($path, 'Text exceeds ' . $limit . ' characters');
        if (preg_match($multiline ? '/[\x00-\x09\x0b-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $value)) {
            self::invalid($path, 'Control characters are not allowed');
        }
        return $value;
    }

    public static function length(string $value): int
    {
        return preg_match_all('/./us', $value);
    }

    public static function url(mixed $value, string $path): string
    {
        $raw = self::text($value, $path, 2048);
        // Do not let a browser interpret backslashes as authority separators.
        if (str_contains($raw, '\\')) self::invalid($path, 'Invalid URL');
        // Encode UTF-8 before parse_url: some host locales classify UTF-8
        // continuation bytes as controls and would replace them with underscores.
        $raw = preg_replace_callback('/[\x20\x22\x3c\x3e\x60\x7b\x7d\x80-\xff]/',
            static fn(array $m): string => rawurlencode($m[0]), $raw);
        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) self::invalid($path, 'Expected an HTTP(S) URL without credentials');
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $result = $scheme . '://' . $host
            . ($port !== null && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '')
            . ($parts['path'] ?? '/');
        if (isset($parts['query'])) $result .= '?' . $parts['query'];
        if (isset($parts['fragment'])) $result .= '#' . $parts['fragment'];
        if (!is_string($result) || strlen($result) > 2048 || filter_var($result, FILTER_VALIDATE_URL) === false) {
            self::invalid($path, 'Invalid or oversized URL');
        }
        return $result;
    }

    private static function timestamp(mixed $value, string $path): string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?(Z|[+-]\d{2}:\d{2})$/D', $value, $m)
            || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59
            || ($m[8] !== 'Z' && ((int)substr($m[8], 1, 2) > 23 || (int)substr($m[8], 4) > 59))) {
            self::invalid($path, 'Expected a valid RFC 3339 timestamp with seconds and offset');
        }
        $date = new \DateTimeImmutable($value);
        $date = $date->setTimezone(new \DateTimeZone('UTC'));
        if ($date->format('Y') < '1970' || $date->format('Y') >= '2100') self::invalid($path, 'Time outside 1970–2099');
        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function image(mixed $value, string $path): array
    {
        $image = self::object($value, $path, ['url', 'attachmentId']);
        if (array_key_exists('url', $image) === array_key_exists('attachmentId', $image)) self::invalid($path, 'Choose a URL or an attachment');
        return array_key_exists('url', $image) ? ['url' => self::url($image['url'], $path . '.url')]
            : ['attachmentId' => self::text($image['attachmentId'] ?? null, $path . '.attachmentId', 200)];
    }

    public static function normalize(mixed $value, string $path = 'embed'): array
    {
        $input = self::object($value, $path, ['title', 'description', 'url', 'timestamp', 'color', 'author', 'footer', 'image', 'thumbnail', 'fields']);
        $embed = [];
        foreach (['title' => 256, 'description' => 4096] as $key => $limit) {
            if (array_key_exists($key, $input)) $embed[$key] = self::text($input[$key], $path . '.' . $key, $limit, false, $key === 'description');
        }
        if (array_key_exists('url', $input)) $embed['url'] = self::url($input['url'], $path . '.url');
        if (array_key_exists('timestamp', $input)) $embed['timestamp'] = self::timestamp($input['timestamp'], $path . '.timestamp');
        if (array_key_exists('color', $input)) {
            if (!is_string($input['color']) || !preg_match('/^#[a-f0-9]{6}$/iD', $input['color'])) self::invalid($path . '.color', 'Use #RRGGBB');
            $embed['color'] = strtolower($input['color']);
        }
        foreach (['author' => ['name', 256], 'footer' => ['text', 2048]] as $key => [$textKey, $limit]) {
            if (!array_key_exists($key, $input)) continue;
            $part = self::object($input[$key], $path . '.' . $key, $key === 'author' ? ['name', 'url', 'icon'] : ['text', 'icon']);
            $embed[$key] = [$textKey => self::text($part[$textKey] ?? null, $path . '.' . $key . '.' . $textKey, $limit)];
            if (array_key_exists('url', $part)) $embed[$key]['url'] = self::url($part['url'], $path . '.' . $key . '.url');
            if (array_key_exists('icon', $part)) $embed[$key]['icon'] = self::image($part['icon'], $path . '.' . $key . '.icon');
        }
        foreach (['image', 'thumbnail'] as $key) if (array_key_exists($key, $input)) $embed[$key] = self::image($input[$key], $path . '.' . $key);
        if (array_key_exists('fields', $input)) {
            if (!is_array($input['fields']) || !array_is_list($input['fields']) || count($input['fields']) > 25) self::invalid($path . '.fields', 'Expected at most 25 fields');
            $embed['fields'] = [];
            foreach ($input['fields'] as $i => $value) {
                $fieldPath = $path . '.fields[' . $i . ']';
                $field = self::object($value, $fieldPath, ['name', 'value', 'inline']);
                if (array_key_exists('inline', $field) && !is_bool($field['inline'])) self::invalid($fieldPath . '.inline', 'Expected a boolean');
                $embed['fields'][] = ['name' => self::text($field['name'] ?? null, $fieldPath . '.name', 256),
                    'value' => self::text($field['value'] ?? null, $fieldPath . '.value', 1024, true, true), 'inline' => $field['inline'] ?? false];
            }
        }
        if (self::textLength($embed) > 6000) self::invalid($path, 'Embed text exceeds 6000 characters');
        if (self::strings($embed) === [] && !isset($embed['image']) && !isset($embed['thumbnail']) && !isset($embed['url'])) self::invalid($path, 'The Embed is empty');
        return $embed;
    }

    private static function strings(array $embed): array
    {
        return array_values(array_filter([$embed['title'] ?? '', $embed['description'] ?? '', $embed['author']['name'] ?? '',
            $embed['footer']['text'] ?? '', ...array_merge(...array_map(static fn(array $f): array => [$f['name'], $f['value']], $embed['fields'] ?? []))],
            static fn(string $s): bool => $s !== ''));
    }

    public static function textLength(array $embed): int
    {
        return array_sum(array_map(self::length(...), self::strings($embed)));
    }

    public static function fallback(array $embed): string
    {
        $text = implode("\n", self::strings($embed));
        if (preg_match('/^[\s\x{FEFF}]*$/uD', $text)) $text = $embed['url'] ?? 'Embed image';
        return self::boundedText($text);
    }

    /** @param list<array<string,mixed>> $embeds Already-normalized payloads */
    public static function encodeBlocks(array $embeds, callable $newId): string
    {
        $blocks = array_map(static fn(array $embed): array => ['id' => $newId(), 'type' => 'rich-embed',
            'schemaVersion' => 1, 'payload' => $embed, 'fallbackText' => self::fallback($embed)], $embeds);
        $json = json_encode(['version' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BLOCK_BYTES) self::invalid('embeds', 'Blocks exceed 1 MiB');
        return $json;
    }

    public static function boundedText(string $value): string
    {
        $value = substr($value, 0, 4096);
        while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
        return $value;
    }
}
