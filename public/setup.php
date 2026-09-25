<?php
declare(strict_types=1);

use Solosync\SyncServer\Core\AdminService;
use Solosync\SyncServer\Core\Config;
use Solosync\SyncServer\Core\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

final class SetupValidationException extends RuntimeException {}

function setupLanguage(): string
{
    $explicit = $_GET['lang'] ?? $_POST['lang'] ?? null;
    if (is_string($explicit) && in_array($explicit, ['ja', 'en'], true)) return $explicit;
    $acceptLanguage = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $first = trim(explode(',', $acceptLanguage, 2)[0] ?? '');
    return str_starts_with($first, 'en') ? 'en' : 'ja';
}

function setupText(string $key): string
{
    global $lang;
    static $messages = [
        'ja' => [
            'title' => 'Solosync セットアップ',
            'heading' => 'Solosync セルフホスト',
            'intro' => 'この画面は初回セットアップ専用です。サーバーIDは自動的に安全な乱数で生成され、セットアップ完了後は再実行できません。',
            'fatal_https' => '安全のため、初回セットアップはHTTPSからのみ実行できます。',
            'fatal_private' => 'private ディレクトリに書き込めません。ホスティング側の権限を確認してください。',
            'lock_failed' => 'セットアップロックを取得できませんでした。',
            'already_installed' => 'このサーバーは既にセットアップ済みです。',
            'setup_key_invalid' => 'セットアップキーが正しくありません。',
            'cross_site' => '別サイトからのセットアップ要求は受け付けられません。',
            'db_host_invalid' => 'DBホスト名が不正です。',
            'db_port_invalid' => 'DBポートが不正です。',
            'db_name_invalid' => 'DB名が不正です。',
            'db_user_invalid' => 'DBユーザー名が不正です。',
            'db_password_long' => 'DBパスワードが長すぎます。',
            'admin_password_short' => '管理者パスワードは12文字以上にしてください。',
            'admin_password_mismatch' => '管理者パスワードが一致しません。',
            'origin_long' => '許可Originが長すぎます。',
            'origin_format' => '許可Originは https://example.com の形式で入力してください。',
            'origin_https' => '許可OriginはHTTPSにしてください。HTTPはlocalhostのみ使用できます。',
            'origin_path' => '許可Originにパスは指定できません。',
            'origin_count' => '許可Originは20件以内にしてください。',
            'password_hash_failed' => '管理者パスワードをハッシュ化できませんでした。',
            'temp_config_failed' => '一時設定ファイルを書き込めませんでした。',
            'config_commit_failed' => '設定ファイルを確定できませんでした。',
            'setup_failed' => 'セットアップに失敗しました。DB接続情報、PHP拡張、ファイル権限を確認してください。',
            'setup_complete' => 'セットアップが完了しました。接続キーは管理画面から発行してください。',
            'server_url' => 'Solocordに入力するサーバーURL',
            'open_admin' => '管理画面を開く',
            'installed_notice' => 'このSolosyncサーバーは既にセットアップ済みです。このセットアップ画面は無効化されています。',
            'first' => '最初に：',
            'ownership_notice' => "本人確認のため、 private/setup.key.php にセットアップキーを自動生成しました。ファイルマネージャーでこのファイルを開き、return '...'; の引用符内にあるキーだけを下へ貼り付けてください。",
            'ownership' => '所有確認',
            'setup_key' => 'セットアップキー',
            'database' => 'MySQL / MariaDB',
            'db_host' => 'DBホスト',
            'port' => 'ポート',
            'db_name' => 'DB名',
            'db_user' => 'DBユーザー名',
            'db_password' => 'DBパスワード',
            'administrator' => '管理者',
            'admin_password' => '管理者パスワード（12文字以上）',
            'admin_password_confirm' => '管理者パスワード（確認）',
            'web_access' => 'Web版からの接続',
            'allowed_origins' => '許可Origin（1行1件）',
            'origins_help' => 'ネイティブ版/Tauri版だけを使う場合は空でも構いません。ワイルドカードは使用できません。',
            'proxy_title' => '信頼できるリバースプロキシの X-Forwarded-Proto を使用する',
            'proxy_help' => 'Cloudflareなどの信頼できるリバースプロキシが利用者とのHTTPS通信を終端し、PHPサーバー側にはHTTPで転送する構成のときだけ有効にしてください。Solosyncは X-Forwarded-Proto: https を見てHTTPS接続として扱います。PHPサーバーまで直接HTTPSで接続される場合はオフのままで構いません。プロキシを迂回してPHPへ直接アクセスでき、任意の転送ヘッダーを送れる構成では有効にしないでください。',
            'submit' => 'セットアップ',
            'language' => '言語',
        ],
        'en' => [
            'title' => 'Solosync Setup',
            'heading' => 'Solosync Self-host',
            'intro' => 'This page is only for the first-time setup. The server ID is generated automatically using secure randomness, and setup cannot be run again after completion.',
            'fatal_https' => 'For security, first-time setup must be performed over HTTPS.',
            'fatal_private' => 'The private directory is not writable. Check the file permissions provided by your hosting service.',
            'lock_failed' => 'Could not acquire the setup lock.',
            'already_installed' => 'This server has already been set up.',
            'setup_key_invalid' => 'The setup key is incorrect.',
            'cross_site' => 'Setup requests from another site are not accepted.',
            'db_host_invalid' => 'The database host name is invalid.',
            'db_port_invalid' => 'The database port is invalid.',
            'db_name_invalid' => 'The database name is invalid.',
            'db_user_invalid' => 'The database username is invalid.',
            'db_password_long' => 'The database password is too long.',
            'admin_password_short' => 'The administrator password must be at least 12 characters.',
            'admin_password_mismatch' => 'The administrator passwords do not match.',
            'origin_long' => 'An allowed origin is too long.',
            'origin_format' => 'Enter allowed origins in the form https://example.com.',
            'origin_https' => 'Allowed origins must use HTTPS. HTTP is allowed only for localhost.',
            'origin_path' => 'Allowed origins cannot contain a path.',
            'origin_count' => 'Enter no more than 20 allowed origins.',
            'password_hash_failed' => 'Could not hash the administrator password.',
            'temp_config_failed' => 'Could not write the temporary configuration file.',
            'config_commit_failed' => 'Could not finalize the configuration file.',
            'setup_failed' => 'Setup failed. Check the database settings, PHP extensions, and file permissions.',
            'setup_complete' => 'Setup is complete. Create a connection key from the administration page.',
            'server_url' => 'Server URL to enter in Solocord',
            'open_admin' => 'Open administration page',
            'installed_notice' => 'This Solosync server is already set up. This setup page is disabled.',
            'first' => 'First:',
            'ownership_notice' => "To verify ownership, a setup key was automatically generated in private/setup.key.php. Open this file in your hosting file manager and paste only the key inside the quotes in return '...'; below.",
            'ownership' => 'Ownership verification',
            'setup_key' => 'Setup key',
            'database' => 'MySQL / MariaDB',
            'db_host' => 'Database host',
            'port' => 'Port',
            'db_name' => 'Database name',
            'db_user' => 'Database username',
            'db_password' => 'Database password',
            'administrator' => 'Administrator',
            'admin_password' => 'Administrator password (12+ characters)',
            'admin_password_confirm' => 'Confirm administrator password',
            'web_access' => 'Browser access',
            'allowed_origins' => 'Allowed origins (one per line)',
            'origins_help' => 'You can leave this empty if you only use native/Tauri clients. Wildcards are not supported.',
            'proxy_title' => 'Trust X-Forwarded-Proto from a trusted reverse proxy',
            'proxy_help' => 'Enable this only when a trusted reverse proxy such as Cloudflare terminates HTTPS for visitors and forwards requests to the PHP server over HTTP. Solosync will treat X-Forwarded-Proto: https as evidence that the public request used HTTPS. Leave this off when the PHP server receives HTTPS directly. Do not enable it when clients can bypass the proxy and send arbitrary forwarded headers directly to PHP.',
            'submit' => 'Set up',
            'language' => 'Language',
        ],
    ];
    return $messages[$lang][$key] ?? $messages['ja'][$key] ?? $key;
}

