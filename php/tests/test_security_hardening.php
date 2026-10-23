<?php

declare(strict_types=1);

// Command-line only: this file must never run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Security regression tests.
 * Starts the app on a free local port with its own temporary database and attacks it the way
 * the security audit described. Never touches php/storage/dptech.db.
 *
 * Run: php php/tests/test_security_hardening.php
 */

$appRoot = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptech_sec_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);
$dbPath = str_replace('\\', '/', $tmpDir) . '/security.db';
$adminPassword = 'TestAdminPass#2026';
$appSecret = bin2hex(random_bytes(32));
$bridgeSecret = bin2hex(random_bytes(16));

// The test process and the server share the same temporary database and secrets
foreach ([
    'DATABASE_URL' => 'sqlite:///' . $dbPath,
    'SECRET_KEY' => $appSecret,
    'WHATSAPP_BRIDGE_SECRET' => $bridgeSecret,
    'ADMIN_USERNAME' => 'admin',
    'ADMIN_PASSWORD' => $adminPassword,
] as $k => $v) {
    putenv("{$k}={$v}");
}

require_once $appRoot . '/bootstrap.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  ✓ {$label}\n";
    } else {
        $failures++;
        echo "  ✗ {$label}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    }
}

// ---- Start the app -------------------------------------------------------------------------

$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
fclose($probe);

$publicDir = $appRoot . DIRECTORY_SEPARATOR . 'public';
$serverLog = $tmpDir . DIRECTORY_SEPARATOR . 'server.log';
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $publicDir, $publicDir . DIRECTORY_SEPARATOR . 'index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    $appRoot,
    null
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start the test server\n");
    exit(1);
}

register_shutdown_function(static function () use ($proc, $tmpDir): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
});

$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if ($c) {
        fclose($c);
        break;
    }
    usleep(100000);
}

