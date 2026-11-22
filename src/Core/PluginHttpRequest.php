<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class PluginHttpRequest
{
    /** @param array<string,mixed> $query @param array<string,string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body,
        public readonly bool $admin,
    ) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $this->jsonObject();
        return json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
    }

    /** Preserve object/array distinctions for structured webhook validation. */
    public function jsonObject(): \stdClass
    {
        try { $object = json_decode($this->body, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException(400, 'INVALID_JSON', 'Invalid JSON body'); }
        if (!$object instanceof \stdClass) throw new ApiException(400, 'INVALID_JSON', 'Expected a JSON object');
        return $object;
    }
}