$lang = setupLanguage();
$root = dirname(__DIR__);
$privateDir = $root . '/private';
$configPath = $privateDir . '/config.php';
$setupKeyPath = $privateDir . '/setup.key.php';
$setupLockPath = $privateDir . '/setup.install.lock';
$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
umask(0077);

function setupHeaders(string $nonce): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
}

function setupRequestIsHttps(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') return true;
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0] ?? ''));
    return $forwarded === 'https';
}

function setupIsLocalRequest(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @return list<string> */
function parseAllowedOrigins(string $raw): array
{
    $origins = [];
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $origin = trim($line);
        if ($origin === '') continue;
        if (strlen($origin) > 300) throw new SetupValidationException(setupText('origin_long'));
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            throw new SetupValidationException(setupText('origin_format'));
        }
        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);
        $path = (string)($parts['path'] ?? '');
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new SetupValidationException(setupText('origin_https'));
        }
        if ($path !== '' && $path !== '/') throw new SetupValidationException(setupText('origin_path'));
        $normalized = $scheme . '://' . $host;
        if (isset($parts['port'])) $normalized .= ':' . (int)$parts['port'];
        if (!in_array($normalized, $origins, true)) $origins[] = $normalized;
        if (count($origins) > 20) throw new SetupValidationException(setupText('origin_count'));
    }
    return $origins;
}

