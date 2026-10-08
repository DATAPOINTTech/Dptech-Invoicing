<?php

declare(strict_types=1);

// Dynamically resolve directory containing bootstrap.php
$appRoot = null;
$appCandidates = [
    dirname(__DIR__),                                      // standard: php/public -> php/
    __DIR__ . DIRECTORY_SEPARATOR . 'php',                 // if index.php is at repo root
    __DIR__,                                               // if bootstrap is in same dir
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_backend' . DIRECTORY_SEPARATOR . 'php',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app_backend',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'php',
];

foreach ($appCandidates as $cand) {
    if (is_file($cand . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
        $appRoot = $cand;
        break;
    }
}

if ($appRoot === null) {
    http_response_code(500);
    die('Server configuration error: Unable to locate bootstrap.php. Please check backend files.');
}

require_once $appRoot . DIRECTORY_SEPARATOR . 'bootstrap.php';

// Require all modular route handlers
require_once $appRoot . '/routes/auth.php';
require_once $appRoot . '/routes/dashboard.php';
require_once $appRoot . '/routes/clients.php';
require_once $appRoot . '/routes/suppliers.php';
require_once $appRoot . '/routes/products.php';
require_once $appRoot . '/routes/inventory.php';
require_once $appRoot . '/routes/purchases.php';
require_once $appRoot . '/routes/expenses.php';
require_once $appRoot . '/routes/projects.php';
require_once $appRoot . '/routes/estimates.php';
require_once $appRoot . '/routes/invoices.php';
require_once $appRoot . '/routes/prices.php';
require_once $appRoot . '/routes/reports.php';
require_once $appRoot . '/routes/agent.php';
require_once $appRoot . '/routes/companies.php';
require_once $appRoot . '/routes/whatsapp.php';

function serve_static_asset(string $path): bool
{
    global $appRoot;
    if ($path === '/favicon.ico') {
        $iconCandidates = [
            __DIR__ . '/static/img/favicon.svg',
            ($appRoot ?? __DIR__) . '/public/static/img/favicon.svg',
            __DIR__ . '/php/public/static/img/favicon.svg',
        ];
        foreach ($iconCandidates as $iconPath) {
            if (is_file($iconPath)) {
                header('Content-Type: image/svg+xml');
                readfile($iconPath);
                exit;
            }
        }
        return false;
    }

    if (!str_starts_with($path, '/static/')) {
        return false;
    }

    $relPath = substr($path, strlen('/static/'));
    $candidates = [
        __DIR__ . '/static/' . $relPath,
        dirname(__DIR__) . '/public/static/' . $relPath,
        ($appRoot ?? __DIR__) . '/public/static/' . $relPath,
        __DIR__ . '/php/public/static/' . $relPath,
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $mimes = [
                'css' => 'text/css; charset=utf-8',
                'js' => 'application/javascript; charset=utf-8',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'ico' => 'image/x-icon',
                'json' => 'application/json',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
            ];
            $contentType = $mimes[$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $contentType);
            header('Cache-Control: public, max-age=86400');
            readfile($candidate);
            exit;
        }
    }

    return false;
}

