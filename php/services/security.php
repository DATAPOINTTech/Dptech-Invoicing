<?php

declare(strict_types=1);

/**
 * Shared security helpers: signing secrets, response headers, login throttling.
 */

/**
 * Signing keys that were published in the source code or in example configs.
 * They are never accepted, even if someone puts one in .env.
 */
const KNOWN_WEAK_SECRET_KEYS = [
    '9b8bd7f3b1684c2988d44d9a53d9cc4ff6fee49d4efdfd9fc65a9f112f14e7f7',
    'ufqEozm0UejRjcpUVF4ySMBER_IrpxFKrdRZn-sngA_dWam3s36zvRoSoBZXUMinqUk',
    'datapoint-secret-key-change-in-production',
    'replace-with-a-long-random-secret',
];

function is_https_request(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * A random secret stored once in storage/.<name>, created on first use.
 * Lets each install have its own unique secret without any manual setup.
 */
function persisted_secret(string $name): string
{
    static $cache = [];
    if (isset($cache[$name])) {
        return $cache[$name];
    }

    $dir = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . '.' . $name;

    $secret = is_file($file) ? trim((string) @file_get_contents($file)) : '';
    if (strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents($file, $secret, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write {$file}. Make the storage/ folder writable, or set SECRET_KEY in .env.");
        }
        @chmod($file, 0600);
    }

    return $cache[$name] = $secret;
}

/** The key used to sign login tokens. */
function app_secret_key(): string
{
    $configured = trim((string) env_value('SECRET_KEY', ''));
    $isPlaceholder = (bool) preg_match('/^(change|replace|your[-_ ]|dptech|datapoint)/i', $configured);
    if (strlen($configured) >= 32 && !$isPlaceholder && !in_array($configured, KNOWN_WEAK_SECRET_KEYS, true)) {
        return $configured;
    }
    return persisted_secret('app_secret');
}

/** Shared secret between the PHP app and the WhatsApp (Node) service. */
function whatsapp_bridge_secret(): string
{
    $configured = trim((string) env_value('WHATSAPP_BRIDGE_SECRET', ''));
    return strlen($configured) >= 16 ? $configured : persisted_secret('bridge_secret');
}

function require_whatsapp_bridge_secret(): void
{
    $given = (string) ($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
    if ($given === '' || !hash_equals(whatsapp_bridge_secret(), $given)) {
        json_response(['detail' => 'Forbidden'], 403);
    }
}

function send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    if (is_https_request()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

// ---- Login throttling ----------------------------------------------------------------------

const MIN_PASSWORD_LENGTH = 8;

const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_FAILURES_PER_USER = 5;
const LOGIN_MAX_FAILURES_PER_IP = 20;

function login_throttle_ready(PDO $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                throttle_key VARCHAR(190) NOT NULL,
                attempted_at INT NOT NULL,
                INDEX idx_login_attempts_key (throttle_key, attempted_at)
            )');
        } else {
            $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                throttle_key VARCHAR(190) NOT NULL,
                attempted_at INTEGER NOT NULL
            )');
            $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_key ON login_attempts (throttle_key, attempted_at)');
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Login throttle unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

function login_throttle_keys(string $username, string $ip): array
{
    return [
        'u:' . substr(strtolower(trim($username)), 0, 100) => LOGIN_MAX_FAILURES_PER_USER,
        'i:' . substr($ip, 0, 64) => LOGIN_MAX_FAILURES_PER_IP,
    ];
}

/** Seconds the caller must wait before trying again, or 0 if they may try. */
function login_throttle_wait(PDO $db, string $username, string $ip): int
{
    if (!login_throttle_ready($db)) {
        return 0;
    }
    $since = time() - LOGIN_WINDOW_SECONDS;
    $wait = 0;
    foreach (login_throttle_keys($username, $ip) as $key => $limit) {
        $stmt = $db->prepare('SELECT COUNT(*), MIN(attempted_at) FROM login_attempts WHERE throttle_key = :k AND attempted_at > :since');
        $stmt->execute(['k' => $key, 'since' => $since]);
        [$count, $oldest] = array_map('intval', $stmt->fetch(PDO::FETCH_NUM));
        if ($count >= $limit) {
            $wait = max($wait, $oldest + LOGIN_WINDOW_SECONDS - time());
        }
    }
    return max(0, $wait);
}

function login_throttle_record_failure(PDO $db, string $username, string $ip): void
{
    if (!login_throttle_ready($db)) {
        return;
    }
    $insert = $db->prepare('INSERT INTO login_attempts (throttle_key, attempted_at) VALUES (:k, :t)');
    foreach (array_keys(login_throttle_keys($username, $ip)) as $key) {
        $insert->execute(['k' => $key, 't' => time()]);
    }
    $db->prepare('DELETE FROM login_attempts WHERE attempted_at < :old')->execute(['old' => time() - 86400]);
}

function login_throttle_clear(PDO $db, string $username): void
{
    if (!login_throttle_ready($db)) {
        return;
    }
    $db->prepare('DELETE FROM login_attempts WHERE throttle_key = :k')
        ->execute(['k' => 'u:' . substr(strtolower(trim($username)), 0, 100)]);
}