/** @param list<string> $allowedOrigins */
function buildConfigPhp(
    string $dsn,
    string $dbUser,
    string $dbPassword,
    string $serverIdentity,
    string $adminPasswordHash,
    array $allowedOrigins,
    bool $trustForwardedProto
): string {
    $database = var_export(['dsn' => $dsn, 'user' => $dbUser, 'password' => $dbPassword], true);
    $origins = var_export($allowedOrigins, true);
    return "<?php\ndeclare(strict_types=1);\n\nreturn [\n"
        . "    'database' => {$database},\n"
        . "    'server_identity' => " . var_export($serverIdentity, true) . ",\n"
        . "    'admin_password_hash' => " . var_export($adminPasswordHash, true) . ",\n"
        . "    'require_https' => true,\n"
        . "    'trust_forwarded_proto' => " . ($trustForwardedProto ? 'true' : 'false') . ",\n"
        . "    'allowed_origins' => {$origins},\n"
        . "    'max_pack_bytes' => 16 * 1024 * 1024,\n"
        . "    'max_page_size' => 200,\n"
        . "    'minimum_poll_ms' => 5000,\n"
        . "    'quota_bytes' => 1024 * 1024 * 1024,\n"
        . "    'plugins_path' => dirname(__DIR__) . '/plugins',\n"
        . "];\n";
}

function setupBaseUrl(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/setup.php'), PHP_URL_PATH) ?? '/setup.php');
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/.');
    $scheme = setupRequestIsHttps() ? 'https' : 'http';
    return $scheme . '://' . $host . ($dir === '' ? '' : $dir);
}

setupHeaders($nonce);

if (!setupRequestIsHttps() && !setupIsLocalRequest()) {
    http_response_code(400);
    $fatal = setupText('fatal_https');
} elseif (!is_dir($privateDir) || !is_writable($privateDir)) {
    http_response_code(500);
    $fatal = setupText('fatal_private');
} else {
    $fatal = null;
}

$installed = is_file($configPath);
$error = null;
$success = null;
$serverUrl = setupBaseUrl();

if (!$installed && $fatal === null && !is_file($setupKeyPath)) {
    $generated = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $handle = @fopen($setupKeyPath, 'x');
    if ($handle !== false) {
        fwrite($handle, "<?php\nreturn " . var_export($generated, true) . ";\n");
        fclose($handle);
        @chmod($setupKeyPath, 0600);
    }
}

