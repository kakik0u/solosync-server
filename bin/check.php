<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = ['pdo_mysql', 'json', 'hash', 'openssl'];
$missing = array_values(array_filter($required, static fn(string $name): bool => !extension_loaded($name)));
$issues = [];
if ($missing) $issues[] = 'Missing PHP extensions: ' . implode(', ', $missing);
if (!is_file($root . '/private/config.php')) $issues[] = 'private/config.php is missing';
if (!is_writable($root . '/private')) $issues[] = 'private/ is not writable (only required while creating config)';
if ($issues) { fwrite(STDERR, implode(PHP_EOL, $issues) . PHP_EOL); exit(1); }
echo "Solosync server prerequisites look OK.\n";
