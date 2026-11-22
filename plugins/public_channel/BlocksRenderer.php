<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Plugins\PublicChannel;

use Solosync\SyncServer\Core\ApiException;
use Solosync\SyncServer\Core\RichEmbed;

/** Static, escaped cards; tables/charts/future blocks use their stored summary. */
final class BlocksRenderer
{
    public static function styles(): string
    {
        return <<<'CSS'
:root{color-scheme:light dark;font-family:system-ui,sans-serif;line-height:1.55}
body{margin:0;background:Canvas;color:CanvasText}main{max-width:800px;margin:auto;padding:24px}
h1{font-size:1.5rem}h2{font-size:1rem;margin:0}article{padding:16px 0;overflow-wrap:anywhere}
article>time,.embed-footer{font-size:.85rem;opacity:.75}article>p{margin:.6rem 0}
a{color:LinkText}.embed{border:1px solid GrayText;border-left:4px solid GrayText;border-radius:6px;padding:14px;margin:12px 0;display:flow-root}
.embed h3{font-size:1rem;margin:.4rem 0}.embed p{margin:.5rem 0}.embed-author,.embed-footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.embed-icon{width:24px;height:24px;object-fit:contain;border-radius:50%}.embed-thumbnail{float:right;width:80px;height:80px;object-fit:contain;margin:0 0 8px 12px}
.embed-image{display:block;max-width:100%;max-height:480px;object-fit:contain;margin:12px 0}.embed-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0;clear:both}
.embed-field{grid-column:1/-1;min-width:0}.embed-field.inline{grid-column:auto}.embed-field dt{font-weight:600}.embed-field dd{margin:0;white-space:pre-wrap}
.block-summary{white-space:pre-wrap;border-left:3px solid GrayText;padding:8px 12px;margin:12px 0}.unsupported{font-size:.85rem;opacity:.75}
@media(max-width:520px){main{padding:12px}.embed{padding:10px}.embed-fields{grid-template-columns:1fr}.embed-thumbnail{width:64px;height:64px}}
CSS;
    }

    public static function render(?string $json, bool $truncated = false): string
    {
        if ($truncated) return self::unsupported();
        if ($json === null || $json === '') return '';
        if (strlen($json) > RichEmbed::MAX_BLOCK_BYTES) return self::unsupported();
        try { $envelope = json_decode($json, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return self::unsupported(); }
        if (!$envelope instanceof \stdClass || !is_int($envelope->version ?? null) || $envelope->version < 1
            || !is_array($envelope->blocks ?? null) || count($envelope->blocks) > 16) return self::unsupported();
        $html = '';
        foreach ($envelope->blocks as $block) {
            if (!$block instanceof \stdClass) { $html .= self::unsupported(); continue; }
            if ($envelope->version === 1 && ($block->type ?? null) === 'rich-embed' && ($block->schemaVersion ?? null) === 1
                && is_string($block->id ?? null) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $block->id)) {
                try { $html .= self::embed(RichEmbed::normalize($block->payload ?? null)); continue; }
                catch (ApiException) { /* Fall back to the escaped summary, not raw JSON. */ }
            }
            $summary = $block->fallbackText ?? '';
            $html .= is_string($summary) && trim($summary) !== ''
                ? '<div class="block-summary">' . self::escape(RichEmbed::boundedText($summary)) . '</div>' : self::unsupported();
        }
        return $html;
    }

    private static function embed(array $embed): string
    {
        $html = '<section class="embed"' . (isset($embed['color']) ? ' style="border-left-color:' . self::escape($embed['color']) . '"' : '') . '>';
        if (isset($embed['thumbnail'])) $html .= self::image($embed['thumbnail'], 'embed-thumbnail', 'サムネイル');
        if (isset($embed['author'])) {
            $author = $embed['author'];
            $html .= '<div class="embed-author">' . (isset($author['icon']) ? self::image($author['icon'], 'embed-icon', '作者アイコン') : '')
                . self::link($author['name'], $author['url'] ?? null) . '</div>';
        }
        if (isset($embed['title'])) $html .= '<h3>' . self::link($embed['title'], $embed['url'] ?? null) . '</h3>';
        elseif (isset($embed['url'])) $html .= '<p>' . self::link($embed['url'], $embed['url']) . '</p>';
        if (isset($embed['description'])) $html .= '<p>' . nl2br(self::escape($embed['description']), false) . '</p>';
        if (!empty($embed['fields'])) {
            $html .= '<dl class="embed-fields">';
            foreach ($embed['fields'] as $field) {
                $html .= '<div class="embed-field' . ($field['inline'] ? ' inline' : '') . '"><dt>' . self::escape($field['name'])
                    . '</dt><dd>' . self::escape($field['value']) . '</dd></div>';
            }
            $html .= '</dl>';
        }
        if (isset($embed['image'])) $html .= self::image($embed['image'], 'embed-image', $embed['title'] ?? 'Embed画像');
        if (isset($embed['footer']) || isset($embed['timestamp'])) {
            $footer = $embed['footer'] ?? [];
            $html .= '<div class="embed-footer">' . (isset($footer['icon']) ? self::image($footer['icon'], 'embed-icon', 'フッターアイコン') : '')
                . (isset($footer['text']) ? '<span>' . self::escape($footer['text']) . '</span>' : '')
                . (isset($embed['timestamp']) ? '<time datetime="' . self::escape($embed['timestamp']) . '">' . self::escape($embed['timestamp']) . '</time>' : '') . '</div>';
        }
        return $html . '</section>';
    }

    private static function image(array $image, string $class, string $alt): string
    {
        if (!isset($image['url'])) return '<span class="unsupported">（添付画像は公開ページでは表示できません）</span>';
        $url = self::escape($image['url']);
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer"><img class="' . $class . '" src="' . $url
            . '" alt="' . self::escape($alt) . '" loading="lazy" referrerpolicy="no-referrer"></a>';
    }

    private static function link(string $text, ?string $url): string
    {
        return $url === null ? self::escape($text) : '<a href="' . self::escape($url)
            . '" target="_blank" rel="noopener noreferrer">' . self::escape($text) . '</a>';
    }

    private static function unsupported(): string { return '<p class="unsupported">（この内容は公開ページでは表示できません）</p>'; }
    private static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