$defaults = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'allowed_origins' => "https://sc.kakikou.app\nhttp://localhost:8081",
    'trust_forwarded_proto' => false,
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$installed && $fatal === null) {
    $defaults['db_host'] = trim((string)($_POST['db_host'] ?? ''));
    $defaults['db_port'] = trim((string)($_POST['db_port'] ?? ''));
    $defaults['db_name'] = trim((string)($_POST['db_name'] ?? ''));
    $defaults['db_user'] = trim((string)($_POST['db_user'] ?? ''));
    $defaults['allowed_origins'] = trim((string)($_POST['allowed_origins'] ?? ''));
    $defaults['trust_forwarded_proto'] = isset($_POST['trust_forwarded_proto']);

    $lock = @fopen($setupLockPath, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        $error = setupText('lock_failed');
    } else {
        try {
            clearstatcache(true, $configPath);
            if (is_file($configPath)) throw new SetupValidationException(setupText('already_installed'));

            $storedSetupKey = is_file($setupKeyPath) ? require $setupKeyPath : null;
            $providedSetupKey = trim((string)($_POST['setup_key'] ?? ''));
            if (!is_string($storedSetupKey) || $providedSetupKey === ''
                || !hash_equals($storedSetupKey, $providedSetupKey)) {
                throw new SetupValidationException(setupText('setup_key_invalid'));
            }

            $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
            if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
                throw new SetupValidationException(setupText('cross_site'));
            }

            $host = $defaults['db_host'];
            $port = $defaults['db_port'];
            $dbName = $defaults['db_name'];
            $dbUser = $defaults['db_user'];
            $dbPassword = (string)($_POST['db_password'] ?? '');
            $adminPassword = (string)($_POST['admin_password'] ?? '');
            $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]{1,253}$/D', $host)) throw new SetupValidationException(setupText('db_host_invalid'));
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new SetupValidationException(setupText('db_port_invalid'));
            if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $dbName)) throw new SetupValidationException(setupText('db_name_invalid'));
            if ($dbUser === '' || strlen($dbUser) > 128) throw new SetupValidationException(setupText('db_user_invalid'));
            if (strlen($dbPassword) > 1024) throw new SetupValidationException(setupText('db_password_long'));
            if (strlen($adminPassword) < 12) throw new SetupValidationException(setupText('admin_password_short'));
            if (!hash_equals($adminPassword, $adminPasswordConfirm)) throw new SetupValidationException(setupText('admin_password_mismatch'));
            $allowedOrigins = parseAllowedOrigins($defaults['allowed_origins']);

            $dsn = 'mysql:host=' . $host . ';port=' . (int)$port . ';dbname=' . $dbName . ';charset=utf8mb4';
            $serverIdentity = bin2hex(random_bytes(32));
            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) throw new RuntimeException(setupText('password_hash_failed'));
            $tempPath = $privateDir . '/.config.setup.' . bin2hex(random_bytes(12)) . '.php';
            $configPhp = buildConfigPhp(
                $dsn,
                $dbUser,
                $dbPassword,
                $serverIdentity,
                $passwordHash,
                $allowedOrigins,
                (bool)$defaults['trust_forwarded_proto']
            );
            if (file_put_contents($tempPath, $configPhp, LOCK_EX) === false) throw new RuntimeException(setupText('temp_config_failed'));
            @chmod($tempPath, 0600);
            try {
                $candidate = Config::load($tempPath);
                $db = Database::connect($candidate);
                $admin = new AdminService($db, $candidate);
                $admin->install($root . '/migrations');
                if (!@rename($tempPath, $configPath)) throw new RuntimeException(setupText('config_commit_failed'));
                @chmod($configPath, 0600);
                @unlink($setupKeyPath);
                $installed = true;
                $success = setupText('setup_complete');
            } finally {
                if (isset($tempPath) && is_file($tempPath)) @unlink($tempPath);
            }
        } catch (SetupValidationException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('[Solosync setup] ' . $exception::class . ': ' . $exception->getMessage());
            $error = setupText('setup_failed');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

?><!doctype html>
<html lang="<?= e($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(setupText('title')) ?></title>
  <style nonce="<?= e($nonce) ?>">
    :root { color-scheme: dark; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; background:#202225; color:#f2f3f5; }
    * { box-sizing:border-box; } body { margin:0; padding:32px 16px; } main { width:min(760px,100%); margin:auto; }
    .card { background:#2b2d31; border:1px solid #3f4147; border-radius:14px; padding:24px; box-shadow:0 16px 45px #0004; }
    h1 { margin:0 0 8px; font-size:26px; } h2 { font-size:17px; margin:26px 0 12px; } p { color:#b5bac1; line-height:1.65; }
    label { display:block; margin:14px 0 6px; font-weight:650; } input,textarea { width:100%; padding:11px 12px; border:1px solid #4e5058; border-radius:7px; background:#1e1f22; color:#f2f3f5; font:inherit; }
    textarea { min-height:100px; resize:vertical; } .grid { display:grid; grid-template-columns:1fr 140px; gap:12px; } .check { display:flex; gap:9px; align-items:flex-start; font-weight:400; color:#f2f3f5; margin:0; } .check input { width:auto; margin:4px 0 0; flex:0 0 auto; }
    button { margin-top:22px; width:100%; border:0; border-radius:7px; padding:12px 16px; background:#5865f2; color:white; font-weight:700; font-size:15px; cursor:pointer; }
    code,.secret { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; word-break:break-all; } .secret { display:block; padding:14px; border-radius:8px; background:#111214; border:1px solid #4e5058; user-select:all; }
    .notice,.error,.success { padding:12px 14px; border-radius:8px; margin:16px 0; line-height:1.55; } .notice { background:#1e2a3b; border:1px solid #3b6ca8; } .error { background:#3b1f23; border:1px solid #a63c48; } .success { background:#183424; border:1px solid #2d8a4e; }
    .language { display:flex; justify-content:flex-end; gap:8px; margin:-4px 0 14px; font-size:14px; } .language strong { color:#b5bac1; font-weight:600; }
    .proxy { margin-top:18px; padding:14px; border:1px solid #4e5058; border-radius:9px; background:#232428; } .proxy p { margin:8px 0 0 27px; font-size:13px; }
    a { color:#8ea1ff; } small { color:#949ba4; } @media (max-width:560px) { .grid { grid-template-columns:1fr; } .card { padding:18px; } .proxy p { margin-left:0; } }
  </style>
</head>
<body><main><div class="card">
  <nav class="language" aria-label="<?= e(setupText('language')) ?>">
    <strong><?= e(setupText('language')) ?>:</strong>
    <a href="?lang=ja" lang="ja">日本語</a>
    <a href="?lang=en" lang="en">English</a>
  </nav>
  <h1><?= e(setupText('heading')) ?></h1>
  <p><?= e(setupText('intro')) ?></p>

  <?php if ($fatal !== null): ?>
    <div class="error"><?= e($fatal) ?></div>
  <?php elseif ($success !== null): ?>
    <div class="success"><?= e($success) ?></div>
    <h2><?= e(setupText('server_url')) ?></h2>
    <span class="secret"><?= e($serverUrl) ?></span>
    <p><a href="<?= e($serverUrl . '/admin/') ?>"><?= e(setupText('open_admin')) ?></a></p>
  <?php elseif ($installed): ?>
    <div class="success"><?= e(setupText('installed_notice')) ?></div>
    <p><a href="<?= e($serverUrl . '/admin/') ?>"><?= e(setupText('open_admin')) ?></a></p>
  <?php else: ?>
    <div class="notice"><strong><?= e(setupText('first')) ?></strong> <?= e(setupText('ownership_notice')) ?></div>
    <?php if ($error !== null): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="lang" value="<?= e($lang) ?>">
      <h2><?= e(setupText('ownership')) ?></h2>
      <label for="setup_key"><?= e(setupText('setup_key')) ?></label>
      <input id="setup_key" name="setup_key" type="password" required autocomplete="off">

      <h2><?= e(setupText('database')) ?></h2>
      <div class="grid">
        <div><label for="db_host"><?= e(setupText('db_host')) ?></label><input id="db_host" name="db_host" value="<?= e($defaults['db_host']) ?>" required></div>
        <div><label for="db_port"><?= e(setupText('port')) ?></label><input id="db_port" name="db_port" inputmode="numeric" value="<?= e($defaults['db_port']) ?>" required></div>
      </div>
      <label for="db_name"><?= e(setupText('db_name')) ?></label><input id="db_name" name="db_name" value="<?= e($defaults['db_name']) ?>" required>
      <label for="db_user"><?= e(setupText('db_user')) ?></label><input id="db_user" name="db_user" value="<?= e($defaults['db_user']) ?>" required>
      <label for="db_password"><?= e(setupText('db_password')) ?></label><input id="db_password" name="db_password" type="password" required autocomplete="new-password">

      <h2><?= e(setupText('administrator')) ?></h2>
      <label for="admin_password"><?= e(setupText('admin_password')) ?></label><input id="admin_password" name="admin_password" type="password" minlength="12" required autocomplete="new-password">
      <label for="admin_password_confirm"><?= e(setupText('admin_password_confirm')) ?></label><input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="12" required autocomplete="new-password">

      <h2><?= e(setupText('web_access')) ?></h2>
      <label for="allowed_origins"><?= e(setupText('allowed_origins')) ?></label>
      <textarea id="allowed_origins" name="allowed_origins" spellcheck="false"><?= e($defaults['allowed_origins']) ?></textarea>
      <small><?= e(setupText('origins_help')) ?></small>
      <div class="proxy">
        <label class="check">
          <input type="checkbox" name="trust_forwarded_proto" value="1" <?= $defaults['trust_forwarded_proto'] ? 'checked' : '' ?>>
          <span><?= e(setupText('proxy_title')) ?></span>
        </label>
        <p><?= e(setupText('proxy_help')) ?></p>
      </div>

      <button type="submit"><?= e(setupText('submit')) ?></button>
    </form>
  <?php endif; ?>
</div></main></body>
</html>