/** @return array{0:int,1:string,2:array} status, body, lower-cased response headers */
function http(string $method, string $path, ?array $json = null, array $headers = [], ?string $rawBody = null, array $opts = []): array
{
    global $base;
    $ch = curl_init($base . $path);
    $reqHeaders = $headers;
    if ($json !== null) {
        $rawBody = json_encode($json);
        $reqHeaders[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_PATH_AS_IS => !empty($opts['path_as_is']),
    ]);
    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    }
    $resp = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $hdrs = [];
    foreach (explode("\r\n", substr($resp, 0, $hdrSize)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdrs[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, substr($resp, $hdrSize), $hdrs];
}

function bearer(string $token): array
{
    return ['Authorization: Bearer ' . $token];
}

function login(string $user, string $pass): array
{
    [$st, $body] = http('POST', '/api/auth/login', ['username' => $user, 'password' => $pass]);
    $data = json_decode($body, true) ?: [];
    return [$st, (string) ($data['access_token'] ?? '')];
}

try {
    [$st] = http('GET', '/health');
    check($st === 200, 'test server is up and healthy', "HTTP {$st}; see {$serverLog}");

    // ---- 1. Login tokens ---------------------------------------------------------------------
    echo "\n[1] Login tokens and signing key\n";
    [$st, $adminToken] = login('admin', $adminPassword);
    check($st === 200 && $adminToken !== '', 'admin can log in with the configured password');
    [$st] = http('GET', '/api/clients', null, bearer($adminToken));
    check($st === 200, 'a real token is accepted');

    foreach (['datapoint-secret-key-change-in-production', '9b8bd7f3b1684c2988d44d9a53d9cc4ff6fee49d4efdfd9fc65a9f112f14e7f7', 'ufqEozm0UejRjcpUVF4ySMBER_IrpxFKrdRZn-sngA_dWam3s36zvRoSoBZXUMinqUk'] as $old) {
        $forged = createAccessToken(['sub' => 'admin', 'role' => 'admin'], $old, 60, 'HS256');
        [$st] = http('GET', '/api/clients', null, bearer($forged));
        check($st === 401, 'token forged with a published key is rejected (' . substr($old, 0, 12) . '…)', "HTTP {$st}");
    }
    check(app_secret_key() === $appSecret, 'a strong SECRET_KEY from .env is used');

    // ---- 2. Endpoints that used to be open --------------------------------------------------
    echo "\n[2] Endpoints that required no login\n";
    [$st] = http('POST', '/api/invoices/pdf', ['invoice_no' => 'X']);
    check($st === 401, 'POST /api/invoices/pdf needs login', "HTTP {$st}");
    [$st] = http('GET', '/api/whatsapp/status');
    check($st === 401, 'GET /api/whatsapp/status needs login', "HTTP {$st}");
    [$st] = http('POST', '/api/tax/calculate', ['items' => []]);
    check($st === 401, 'POST /api/tax/calculate needs login', "HTTP {$st}");
    [$st] = http('POST', '/api/whatsapp/agent-bridge', ['sender_phone' => '1', 'message' => 'invoice']);
    check($st === 403, 'agent-bridge refuses a caller without the shared secret', "HTTP {$st}");
    [$st] = http('POST', '/api/whatsapp/agent-bridge', ['sender_phone' => '1', 'message' => 'invoice'], ['X-Bridge-Secret: wrong-secret-value']);
    check($st === 403, 'agent-bridge refuses a wrong secret', "HTTP {$st}");

    // ---- 3. WhatsApp document leak -----------------------------------------------------------
    echo "\n[3] WhatsApp agent only sends documents to their own customer\n";
    $db = database_connection();
    $db->exec("INSERT INTO clients (name, mobile, is_active) VALUES ('Alice Ltd', '03001112222', 1), ('Bob Traders', '03339998888', 1)");
    $alice = (int) $db->query("SELECT id FROM clients WHERE name='Alice Ltd'")->fetchColumn();
    $bob = (int) $db->query("SELECT id FROM clients WHERE name='Bob Traders'")->fetchColumn();
    $insInv = $db->prepare("INSERT INTO invoices (invoice_no, client_id, invoice_date, status, subtotal, total_amount, balance_due) VALUES (:n, :c, '2026-10-01', 'sent', 1000, 1180, 1180)");
    $insInv->execute(['n' => 'INV-202610-00001', 'c' => $alice]);
    $insInv->execute(['n' => 'INV-202610-00002', 'c' => $bob]);
    $bridge = ['X-Bridge-Secret: ' . $bridgeSecret];

    $ask = static function (string $phone, string $msg) use ($bridge): array {
        [$st, $body] = http('POST', '/api/whatsapp/agent-bridge', ['sender_phone' => $phone, 'sender_name' => 'Tester', 'message' => $msg], $bridge);
        return [$st, json_decode($body, true) ?: []];
    };
    [$st, $r] = $ask('923001112222', 'send INV-202610-00002');
    check($st === 200 && ($r['reply_type'] ?? '') !== 'document', "Alice cannot fetch Bob's invoice by number", json_encode($r['reply_type'] ?? null));
    [$st, $r] = $ask('923450000000', 'invoice');
    check($st === 200 && ($r['reply_type'] ?? '') !== 'document', 'an unknown number asking for "invoice" gets no document', json_encode($r['reply_type'] ?? null));
    [$st, $r] = $ask('923450000000', 'send INV-202610-00001');
    check($st === 200 && ($r['reply_type'] ?? '') !== 'document', 'an unknown number cannot fetch any invoice by number', json_encode($r['reply_type'] ?? null));
    [$st, $r] = $ask('923001112222', 'send INV-202610-00001');
    check($st === 200 && ($r['reply_type'] ?? '') === 'document', "Alice still gets her own invoice", json_encode($r['reply_type'] ?? $r));

    // ---- 4. Logo upload ----------------------------------------------------------------------
    echo "\n[4] Logo upload\n";
    $evil = base64_encode("<?php echo 'pwned'; ?>");
    [$st, $body] = http('POST', '/api/companies/upload-logo', ['image_base64' => 'data:image/php;base64,' . $evil], bearer($adminToken));
    check($st === 422, 'a PHP file disguised as an image is rejected', "HTTP {$st} {$body}");
    [$st] = http('POST', '/api/companies/upload-logo', ['image_base64' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')], bearer($adminToken));
    check($st === 422, 'an SVG (script carrier) is rejected', "HTTP {$st}");
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    [$st, $body] = http('POST', '/api/companies/upload-logo', ['image_base64' => base64_encode($png)], bearer($adminToken));
    $url = (json_decode($body, true) ?: [])['logo_url'] ?? '';
    check($st === 200 && preg_match('#^/static/uploads/logo_[0-9a-f]{16}\.png$#', $url) === 1, 'a real PNG is accepted with a random name and .png extension', "HTTP {$st} {$body}");
    if ($url !== '') {
        @unlink($publicDir . str_replace('/', DIRECTORY_SEPARATOR, $url));
    }

    // ---- 5. Google Sheet importer (local files / internal servers) ---------------------------
    echo "\n[5] Sheet importer cannot read local files or internal servers\n";
    foreach (['file:///etc/passwd', 'file:///C:/Windows/win.ini', 'http://127.0.0.1:' . $port . '/health', 'https://evil.example.com/a.csv'] as $badUrl) {
        [$st, $body] = http('POST', '/api/products/sync-google-sheet', ['sheet_url' => $badUrl], bearer($adminToken));
        check($st === 400 && str_contains($body, 'Google Sheets link'), "refused: {$badUrl}", "HTTP {$st} {$body}");
    }
    [$st] = http('POST', '/api/products/settings', ['google_sheet_url' => 'file:///etc/passwd'], bearer($adminToken));
    check($st === 422, 'saving a non-Google link as the sheet URL is refused', "HTTP {$st}");

    // ---- 6. Permissions ----------------------------------------------------------------------
    echo "\n[6] Staff only see what their permissions allow\n";
    [$st] = http('POST', '/api/users', ['username' => 'weak', 'email' => 'weak@example.com', 'password' => 'short', 'full_name' => 'Weak'], bearer($adminToken));
    check($st === 422, 'a 5-character password is refused for new users', "HTTP {$st}");
    [$st, $body] = http('POST', '/api/users', [
        'username' => 'clerk', 'email' => 'clerk@example.com', 'password' => 'ClerkPass#2026', 'full_name' => 'Clerk', 'role' => 'staff',
        'permissions' => ['invoices' => ['view' => true], 'clients' => ['view' => true]],
    ], bearer($adminToken));
    check($st === 201, 'staff user created', "HTTP {$st} {$body}");
    [, $clerkToken] = login('clerk', 'ClerkPass#2026');
    check($clerkToken !== '', 'staff user can log in');
    $db->exec("INSERT INTO products (name, sku, cost_price, unit_price, is_active) VALUES ('Secret-margin item', 'SM-1', 500, 550, 1)");
    foreach (['/api/reports' => 'reports', '/api/expenses' => 'expenses', '/api/purchases' => 'purchases', '/api/suppliers' => 'suppliers', '/api/projects' => 'projects', '/api/inventory' => 'inventory', '/api/prices' => 'prices', '/api/agent/conversations' => 'conversations', '/api/products/export-csv' => 'product export'] as $path => $label) {
        $needsClientsView = $path === '/api/agent/conversations';
        [$st] = http('GET', $path, null, bearer($clerkToken));
        check($needsClientsView ? $st === 200 : $st === 403, "staff without access gets " . ($needsClientsView ? '200 (has clients.view)' : '403') . " on {$label}", "HTTP {$st}");
    }
    [$st, $body] = http('GET', '/api/products', null, bearer($clerkToken));
    $rows = json_decode($body, true) ?: [];
    check($st === 200 && $rows !== [] && !array_key_exists('cost_price', $rows[0]), 'product list hides cost_price from staff without products.view', "HTTP {$st}");
    [$st, $body] = http('GET', '/api/products', null, bearer($adminToken));
    $rows = json_decode($body, true) ?: [];
    check($st === 200 && $rows !== [] && array_key_exists('cost_price', $rows[0]), 'admin still sees cost_price');
    $invId = (int) $db->query("SELECT id FROM invoices ORDER BY id LIMIT 1")->fetchColumn();
    $estCount = http('GET', "/api/estimates/1/pdf", null, bearer($clerkToken));
    check($estCount[0] === 403, 'estimate PDF needs estimates.view', "HTTP {$estCount[0]}");
    [$st] = http('GET', "/api/invoices/{$invId}/pdf", null, bearer($clerkToken));
    check($st !== 403 && $st !== 401, 'staff WITH invoices.view can open an invoice PDF', "HTTP {$st}");

    // ---- 7. Passwords ------------------------------------------------------------------------
    echo "\n[7] Changing a password\n";
    [$st] = http('POST', '/api/auth/change-password', ['new_password' => 'BrandNewPass#1', 'confirm_password' => 'BrandNewPass#1'], bearer($clerkToken));
    check($st === 400, 'current password is required', "HTTP {$st}");
    [$st] = http('POST', '/api/auth/change-password', ['current_password' => 'wrong', 'new_password' => 'BrandNewPass#1', 'confirm_password' => 'BrandNewPass#1'], bearer($clerkToken));
    check($st === 400, 'a wrong current password is refused', "HTTP {$st}");
    [$st] = http('POST', '/api/auth/change-password', ['current_password' => 'ClerkPass#2026', 'new_password' => 'abc', 'confirm_password' => 'abc'], bearer($clerkToken));
    check($st === 422, 'a too-short new password is refused', "HTTP {$st}");
    [$st] = http('POST', '/api/auth/change-password', ['current_password' => 'ClerkPass#2026', 'new_password' => 'BrandNewPass#1', 'confirm_password' => 'BrandNewPass#1'], bearer($clerkToken));
    check($st === 200, 'with the right current password it works', "HTTP {$st}");

    // ---- 8. Headers, static files ------------------------------------------------------------
    echo "\n[8] Response headers and static files\n";
    [$st, , $h] = http('GET', '/login');
    check(($h['x-content-type-options'] ?? '') === 'nosniff', 'X-Content-Type-Options: nosniff');
    check(($h['x-frame-options'] ?? '') === 'SAMEORIGIN', 'X-Frame-Options: SAMEORIGIN');
    check(str_contains($h['content-security-policy'] ?? '', "frame-ancestors 'self'"), 'Content-Security-Policy frame-ancestors');
    check(isset($h['referrer-policy'], $h['permissions-policy']), 'Referrer-Policy and Permissions-Policy are set');
    check(!isset($h['x-powered-by']), 'X-Powered-By is hidden');
    foreach (['/static/../bootstrap.php', '/static/%2e%2e/bootstrap.php', '/static/../../.env', '/static/css/../../bootstrap.php'] as $trav) {
        [$st, $body] = http('GET', $trav, null, [], null, ['path_as_is' => true]);
        check($st !== 200 || !str_contains($body, '<?php'), "path traversal does not leak source: {$trav}", "HTTP {$st}");
    }

    // ---- 9. Maintenance scripts cannot run from the web --------------------------------------
    echo "\n[9] Maintenance scripts\n";
    $cgi = trim((string) @shell_exec((PHP_OS_FAMILY === 'Windows' ? 'where php-cgi 2>NUL' : 'command -v php-cgi 2>/dev/null')));
    $cgi = strtok($cgi, "\r\n") ?: '';
    $guardedAll = true;
    foreach (array_merge(glob($appRoot . '/scripts/*.php') ?: [], glob($appRoot . '/tests/*.php') ?: [], [dirname($appRoot) . '/run.php']) as $script) {
        if (!str_contains((string) file_get_contents($script), "PHP_SAPI !== 'cli'")) {
            $guardedAll = false;
            echo "    missing CLI guard: " . basename($script) . "\n";
        }
    }
    check($guardedAll, 'every script, test and run.php has a command-line-only guard');
    if ($cgi !== '' && is_file($cgi)) {
        $out = (string) shell_exec('"' . $cgi . '" "' . $appRoot . '/scripts/reset_admin_password.php" 2>&1');
        check(!str_contains($out, 'Password Reset Utility') && !str_contains($out, 'Created new Admin') && !str_contains($out, 'Password:'), 'reset_admin_password.php does nothing when run as a web (CGI) request', substr($out, 0, 80));
    } else {
        echo "  - (php-cgi not installed: only the static guard check above was run)\n";
    }

    // ---- 10. Login throttling (last: it locks addresses) -------------------------------------
    echo "\n[10] Brute-force protection\n";
    $codes = [];
    for ($i = 0; $i < 7; $i++) {
        [$st] = http('POST', '/api/auth/login', ['username' => 'nobody@example.com', 'password' => 'guess' . $i]);
        $codes[] = $st;
    }
    check(array_slice($codes, 0, 5) === [401, 401, 401, 401, 401] && $codes[5] === 429 && $codes[6] === 429, 'after 5 wrong passwords the account is locked (429)', implode(',', $codes));
    [$st, , $h] = http('POST', '/api/auth/login', ['username' => 'nobody@example.com', 'password' => 'again']);
    check($st === 429 && isset($h['retry-after']), 'a Retry-After header tells the client how long to wait', "HTTP {$st}");
} finally {
    echo "\n";
}

echo $failures === 0 ? "=== All {$checks} security checks passed ===\n" : "=== {$failures} of {$checks} security checks FAILED ===\n";
exit($failures === 0 ? 0 : 1);
