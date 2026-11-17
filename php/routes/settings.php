<?php

declare(strict_types=1);

/**
 * White Label & System Administration Routes
 * Manages company branding, gateways, database backups, and system tools.
 */

function handle_settings_routes(string $method, string $path, PDO $db): bool
{
    // 1. GET /api/settings - Fetch all white label settings & system stats
    if ($method === 'GET' && $path === '/api/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'view');

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Fetch settings from app_settings table
        ensure_app_settings_schema($db);
        $stmt = $db->query('SELECT setting_key, setting_value FROM app_settings');
        $rawSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fallbacks to .env
        $settings = [
            'app_name'           => $rawSettings['app_name'] ?? env_value('COMPANY_NAME', 'Enterprise Invoicing'),
            'brand_name'         => $rawSettings['brand_name'] ?? env_value('BRAND_NAME', 'INVOICING'),
            'app_tagline'        => $rawSettings['app_tagline'] ?? env_value('COMPANY_TAGLINE', 'Invoicing & Quotation Suite'),
            'app_logo'           => $rawSettings['app_logo'] ?? env_value('COMPANY_LOGO', '/static/img/logo.png'),
            'app_favicon'        => $rawSettings['app_favicon'] ?? env_value('COMPANY_FAVICON', '/static/img/favicon.svg'),
            'default_currency'   => $rawSettings['default_currency'] ?? env_value('DEFAULT_CURRENCY', 'PKR'),
            'currency_symbol'    => $rawSettings['currency_symbol'] ?? env_value('CURRENCY_SYMBOL', 'PKR '),
            'default_tax_rate'   => (float) ($rawSettings['default_tax_rate'] ?? env_value('DEFAULT_TAX_RATE', '18.0')),
            'tax_label'          => $rawSettings['tax_label'] ?? env_value('TAX_LABEL', 'Sales Tax (GST)'),
            
            // Company contact
            'company_name'       => $rawSettings['company_name'] ?? env_value('COMPANY_NAME', 'Enterprise Invoicing'),
            'company_address'    => $rawSettings['company_address'] ?? env_value('COMPANY_ADDRESS', ''),
            'company_city'       => $rawSettings['company_city'] ?? env_value('COMPANY_CITY', ''),
            'company_phone'      => $rawSettings['company_phone'] ?? env_value('COMPANY_PHONE', ''),
            'company_mobile'     => $rawSettings['company_mobile'] ?? env_value('COMPANY_MOBILE', ''),
            'company_whatsapp'   => $rawSettings['company_whatsapp'] ?? env_value('COMPANY_WHATSAPP', ''),
            'company_email'      => $rawSettings['company_email'] ?? env_value('COMPANY_EMAIL', 'billing@example.com'),
            'company_website'    => $rawSettings['company_website'] ?? env_value('COMPANY_WEBSITE', 'https://example.com'),
            'company_ntn'        => $rawSettings['company_ntn'] ?? env_value('COMPANY_NTN', ''),
            'company_strn'       => $rawSettings['company_strn'] ?? env_value('COMPANY_STRN', ''),
            'payment_terms'      => $rawSettings['payment_terms'] ?? "1. Payment: Net 30 days\n2. Prices include standard sales tax\n3. Validity: 15 days",

            // Integrations
            'whatsapp_enabled'   => ($rawSettings['whatsapp_enabled'] ?? env_value('WHATSAPP_ENABLED', 'true')) === 'true' || ($rawSettings['whatsapp_enabled'] ?? '') === '1',
            'whatsapp_provider'  => $rawSettings['whatsapp_provider'] ?? env_value('WHATSAPP_PROVIDER', 'local'),
            'whatsapp_phone_number_id' => $rawSettings['whatsapp_phone_number_id'] ?? env_value('WHATSAPP_PHONE_NUMBER_ID', ''),
            'whatsapp_api_key'   => !empty($rawSettings['whatsapp_api_key']) ? '••••••••' : (env_value('WHATSAPP_API_KEY') ? '••••••••' : ''),

            'smtp_host'          => $rawSettings['smtp_host'] ?? env_value('EMAIL_HOST', 'mail.example.com'),
            'smtp_port'          => (int) ($rawSettings['smtp_port'] ?? env_value('EMAIL_PORT', '587')),
            'smtp_user'          => $rawSettings['smtp_user'] ?? env_value('EMAIL_USER', 'info@example.com'),
            'smtp_from'          => $rawSettings['smtp_from'] ?? env_value('EMAIL_FROM', 'info@example.com'),

            'google_sheet_url'   => $rawSettings['google_sheet_url'] ?? env_value('LIVE_RATE_SHEET_URL', ''),
            'sheet_markup'       => (float) ($rawSettings['sheet_markup'] ?? '10.0'),

            // FBR Pakistan
            'fbr_enabled'        => ($rawSettings['fbr_enabled'] ?? get_app_setting($db, 'fbr_enabled', '0')) === '1',
            'fbr_environment'    => $rawSettings['fbr_environment'] ?? get_app_setting($db, 'fbr_environment', 'sandbox'),
            'fbr_mode'           => $rawSettings['fbr_mode'] ?? get_app_setting($db, 'fbr_mode', 'simulation'),
            'fbr_pos_id'         => $rawSettings['fbr_pos_id'] ?? get_app_setting($db, 'fbr_pos_id', ''),
            'fbr_bearer_token'   => !empty($rawSettings['fbr_bearer_token']) ? '••••••••' : (!empty(get_app_setting($db, 'fbr_bearer_token')) ? '••••••••' : ''),
        ];

        // Gather database stats
        $dbName = 'SQLite (storage/dptech.db)';
        if ($driver === 'mysql') {
            try {
                $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn();
            } catch (\Throwable $e) {
                $dbName = 'MySQL Database';
            }
        } elseif ($driver === 'pgsql') {
            try {
                $dbName = (string) $db->query('SELECT current_database()')->fetchColumn();
            } catch (\Throwable $e) {
                $dbName = 'PostgreSQL';
            }
        }

        $counts = [
            'invoices'   => db_table_exists($db, 'invoices') ? (int) $db->query('SELECT COUNT(*) FROM invoices')->fetchColumn() : 0,
            'estimates'  => db_table_exists($db, 'estimates') ? (int) $db->query('SELECT COUNT(*) FROM estimates')->fetchColumn() : 0,
            'clients'    => db_table_exists($db, 'clients') ? (int) $db->query('SELECT COUNT(*) FROM clients')->fetchColumn() : 0,
            'products'   => db_table_exists($db, 'products') ? (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn() : 0,
            'users'      => db_table_exists($db, 'users') ? (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() : 0,
            'companies'  => db_table_exists($db, 'companies') ? (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn() : 0,
        ];

        $system = [
            'php_version'       => PHP_VERSION,
            'os'                => PHP_OS,
            'server_software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP CLI / Built-in',
            'driver'            => strtoupper($driver),
            'database_name'     => $dbName,
            'memory_limit'      => ini_get('memory_limit'),
            'max_upload_size'   => ini_get('upload_max_filesize'),
            'post_max_size'     => ini_get('post_max_size'),
            'storage_writable'  => is_writable(PHP_APP_ROOT . '/storage'),
            'uploads_writable'  => is_writable(PHP_APP_ROOT . '/public/static/uploads') || is_writable(dirname(PHP_APP_ROOT) . '/public/static/uploads'),
        ];

        json_response([
            'settings' => $settings,
            'counts'   => $counts,
            'system'   => $system,
        ]);
    }

    // 2. POST /api/settings - Save white label and system configuration
    if ($method === 'POST' && $path === '/api/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');

        $body = request_json();
        ensure_app_settings_schema($db);

        $allowedKeys = [
            'app_name', 'brand_name', 'app_tagline', 'app_logo', 'app_favicon',
            'default_currency', 'currency_symbol', 'default_tax_rate', 'tax_label',
            'company_name', 'company_address', 'company_city', 'company_phone',
            'company_mobile', 'company_whatsapp', 'company_email', 'company_website',
            'company_ntn', 'company_strn', 'payment_terms',
            'whatsapp_enabled', 'whatsapp_provider', 'whatsapp_phone_number_id',
            'smtp_host', 'smtp_port', 'smtp_user', 'smtp_from',
            'google_sheet_url', 'sheet_markup',
            'fbr_enabled', 'fbr_environment', 'fbr_mode', 'fbr_pos_id',
        ];

        // Passwords & tokens only updated if not masked
        if (!empty($body['fbr_bearer_token']) && !str_contains($body['fbr_bearer_token'], '••••')) {
            set_app_setting($db, 'fbr_bearer_token', trim((string) $body['fbr_bearer_token']));
        }
        if (!empty($body['whatsapp_api_key']) && !str_contains($body['whatsapp_api_key'], '••••')) {
            set_app_setting($db, 'whatsapp_api_key', trim((string) $body['whatsapp_api_key']));
        }
        if (!empty($body['smtp_pass']) && !str_contains($body['smtp_pass'], '••••')) {
            set_app_setting($db, 'smtp_pass', trim((string) $body['smtp_pass']));
        }

        foreach ($allowedKeys as $k) {
            if (isset($body[$k])) {
                $val = is_bool($body[$k]) ? ($body[$k] ? '1' : '0') : trim((string) $body[$k]);
                set_app_setting($db, $k, $val);
            }
        }

        // Synchronize primary company record in companies table (id = 1 or is_default = 1)
        if (db_table_exists($db, 'companies')) {
            try {
                $coName = !empty($body['company_name']) ? trim((string)$body['company_name']) : (!empty($body['app_name']) ? trim((string)$body['app_name']) : '');
                if ($coName !== '') {
                    $upd = $db->prepare('
                        UPDATE companies SET
                            name = COALESCE(:name, name),
                            email = COALESCE(:email, email),
                            phone = COALESCE(:phone, phone),
                            mobile = COALESCE(:wa, mobile),
                            whatsapp = COALESCE(:wa, whatsapp),
                            website = COALESCE(:ws, website),
                            address = COALESCE(:addr, address),
                            city = COALESCE(:city, city),
                            ntn = COALESCE(:ntn, ntn),
                            strn = COALESCE(:strn, strn),
                            terms = COALESCE(:terms, terms),
                            logo_url = COALESCE(:logo, logo_url)
                        WHERE is_default = 1 OR id = 1
                    ');
                    $upd->execute([
                        'name'  => $coName,
                        'email' => !empty($body['company_email']) ? trim((string)$body['company_email']) : null,
                        'phone' => !empty($body['company_phone']) ? trim((string)$body['company_phone']) : null,
                        'wa'    => !empty($body['company_whatsapp']) ? trim((string)$body['company_whatsapp']) : null,
                        'ws'    => !empty($body['company_website']) ? trim((string)$body['company_website']) : null,
                        'addr'  => !empty($body['company_address']) ? trim((string)$body['company_address']) : null,
                        'city'  => !empty($body['company_city']) ? trim((string)$body['company_city']) : null,
                        'ntn'   => !empty($body['company_ntn']) ? trim((string)$body['company_ntn']) : null,
                        'strn'  => !empty($body['company_strn']) ? trim((string)$body['company_strn']) : null,
                        'terms' => !empty($body['payment_terms']) ? trim((string)$body['payment_terms']) : null,
                        'logo'  => !empty($body['app_logo']) ? trim((string)$body['app_logo']) : null,
                    ]);
                }
            } catch (\Throwable $e) {
                error_log("Failed to sync primary company: " . $e->getMessage());
            }
        }

        json_response(['success' => true, 'message' => 'Settings and branding updated successfully.']);
    }

    // 3. POST /api/settings/upload-asset - Upload Logo or Favicon
    if ($method === 'POST' && $path === '/api/settings/upload-asset') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'settings', 'edit');

        $type = $_GET['type'] ?? 'logo'; // 'logo' or 'favicon'
        $bytes = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $bytes = (string) file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $body = request_json();
            $base64 = (string) ($body['image_base64'] ?? '');
            if ($base64 !== '') {
                if (str_starts_with($base64, 'data:') && str_contains($base64, ',')) {
                    $base64 = substr($base64, strpos($base64, ',') + 1);
                }
                $bytes = (string) base64_decode($base64, true);
            }
        }

        if ($bytes === '') {
            json_response(['detail' => 'No image file uploaded.'], 422);
        }

        $url = save_logo_image($bytes);
        if ($type === 'favicon') {
            set_app_setting($db, 'app_favicon', $url);
        } else {
            set_app_setting($db, 'app_logo', $url);
        }

        json_response(['url' => $url, 'message' => ucfirst($type) . ' uploaded successfully.']);
    }

    // 4. GET /api/settings/database-backup - Download complete database SQL dump
    if ($method === 'GET' && $path === '/api/settings/database-backup') {
        $user = require_authenticated_user($db);
        if (strtolower((string)($user->role ?? '')) !== 'admin') {
            json_response(['detail' => 'Only administrators can download database backups.'], 403);
        }

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $appName = get_app_setting($db, 'app_name', 'Invoicing');
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($appName));
        $filename = "{$cleanName}_backup_" . date('Y-m-d_His') . ".sql";

        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "-- ========================================================\n";
        echo "-- Database Backup: {$appName}\n";
        echo "-- Exported at: " . date('Y-m-d H:i:s') . "\n";
        echo "-- Driver: " . strtoupper($driver) . "\n";
        echo "-- ========================================================\n\n";

        if ($driver === 'mysql') {
            echo "SET FOREIGN_KEY_CHECKS=0;\n";
            echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            echo "START TRANSACTION;\n";
            echo "SET time_zone = \"+00:00\";\n\n";

            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $tbl) {
                echo "--\n-- Table structure for table `{$tbl}`\n--\n";
                echo "DROP TABLE IF EXISTS `{$tbl}`;\n";
                $createSql = $db->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_ASSOC);
                echo $createSql['Create Table'] . ";\n\n";

                $rows = $db->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    echo "--\n-- Dumping data for table `{$tbl}`\n--\n";
                    $cols = array_keys($rows[0]);
                    $colList = implode('`, `', $cols);
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $db->quote((string)$val);
                            }
                        }
                        echo "INSERT INTO `{$tbl}` (`{$colList}`) VALUES (" . implode(', ', $values) . ");\n";
                    }
                    echo "\n";
                }
            }
            echo "SET FOREIGN_KEY_CHECKS=1;\n";
            echo "COMMIT;\n";
        } else {
            // SQLite
            echo "PRAGMA foreign_keys=OFF;\n";
            echo "BEGIN TRANSACTION;\n\n";

            $tables = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tables as $t) {
                $tbl = $t['name'];
                $sql = $t['sql'];
                echo "-- Table: {$tbl}\n";
                echo "DROP TABLE IF EXISTS \"{$tbl}\";\n";
                echo "{$sql};\n";

                $rows = $db->query("SELECT * FROM \"{$tbl}\"")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $cols = array_keys($rows[0]);
                    $colList = implode('", "', $cols);
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $values[] = 'NULL';
                            } else {
                                $values[] = $db->quote((string)$val);
                            }
                        }
                        echo "INSERT INTO \"{$tbl}\" (\"{$colList}\") VALUES (" . implode(', ', $values) . ");\n";
                    }
                    echo "\n";
                }
            }
            echo "COMMIT;\n";
        }
        exit;
    }

    // 5. POST /api/settings/reset-demo-data - Clear transactions to zero (Keeps users & settings)
    if ($method === 'POST' && $path === '/api/settings/reset-demo-data') {
        $user = require_authenticated_user($db);
        if (strtolower((string)($user->role ?? '')) !== 'admin') {
            json_response(['detail' => 'Only administrators can reset data.'], 403);
        }

        $body = request_json();
        $password = (string) ($body['password'] ?? '');
        
        // Verify admin password before destructive action
        $stmt = $db->prepare('SELECT hashed_password FROM users WHERE id = :id');
        $stmt->execute(['id' => $user->id]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($password, $hash)) {
            json_response(['detail' => 'Incorrect administrator password. Action aborted.'], 403);
        }

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $tablesToClear = [
            'invoice_items', 'payments', 'invoices',
            'estimate_items', 'estimates',
            'agent_messages', 'agent_conversations',
            'purchase_invoices', 'expenses', 'stock_movements',
            'clients', 'projects'
        ];

        try {
            if ($driver === 'mysql') {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tablesToClear as $tbl) {
                    if (db_table_exists($db, $tbl)) {
                        $db->exec("TRUNCATE TABLE `{$tbl}`");
                    }
                }
                $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                $db->exec('PRAGMA foreign_keys = OFF');
                foreach ($tablesToClear as $tbl) {
                    if (db_table_exists($db, $tbl)) {
                        $db->exec("DELETE FROM `{$tbl}`");
                        try {
                            $db->exec("DELETE FROM sqlite_sequence WHERE name = '{$tbl}'");
                        } catch (\Throwable $e) {}
                    }
                }
                $db->exec('PRAGMA foreign_keys = ON');
            }

            json_response(['success' => true, 'message' => 'All demonstration transaction data has been successfully reset to zero.']);
        } catch (\Throwable $e) {
            json_response(['detail' => 'Failed to reset data: ' . $e->getMessage()], 500);
        }
    }

    return false;
}
