<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class PluginHttpResponse
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType = 'application/json; charset=utf-8',
        public readonly array $headers = [],
    ) {}

    /** @param array<string,mixed> $body */
    public static function json(int $status, array $body): self
    {
        return new self($status, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