function handle_request(): never
{
    $method = request_method();
    $path = request_path();

    // 1. Static file check
    if (serve_static_asset($path)) {
        exit;
    }

    try {
        $db = database_connection();

        // 2. Health check
        if ($method === 'GET' && $path === '/health') {
            $db->query('SELECT 1');
            json_response(['status' => 'ok', 'runtime' => 'php', 'database' => 'ok']);
        }

        // 3. Tax calculation endpoint
        if ($method === 'POST' && $path === '/api/tax/calculate') {
            $payload = request_json();
            $items = $payload['items'] ?? [];
            if (!is_array($items)) {
                json_response(['detail' => 'items must be an array.'], 422);
            }

            json_response(calculate_invoice_tax(
                $items,
                (float) ($payload['discount_percent'] ?? 0),
                isset($payload['tax_rate']) ? (float) $payload['tax_rate'] : null,
                (bool) ($payload['apply_wht'] ?? false),
                (bool) ($payload['apply_fed'] ?? false)
            ));
        }

        // 4. File import endpoints (purchases, estimates, invoices, prices)
        if ($method === 'POST' && in_array($path, ['/api/purchases/import', '/api/estimates/import', '/api/invoices/import'], true)) {
            $user = require_authenticated_user($db);
            $rawContent = '';
            $filename = 'uploaded_file';

            if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                $rawContent = file_get_contents($_FILES['file']['tmp_name']) ?: '';
                $filename = (string) ($_FILES['file']['name'] ?? 'invoice');
            } else {
                $body = request_json();
                $b64 = $body['file_content'] ?? $body['content'] ?? '';
                if ($b64 !== '') {
                    $rawContent = base64_decode((string) $b64, true) ?: '';
                    $filename = (string) ($body['filename'] ?? 'invoice');
                }
            }

            if ($rawContent === '') {
                json_response(['detail' => 'No valid file content provided.'], 422);
            }

            $parsed = parse_invoice_file($filename, $rawContent, $db);
            json_response($parsed);
        }

        // 5. Dispatch to modular API route handlers
        handle_auth_routes($method, $path, $db);
        handle_dashboard_routes($method, $path, $db);
        handle_clients_routes($method, $path, $db);
        handle_suppliers_routes($method, $path, $db);
        handle_products_routes($method, $path, $db);
        handle_inventory_routes($method, $path, $db);
        handle_purchases_routes($method, $path, $db);
        handle_expenses_routes($method, $path, $db);
        handle_projects_routes($method, $path, $db);
        handle_estimates_routes($method, $path, $db);
        handle_invoices_routes($method, $path, $db);
        handle_prices_routes($method, $path, $db);
        handle_reports_routes($method, $path, $db);
        handle_agent_routes($method, $path, $db);
        handle_companies_routes($method, $path, $db);
        handle_whatsapp_routes($method, $path, $db);

        // 6. Web UI views (renders full Tailwind CSS frontend)
        if ($method === 'GET') {
            $uiRoutes = [
                '/' => 'login.html',
                '/login' => 'login.html',
                '/dashboard' => 'dashboard.html',
                '/clients' => 'clients/list.html',
                '/clients/new' => 'clients/form.html',
                '/products' => 'inventory/products.html',
                '/products/new' => 'inventory/product_form.html',
                '/inventory' => 'inventory/list.html',
                '/inventory/movements' => 'inventory/movements.html',
                '/purchases' => 'purchases/list.html',
                '/purchases/new' => 'purchases/form.html',
                '/expenses' => 'expenses/list.html',
                '/expenses/new' => 'expenses/form.html',
                '/estimates' => 'estimates/list.html',
                '/estimates/new' => 'estimates/form.html',
                '/invoices' => 'invoices/list.html',
                '/invoices/new' => 'invoices/form.html',
                '/agent' => 'agent/chat.html',
                '/projects' => 'projects.html',
                '/reports' => 'reports.html',
                '/prices' => 'prices.html',
                '/users' => 'users/list.html',
                '/companies' => 'companies.html',
                '/settings' => 'companies.html',
                '/settings/companies' => 'companies.html',
            ];

            if (isset($uiRoutes[$path])) {
                render_template($uiRoutes[$path]);
            }

            if (preg_match('#^/purchases/([1-9][0-9]*)$#', $path, $m)) {
                render_template('purchases/detail.html', ['purchase_id' => (int) $m[1]]);
            }
            if (preg_match('#^/purchases/([1-9][0-9]*)/edit$#', $path, $m)) {
                render_template('purchases/form.html', ['purchase_id' => (int) $m[1]]);
            }

            if (preg_match('#^/expenses/([1-9][0-9]*)$#', $path, $m)) {
                render_template('expenses/form.html', ['expense_id' => (int) $m[1]]);
            }

            if (preg_match('#^/estimates/([1-9][0-9]*)$#', $path, $m)) {
                render_template('estimates/detail.html', ['estimate_id' => (int) $m[1]]);
            }
            if (preg_match('#^/estimates/([1-9][0-9]*)/edit$#', $path, $m)) {
                render_template('estimates/form.html', ['estimate_id' => (int) $m[1]]);
            }

            if (preg_match('#^/invoices/([1-9][0-9]*)$#', $path, $m)) {
                render_template('invoices/detail.html', ['invoice_id' => (int) $m[1]]);
            }
            if (preg_match('#^/invoices/([1-9][0-9]*)/edit$#', $path, $m)) {
                render_template('invoices/form.html', ['invoice_id' => (int) $m[1]]);
            }
        }

        // 7. 404 Fallback
        if (str_starts_with($path, '/api/')) {
            json_response(['detail' => 'Not found.'], 404);
        } else {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>404 Not Found · DATAPOINT</title><style>body{font-family:Inter,system-ui,sans-serif;margin:60px auto;max-width:500px;text-align:center;color:#0f172a;background:#f8fafc}.btn{display:inline-block;background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;margin-top:16px}</style></head><body><h1>404 Not Found</h1><p>The requested page was not found on this server.</p><a class="btn" href="/dashboard">Return to Dashboard</a></body></html>';
            exit;
        }
    } catch (InvalidArgumentException $e) {
        json_response(['detail' => $e->getMessage()], 422);
    } catch (PDOException $e) {
        error_log('Database error: ' . $e->getMessage());
        json_response(['detail' => 'Database error: ' . $e->getMessage()], 503);
    } catch (Throwable $e) {
        error_log('Server error: ' . $e->getMessage());
        json_response(['detail' => 'Internal server error.'], 500);
    }
}

handle_request();
