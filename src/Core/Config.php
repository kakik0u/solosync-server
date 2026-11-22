<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class Config
{
    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values) {}

    public static function load(string $path): self
    {
        if (!is_file($path)) throw new \RuntimeException('Server configuration is missing');
        $values = require $path;
        if (!is_array($values)) throw new \RuntimeException('Server configuration is invalid');
        return new self($values);
    }

    /** @return array{dsn:string,user:string,password:string} */
    public function database(): array
    {
        $value = $this->values['database'] ?? null;
        if (!is_array($value) || !is_string($value['dsn'] ?? null)
            || !is_string($value['user'] ?? null) || !is_string($value['password'] ?? null)) {
            throw new \RuntimeException('Database configuration is invalid');
        }
        return ['dsn' => $value['dsn'], 'user' => $value['user'], 'password' => $value['password']];
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value) || $value === '') throw new \RuntimeException("Missing configuration: {$key}");
        return $value;
    }

    public function int(string $key, int $minimum, int $maximum): int
    {
        $value = $this->values[$key] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \RuntimeException("Invalid configuration: {$key}");
        }
        return $value;
    }

    public function bool(string $key): bool
    {
        return (bool)($this->values[$key] ?? false);
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $value = $this->values['allowed_origins'] ?? [];
        if (!is_array($value)) throw new \RuntimeException('allowed_origins is invalid');
        foreach ($value as $origin) {
            if (!is_string($origin) || $origin === '') throw new \RuntimeException('allowed_origins is invalid');
        }
        return array_values($value);
    }

    public function pluginsPath(): string
    {
        $value = $this->values['plugins_path'] ?? dirname(__DIR__, 2) . '/plugins';
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new \RuntimeException('plugins_path is invalid');
        }
        return rtrim($value, DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed>|null $server */
    public function requestIsHttps(?array $server = null): bool
    {
        $server ??= $_SERVER;
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') return true;
        if (!$this->bool('trust_forwarded_proto')) return false;
        $forwarded = (string)($server['HTTP_X_FORWARDED_PROTO'] ?? '');
        return strtolower(trim(explode(',', $forwarded, 2)[0] ?? '')) === 'https';
    }
}
