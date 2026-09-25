<?php
declare(strict_types=1);

namespace Solosync\SyncServer\Core;

final class AdminAuth
{
    private const COOKIE_NAME = 'solosync_admin_session';
    private const SESSION_TTL_SECONDS = 12 * 60 * 60;

    public function __construct(private readonly Config $config) {}

    /** @return array{csrf:string,expiresAt:int} */
    public function login(string $password, string $cookiePath): array
    {
        $passwordHash = $this->config->string('admin_password_hash');
        if (!password_verify($password, $passwordHash)) {
            throw new ApiException(401, 'INVALID_ADMIN_CREDENTIALS', 'Invalid administrator password');
        }

        $expiresAt = time() + self::SESSION_TTL_SECONDS;
        $session = [
            'v' => 1,
            'exp' => $expiresAt,
            'csrf' => self::base64UrlEncode(random_bytes(24)),
        ];
        $payload = json_encode($session, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->signingKey(), true));
        $token = $encodedPayload . '.' . $signature;

        if (!setcookie(self::COOKIE_NAME, $token, $this->cookieOptions($cookiePath, $expiresAt))) {
            throw new \RuntimeException('Unable to create administrator session');
        }

        return ['csrf' => $session['csrf'], 'expiresAt' => $expiresAt];
    }

    public function logout(string $cookiePath): void
    {
        setcookie(self::COOKIE_NAME, '', $this->cookieOptions($cookiePath, time() - 3600));
    }

    /** @return array{csrf:string,expiresAt:int}|null */
    public function session(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 2048) return null;

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) return null;
        [$encodedPayload, $encodedSignature] = $parts;
        $providedSignature = self::base64UrlDecode($encodedSignature);
        if ($providedSignature === null || strlen($providedSignature) !== 32) return null;

        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->signingKey(), true);
        if (!hash_equals($expectedSignature, $providedSignature)) return null;

        $payload = self::base64UrlDecode($encodedPayload);
        if ($payload === null) return null;
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)
            || ($decoded['v'] ?? null) !== 1
            || !is_int($decoded['exp'] ?? null)
            || !is_string($decoded['csrf'] ?? null)
            || !preg_match('/^[A-Za-z0-9_-]{32}$/D', $decoded['csrf'])
            || $decoded['exp'] <= time()) {
            return null;
        }

        return ['csrf' => $decoded['csrf'], 'expiresAt' => $decoded['exp']];
    }

    /** @return array{csrf:string,expiresAt:int} */
    public function requireAuth(): array
    {
        $session = $this->session();
        if ($session === null) {
            throw new ApiException(401, 'ADMIN_AUTH_REQUIRED', 'Administrator authentication required');
        }
        return $session;
    }

    public function requireMutationHeader(): void
    {
        $session = $this->requireAuth();
        $provided = $_SERVER['HTTP_X_SOLOSYNC_ADMIN'] ?? null;
        if (!is_string($provided) || !hash_equals($session['csrf'], $provided)) {
            throw new ApiException(403, 'ADMIN_CONFIRMATION_REQUIRED', 'Administrator CSRF token is required');
        }
    }

    /** @return array{expires:int,path:string,secure:bool,httponly:bool,samesite:string} */
    private function cookieOptions(string $cookiePath, int $expires): array
    {
        $path = '/' . trim($cookiePath, '/');
        if ($path !== '/') $path .= '/';
        return [
            'expires' => $expires,
            'path' => $path,
            'secure' => $this->config->requestIsHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ];
    }

    private function signingKey(): string
    {
        return hash(
            'sha256',
            "Solosync/admin-session/v1\0"
                . $this->config->string('server_identity')
                . "\0"
                . $this->config->string('admin_password_hash'),
            true,
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) return null;
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($decoded) ? $decoded : null;
    }
}
