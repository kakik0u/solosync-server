<?php
declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=solosync;charset=utf8mb4',
        'user' => 'solosync',
        'password' => 'CHANGE_ME',
    ],
    // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'server_identity' => 'CHANGE_TO_64_HEX_CHARACTERS',
    // Generate with: php -r "echo password_hash('your password', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => 'CHANGE_TO_PASSWORD_HASH',
    'require_https' => true,
    'trust_forwarded_proto' => false,
    'allowed_origins' => [
        'https://sc.kakikou.app',
        'http://localhost:8081',
    ],
    // Normal packs stay around 768 KiB client-side. The larger server ceiling
    // exists for a single attachment operation that cannot be split across packs.
    'max_pack_bytes' => 16 * 1024 * 1024,
    'max_page_size' => 200,
    'minimum_poll_ms' => 5000,
    'quota_bytes' => 1024 * 1024 * 1024,
    'plugins_path' => dirname(__DIR__) . '/plugins',
];
