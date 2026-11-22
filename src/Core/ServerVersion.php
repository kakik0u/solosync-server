<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

/** The fallback supports upgrades where an operator copied files but missed VERSION. */
final class ServerVersion
{
    public const FALLBACK = '1.0.0';

    public static function installed(?string $root = null): string
    {
        $path = ($root ?? dirname(__DIR__, 2)) . '/VERSION';
        $value = is_file($path) ? trim((string)file_get_contents($path)) : '';
        return preg_match('/^\d+\.\d+\.\d+$/D', $value) ? $value : self::FALLBACK;
    }

    public static function versionFileValid(?string $root = null): bool
    {
        $path = ($root ?? dirname(__DIR__, 2)) . '/VERSION';
        return is_file($path) && preg_match('/^\d+\.\d+\.\d+$/D', trim((string)file_get_contents($path))) === 1;
    }
}
