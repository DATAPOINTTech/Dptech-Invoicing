<?php

declare(strict_types=1);

const PHP_APP_ROOT = __DIR__;

require_once PHP_APP_ROOT . '/services/security.php';
require_once PHP_APP_ROOT . '/services/auth.php';
require_once PHP_APP_ROOT . '/services/communication.php';
require_once PHP_APP_ROOT . '/services/inventory_service.php';
require_once PHP_APP_ROOT . '/services/taxation.php';
require_once PHP_APP_ROOT . '/services/pdf_service.php';
require_once PHP_APP_ROOT . '/services/invoice_import.php';
require_once PHP_APP_ROOT . '/services/scraper.php';
require_once PHP_APP_ROOT . '/services/support_agent.php';
require_once PHP_APP_ROOT . '/services/google_sheet_sync.php';

function load_dotenv(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

load_dotenv(PHP_APP_ROOT . DIRECTORY_SEPARATOR . '.env');
load_dotenv(dirname(PHP_APP_ROOT) . DIRECTORY_SEPARATOR . '.env');

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === null) {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? $default;
    }
    return $value;
}

function default_php_database_path(): string
{
    $storageDirectory = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($storageDirectory)) {
        mkdir($storageDirectory, 0777, true);
    }

    return $storageDirectory . DIRECTORY_SEPARATOR . 'dptech.db';
}

function database_connection(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $defaultDatabase = default_php_database_path();
    $url = env_value('DATABASE_URL', 'sqlite:///' . $defaultDatabase);
    if ($url === null || $url === '') {
        $url = 'sqlite:///' . $defaultDatabase;
    }

    if (str_starts_with($url, 'sqlite:///')) {
        $databasePath = substr($url, strlen('sqlite:///'));
        if (!str_starts_with($databasePath, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $databasePath)) {
            $phpDatabasePath = PHP_APP_ROOT . DIRECTORY_SEPARATOR . $databasePath;
            $repositoryDatabasePath = dirname(PHP_APP_ROOT) . DIRECTORY_SEPARATOR . $databasePath;
            $databasePath = is_file($repositoryDatabasePath) ? $repositoryDatabasePath : $phpDatabasePath;
        }
        $dsn = 'sqlite:' . $databasePath;
        $connection = new PDO($dsn);
    } elseif (str_starts_with($url, 'sqlite://')) {
        $dsn = 'sqlite:' . substr($url, strlen('sqlite://'));
        $connection = new PDO($dsn);
    } elseif (str_starts_with($url, 'postgres://') || str_starts_with($url, 'postgresql://')) {
        $prefix = str_starts_with($url, 'postgres://') ? 'postgres://' : 'postgresql://';
        $parsed = parse_url('http://' . substr($url, strlen($prefix)));
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 5432;
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';
        $dbname = ltrim($parsed['path'] ?? '', '/');
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $connection = new PDO($dsn, $user, $pass);
    } elseif (str_starts_with($url, 'mysql://')) {
        $parsed = parse_url('http://' . substr($url, strlen('mysql://')));
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 3306;
        $user = $parsed['user'] ?? '';
        $pass = $parsed['pass'] ?? '';
        $dbname = ltrim($parsed['path'] ?? '', '/');
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        $connection = new PDO($dsn, $user, $pass);
    } else {
        throw new RuntimeException('Unsupported DATABASE_URL scheme for the PHP environment.');
    }

    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    ensure_all_schema_tables($connection);
    ensure_default_admin($connection);
    ensure_multicompany_schema($connection);
    ensure_fbr_schema($connection);
    check_emergency_password_reset($connection);

    return $connection;
}

function db_table_exists(PDO $db, string $table): bool
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type IN ('table','view') AND name = :name");
        $stmt->execute(['name' => $table]);
        return (bool) $stmt->fetchColumn();
    }
    if ($driver === 'mysql') {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :name AND table_schema = DATABASE()");
        $stmt->execute(['name' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :name");
    $stmt->execute(['name' => $table]);
    return (bool) $stmt->fetchColumn();
}

function ensure_all_schema_tables(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver !== 'sqlite') {
        // In PostgreSQL/MySQL, tables are either managed by migrations or created via standard schema
        return;
    }

    $schemas = [
        'users' => <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	username VARCHAR(50) NOT NULL UNIQUE,
	email VARCHAR(100) NOT NULL UNIQUE,
	hashed_password VARCHAR(200) NOT NULL,
	full_name VARCHAR(100) NOT NULL,
	phone VARCHAR(20),
	role VARCHAR(20) DEFAULT 'staff',
	is_active BOOLEAN DEFAULT 1,
	permissions JSON,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'clients' => <<<'SQL'
CREATE TABLE IF NOT EXISTS clients (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(200) NOT NULL,
	company VARCHAR(200),
	email VARCHAR(100),
	phone VARCHAR(20),
	mobile VARCHAR(20),
	address TEXT,
	city VARCHAR(50),
	province VARCHAR(50),
	ntn VARCHAR(50),
	strn VARCHAR(50),
	notes TEXT,
	is_active BOOLEAN DEFAULT 1,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'suppliers' => <<<'SQL'
CREATE TABLE IF NOT EXISTS suppliers (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(200) NOT NULL,
	contact_person VARCHAR(200),
	email VARCHAR(100),
	phone VARCHAR(20),
	mobile VARCHAR(20),
	address TEXT,
	city VARCHAR(50),
	ntn VARCHAR(50),
	strn VARCHAR(50),
	is_active BOOLEAN DEFAULT 1,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'products' => <<<'SQL'
CREATE TABLE IF NOT EXISTS products (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(200) NOT NULL,
	description TEXT,
	category VARCHAR(50),
	sku VARCHAR(50),
	unit_price FLOAT DEFAULT 0,
	cost_price FLOAT DEFAULT 0,
	unit VARCHAR(20) DEFAULT 'pcs',
	tax_rate FLOAT DEFAULT 18.0,
	tax_inclusive BOOLEAN DEFAULT 0,
	hs_code VARCHAR(20),
	is_active BOOLEAN DEFAULT 1,
	min_stock_level FLOAT DEFAULT 0,
	max_stock_level FLOAT DEFAULT 0,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'price_list' => <<<'SQL'
CREATE TABLE IF NOT EXISTS price_list (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(300) NOT NULL,
	description TEXT,
	category VARCHAR(100),
	unit VARCHAR(20) DEFAULT 'pcs',
	unit_price FLOAT NOT NULL,
	currency VARCHAR(10) DEFAULT 'PKR',
	effective_date DATE NOT NULL,
	source VARCHAR(200),
	image_url VARCHAR(500),
	is_active BOOLEAN DEFAULT 1,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'projects' => <<<'SQL'
CREATE TABLE IF NOT EXISTS projects (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	name VARCHAR(200) NOT NULL,
	description TEXT,
	client_id INTEGER,
	start_date DATE,
	end_date DATE,
	status VARCHAR(50) DEFAULT 'planning',
	budget FLOAT DEFAULT 0,
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'inventory' => <<<'SQL'
CREATE TABLE IF NOT EXISTS inventory (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	product_id INTEGER NOT NULL UNIQUE,
	quantity FLOAT DEFAULT 0,
	warehouse VARCHAR(100) DEFAULT 'Main',
	location VARCHAR(100),
	last_updated DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'stock_movements' => <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_movements (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	product_id INTEGER NOT NULL,
	quantity FLOAT NOT NULL,
	movement_type VARCHAR(50) NOT NULL,
	reference_type VARCHAR(50),
	reference_id INTEGER,
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'purchase_invoices' => <<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_invoices (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	invoice_no VARCHAR(50) NOT NULL UNIQUE,
	supplier_id INTEGER,
	supplier_name VARCHAR(200) NOT NULL,
	supplier_ntn VARCHAR(50),
	supplier_address TEXT,
	invoice_date DATE NOT NULL,
	received_date DATE,
	status VARCHAR(50) DEFAULT 'draft',
	subtotal FLOAT DEFAULT 0,
	tax_amount FLOAT DEFAULT 0,
	tax_rate FLOAT DEFAULT 18.0,
	total_amount FLOAT DEFAULT 0,
	amount_paid FLOAT DEFAULT 0,
	balance_due FLOAT DEFAULT 0,
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'purchase_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_items (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	purchase_id INTEGER NOT NULL,
	product_id INTEGER,
	product_name VARCHAR(200) NOT NULL,
	description TEXT,
	quantity FLOAT NOT NULL,
	unit VARCHAR(20) DEFAULT 'pcs',
	unit_price FLOAT NOT NULL,
	tax_rate FLOAT DEFAULT 18.0,
	tax_amount FLOAT DEFAULT 0,
	total_price FLOAT NOT NULL
);
SQL,
        'purchase_payments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_payments (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	purchase_id INTEGER NOT NULL,
	amount FLOAT NOT NULL,
	payment_date DATE NOT NULL,
	payment_method VARCHAR(50),
	reference_no VARCHAR(100),
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'expenses' => <<<'SQL'
CREATE TABLE IF NOT EXISTS expenses (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	expense_no VARCHAR(50),
	category VARCHAR(50) DEFAULT 'other',
	description TEXT NOT NULL,
	amount FLOAT NOT NULL,
	tax_amount FLOAT DEFAULT 0,
	total_amount FLOAT NOT NULL,
	expense_date DATE NOT NULL,
	payment_method VARCHAR(50),
	vendor_name VARCHAR(200),
	receipt_ref VARCHAR(100),
	project_id INTEGER,
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'estimates' => <<<'SQL'
CREATE TABLE IF NOT EXISTS estimates (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	estimate_no VARCHAR(50) NOT NULL UNIQUE,
	client_id INTEGER NOT NULL,
	project_id INTEGER,
	title VARCHAR(200),
	estimate_date DATE NOT NULL,
	valid_until DATE,
	status VARCHAR(50) DEFAULT 'draft',
	subtotal FLOAT DEFAULT 0,
	discount_percent FLOAT DEFAULT 0,
	discount_amount FLOAT DEFAULT 0,
	tax_rate FLOAT DEFAULT 18.0,
	tax_amount FLOAT DEFAULT 0,
	total_amount FLOAT DEFAULT 0,
	terms_conditions TEXT,
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'estimate_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS estimate_items (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	estimate_id INTEGER NOT NULL,
	product_id INTEGER,
	description VARCHAR(500) NOT NULL,
	model_make VARCHAR(300),
	quantity FLOAT NOT NULL,
	unit VARCHAR(20) DEFAULT 'pcs',
	unit_price FLOAT NOT NULL,
	tax_rate FLOAT DEFAULT 18.0,
	tax_amount FLOAT DEFAULT 0,
	total_price FLOAT NOT NULL
);
SQL,
        'invoices' => <<<'SQL'
CREATE TABLE IF NOT EXISTS invoices (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	invoice_no VARCHAR(50) NOT NULL UNIQUE,
	client_id INTEGER NOT NULL,
	estimate_id INTEGER,
	project_id INTEGER,
	invoice_date DATE NOT NULL,
	due_date DATE,
	status VARCHAR(50) DEFAULT 'draft',
	subtotal FLOAT DEFAULT 0,
	discount_percent FLOAT DEFAULT 0,
	discount_amount FLOAT DEFAULT 0,
	tax_rate FLOAT DEFAULT 18.0,
	tax_amount FLOAT DEFAULT 0,
	withholding_tax_rate FLOAT DEFAULT 0,
	withholding_tax_amount FLOAT DEFAULT 0,
	fed_rate FLOAT DEFAULT 0,
	fed_amount FLOAT DEFAULT 0,
	total_amount FLOAT DEFAULT 0,
	amount_paid FLOAT DEFAULT 0,
	balance_due FLOAT DEFAULT 0,
	payment_terms VARCHAR(200),
	notes TEXT,
	terms_conditions TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME
);
SQL,
        'invoice_items' => <<<'SQL'
CREATE TABLE IF NOT EXISTS invoice_items (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	invoice_id INTEGER NOT NULL,
	product_id INTEGER,
	description VARCHAR(500) NOT NULL,
	model_make VARCHAR(300),
	quantity FLOAT NOT NULL,
	unit VARCHAR(20) DEFAULT 'pcs',
	unit_price FLOAT NOT NULL,
	tax_rate FLOAT DEFAULT 18.0,
	tax_amount FLOAT DEFAULT 0,
	total_price FLOAT NOT NULL
);
SQL,
        'payments' => <<<'SQL'
CREATE TABLE IF NOT EXISTS payments (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	invoice_id INTEGER NOT NULL,
	amount FLOAT NOT NULL,
	payment_date DATE NOT NULL,
	payment_method VARCHAR(50) DEFAULT 'Cash',
	reference_no VARCHAR(100),
	notes TEXT,
	created_by INTEGER,
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'agent_conversations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS agent_conversations (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	channel VARCHAR(20) DEFAULT 'web',
	sender_phone VARCHAR(50),
	sender_name VARCHAR(100),
	client_id INTEGER,
	last_message TEXT,
	unread_count INTEGER DEFAULT 0,
	status VARCHAR(20) DEFAULT 'active',
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
	updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'agent_messages' => <<<'SQL'
CREATE TABLE IF NOT EXISTS agent_messages (
	id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	conversation_id INTEGER NOT NULL,
	sender_type VARCHAR(20) NOT NULL,
	sender_name VARCHAR(100),
	message TEXT NOT NULL,
	message_type VARCHAR(20) DEFAULT 'text',
	document_url VARCHAR(500),
	document_name VARCHAR(200),
	is_whatsapp BOOLEAN DEFAULT 0,
	whatsapp_msg_id VARCHAR(100),
	created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
        'app_settings' => <<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
	setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
	setting_value TEXT,
	updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL,
    ];

    foreach ($schemas as $table => $sql) {
        if (!db_table_exists($db, $table)) {
            $db->exec($sql);
        }
    }
}

function ensure_app_settings_schema(PDO $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL);
}

function get_app_setting(PDO $db, string $key, ?string $default = null): ?string
{
    ensure_app_settings_schema($db);
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string) $val : $default;
}

function set_app_setting(PDO $db, string $key, string $value): void
{
    ensure_app_settings_schema($db);
    try {
        $stmt = $db->prepare('
            INSERT INTO app_settings (setting_key, setting_value, updated_at)
            VALUES (:k, :v, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = :v2, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
    } catch (Throwable $e) {
        $del = $db->prepare('DELETE FROM app_settings WHERE setting_key = :k');
        $del->execute(['k' => $key]);
        $ins = $db->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP)');
        $ins->execute(['k' => $key, 'v' => $value]);
    }
}

function ensure_multicompany_schema(PDO $db): void
{
    // 1. Ensure companies table
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS companies (
    id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL,
    code VARCHAR(50),
    logo_url TEXT,
    email VARCHAR(100),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    address TEXT,
    city VARCHAR(50),
    ntn VARCHAR(50),
    strn VARCHAR(50),
    terms TEXT,
    is_default BOOLEAN DEFAULT 0,
    markup_percent FLOAT DEFAULT 0.0,
    sort_order INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL);

    // 2. Add multi-company columns to estimates and invoices if missing
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $neededCols = [
            'company_id' => 'INTEGER',
            'company_name' => 'VARCHAR(200)',
            'company_logo' => 'TEXT',
            'company_phone' => 'VARCHAR(50)',
            'company_email' => 'VARCHAR(100)',
            'company_address' => 'TEXT',
            'company_ntn' => 'VARCHAR(50)',
            'company_strn' => 'VARCHAR(50)',
            'batch_id' => 'VARCHAR(50)',
            'markup_percent' => 'FLOAT DEFAULT 0.0',
        ];

        foreach (['estimates', 'invoices'] as $tbl) {
            if (!db_table_exists($db, $tbl)) {
                continue;
            }
            $cols = $db->query("PRAGMA table_info({$tbl})")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($cols, 'name');

            foreach ($neededCols as $col => $type) {
                if ($tbl === 'invoices' && ($col === 'batch_id' || $col === 'markup_percent')) {
                    continue;
                }
                if (!in_array($col, $colNames, true)) {
                    try {
                        $db->exec("ALTER TABLE {$tbl} ADD COLUMN {$col} {$type}");
                    } catch (Throwable $e) {
                        // Column might already exist
                    }
                }
            }
        }

        // Add model_make to item tables
        foreach (['estimate_items', 'invoice_items'] as $itemTbl) {
            if (db_table_exists($db, $itemTbl)) {
                $cols = $db->query("PRAGMA table_info({$itemTbl})")->fetchAll(PDO::FETCH_ASSOC);
                $colNames = array_column($cols, 'name');
                if (!in_array('model_make', $colNames, true)) {
                    try {
                        $db->exec("ALTER TABLE {$itemTbl} ADD COLUMN model_make VARCHAR(300)");
                    } catch (Throwable $e) {}
                }
            }
        }

        // Add website & whatsapp to companies table
        if (db_table_exists($db, 'companies')) {
            $cols = $db->query("PRAGMA table_info(companies)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($cols, 'name');
            foreach (['website' => 'VARCHAR(100)', 'whatsapp' => 'VARCHAR(50)'] as $c => $t) {
                if (!in_array($c, $colNames, true)) {
                    try {
                        $db->exec("ALTER TABLE companies ADD COLUMN {$c} {$t}");
                    } catch (Throwable $e) {}
                }
            }
        }

        // Add title to invoices table
        if (db_table_exists($db, 'invoices')) {
            $cols = $db->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($cols, 'name');
            if (!in_array('title', $colNames, true)) {
                try {
                    $db->exec("ALTER TABLE invoices ADD COLUMN title VARCHAR(200)");
                } catch (Throwable $e) {}
            }
        }
    }

    // 3. Seed initial 3 companies if empty
    $count = (int) $db->query('SELECT COUNT(*) FROM companies')->fetchColumn();
    if ($count === 0) {
        $primaryCompany = env_value('COMPANY_NAME', 'DATAPOINT Technologies');
        $primaryAddress = env_value('COMPANY_ADDRESS', 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh');
        $primaryPhone = env_value('COMPANY_PHONE', '+92-316-7788990');
        $primaryEmail = env_value('COMPANY_EMAIL', 'info@datapointtechnology.com');

        $initialCompanies = [
            [
                'name' => $primaryCompany,
                'code' => 'CO1',
                'logo_url' => '/static/img/logo.png',
                'email' => $primaryEmail,
                'phone' => $primaryPhone,
                'mobile' => '+92-300-1122334',
                'address' => $primaryAddress,
                'city' => 'Hyderabad',
                'ntn' => '1234567-8',
                'strn' => '12-34-5678-901-23',
                'terms' => "1. Payment: Net 30 days\n2. Prices include standard GST\n3. Validity: 15 days",
                'is_default' => 1,
                'markup_percent' => 0.0,
                'sort_order' => 1,
            ],
            [
                'name' => 'TechPoint Solutions',
                'code' => 'CO2',
                'logo_url' => '/static/img/logo.png',
                'email' => 'sales@techpoint.pk',
                'phone' => '+92-300-8899112',
                'mobile' => '+92-315-9988776',
                'address' => 'Suite 402, Trade Center, Qasimabad, Hyderabad Sindh',
                'city' => 'Hyderabad',
                'ntn' => '8765432-1',
                'strn' => '98-76-5432-109-87',
                'terms' => "1. Payment: Net 30 days\n2. Prices include standard GST\n3. Validity: 15 days",
                'is_default' => 0,
                'markup_percent' => 2.0,
                'sort_order' => 2,
            ],
            [
                'name' => 'Apex Data Systems',
                'code' => 'CO3',
                'logo_url' => '/static/img/logo.png',
                'email' => 'info@apexdata.com.pk',
                'phone' => '+92-312-3344556',
                'mobile' => '+92-333-5566778',
                'address' => 'Plot 15, Industrial Estate, Autobahn Road, Hyderabad Sindh',
                'city' => 'Hyderabad',
                'ntn' => '5432167-9',
                'strn' => '54-32-1678-901-45',
                'terms' => "1. Payment: Net 30 days\n2. Prices include standard GST\n3. Validity: 15 days",
                'is_default' => 0,
                'markup_percent' => 3.0,
                'sort_order' => 3,
            ],
        ];

        $ins = $db->prepare('INSERT INTO companies (name, code, logo_url, email, phone, mobile, address, city, ntn, strn, terms, is_default, markup_percent, sort_order, created_at, updated_at) VALUES (:name, :code, :logo_url, :email, :phone, :mobile, :address, :city, :ntn, :strn, :terms, :is_default, :markup_percent, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        foreach ($initialCompanies as $co) {
            $ins->execute($co);
        }
    }
}

function ensure_fbr_schema(PDO $db): void
{
    ensure_app_settings_schema($db);

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    $getColumns = function(string $table) use ($db, $driver): array {
        if (!db_table_exists($db, $table)) return [];
        try {
            if ($driver === 'sqlite') {
                $cols = $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC);
                return array_column($cols, 'name');
            }
            $cols = $db->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
            return array_column($cols, 'Field');
        } catch (\Throwable $e) {
            return [];
        }
    };

    // 1. Invoices table FBR integration columns
    if (db_table_exists($db, 'invoices')) {
        $existing = $getColumns('invoices');
        $fbrCols = [
            'fbr_status' => "VARCHAR(50) DEFAULT 'unposted'",
            'fbr_invoice_no' => 'VARCHAR(100)',
            'fbr_pos_id' => 'VARCHAR(50)',
            'fbr_code' => 'VARCHAR(20)',
            'fbr_response' => 'TEXT',
            'fbr_qr_code' => 'TEXT',
            'fbr_posted_at' => 'DATETIME',
            'fbr_posted_by' => 'INTEGER',
            'approved_at' => 'DATETIME',
            'approved_by' => 'INTEGER',
        ];
        foreach ($fbrCols as $col => $type) {
            if (!in_array($col, $existing, true)) {
                try {
                    $db->exec("ALTER TABLE invoices ADD COLUMN {$col} {$type}");
                } catch (\Throwable $e) {}
            }
        }
    }

    // 2. Companies table FBR per-company settings
    if (db_table_exists($db, 'companies')) {
        $existing = $getColumns('companies');
        $coCols = [
            'fbr_pos_id' => 'VARCHAR(50)',
            'fbr_bearer_token' => 'TEXT',
            'fbr_environment' => "VARCHAR(20) DEFAULT 'sandbox'",
            'fbr_enabled' => 'BOOLEAN DEFAULT 1',
        ];
        foreach ($coCols as $col => $type) {
            if (!in_array($col, $existing, true)) {
                try {
                    $db->exec("ALTER TABLE companies ADD COLUMN {$col} {$type}");
                } catch (\Throwable $e) {}
            }
        }
    }

    // 3. Line items & products PCT/HS Code
    foreach (['products', 'invoice_items'] as $tbl) {
        if (db_table_exists($db, $tbl)) {
            $existing = $getColumns($tbl);
            if (!in_array('pct_code', $existing, true)) {
                try {
                    $db->exec("ALTER TABLE {$tbl} ADD COLUMN pct_code VARCHAR(50)");
                } catch (\Throwable $e) {}
            }
        }
    }

    // 4. Default FBR configuration in app_settings (FBR Digital Invoicing API v1)
    if (get_app_setting($db, 'fbr_enabled') === null) {
        set_app_setting($db, 'fbr_enabled', '1');
    }
    if (get_app_setting($db, 'fbr_environment') === null) {
        set_app_setting($db, 'fbr_environment', 'sandbox');
    }
    $currApi = get_app_setting($db, 'fbr_api_url');
    if ($currApi === null || str_contains($currApi, 'ims.pral.com.pk')) {
        set_app_setting($db, 'fbr_api_url', 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb');
    }
    $currVal = get_app_setting($db, 'fbr_validate_url');
    if ($currVal === null) {
        set_app_setting($db, 'fbr_validate_url', 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb');
    }
    $currTok = get_app_setting($db, 'fbr_bearer_token');
    if ($currTok === null || $currTok === '') {
        set_app_setting($db, 'fbr_bearer_token', '18d1e4a2-145a-3ec1-b20b-02ccaab53457');
    }
    if (get_app_setting($db, 'fbr_pos_id') === null) {
        set_app_setting($db, 'fbr_pos_id', '100001');
    }
    if (get_app_setting($db, 'fbr_mode') === null || get_app_setting($db, 'fbr_mode') === 'simulation') {
        set_app_setting($db, 'fbr_mode', 'live');
    }
}

function ensure_default_admin(PDO $db): void
{
    $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $username = trim((string) env_value('ADMIN_USERNAME', 'admin'));
    $email = trim((string) env_value('ADMIN_EMAIL', 'admin@dptech.local'));
    $password = (string) env_value('ADMIN_PASSWORD', '');

    // No default passwords: unless a strong ADMIN_PASSWORD is configured, create a random one and
    // save it where only someone with access to the server files can read it.
    $generated = strlen($password) < MIN_PASSWORD_LENGTH || strtolower($password) === 'admin123';
    if ($generated) {
        $password = bin2hex(random_bytes(8));
        $noteFile = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'first_run_admin_password.txt';
        $note = "First-run administrator login\r\nUsername: {$username}\r\nPassword: {$password}\r\n\r\n"
            . "Sign in, change this password under your profile, then delete this file.\r\n";
        if (@file_put_contents($noteFile, $note, LOCK_EX) === false) {
            throw new RuntimeException('Cannot create the first administrator: set ADMIN_PASSWORD in .env (8+ characters) or make the storage/ folder writable.');
        }
        @chmod($noteFile, 0600);
    }

    $stmt = $db->prepare('INSERT INTO users (username, email, hashed_password, full_name, role, is_active, created_at, updated_at) VALUES (:username, :email, :hashed_password, :full_name, :role, :is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'hashed_password' => password_hash($password, PASSWORD_BCRYPT),
        'full_name' => 'Administrator',
        'role' => 'admin',
        'is_active' => 1,
    ]);
}

function check_emergency_password_reset(PDO $db): void
{
    $storageDir = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
    $resetFile = $storageDir . DIRECTORY_SEPARATOR . 'reset_password.txt';
    if (!is_file($resetFile)) {
        return;
    }

    $raw = trim((string) file_get_contents($resetFile));
    if ($raw !== '') {
        $newHash = getPasswordHash($raw);
        $stmt = $db->prepare('UPDATE users SET hashed_password = :h, updated_at = CURRENT_TIMESTAMP WHERE role = "admin" OR id = 1');
        $stmt->execute(['h' => $newHash]);
    }
    @unlink($resetFile);
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_data(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        return request_json();
    }

    $data = $_POST;
    return is_array($data) && $data !== [] ? $data : request_json();
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_response(['detail' => 'Request body must be a JSON object.'], 400);
    }
    return $payload;
}

function request_path(): string
{
    $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($rawUri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    // Strip /index.php prefix if passed through rewrite or direct file access
    if (str_starts_with($path, '/index.php/')) {
        $path = substr($path, 10);
    } elseif ($path === '/index.php') {
        $path = '/';
    }

    // Strip /php/public prefix if directly requested
    if (str_starts_with($path, '/php/public/')) {
        $path = substr($path, strlen('/php/public'));
    } elseif ($path === '/php/public') {
        $path = '/';
    }

    // Strip script base directory if hosted in a subdirectory (e.g. /subfolder/login)
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (str_ends_with(strtolower($scriptName), '.php')) {
        $scriptDir = dirname($scriptName);
        if ($scriptDir !== '/' && $scriptDir !== '.' && $scriptDir !== '' && !str_starts_with($scriptDir, '/static') && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir));
        }
    }

    return $path !== '' ? $path : '/';
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function bearer_token(): ?string
{
    // 1. Check server environment headers (handles Apache mod_proxy_fcgi, REDIRECT_ chains)
    $candidateHeaders = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        $_SERVER['HTTP_X_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_HTTP_X_AUTHORIZATION'] ?? null,
        $_SERVER['REDIRECT_REDIRECT_HTTP_X_AUTHORIZATION'] ?? null,
        $_SERVER['HTTP_X_TOKEN'] ?? null,
        $_SERVER['REDIRECT_REMOTE_USER'] ?? null,
        $_SERVER['REMOTE_USER'] ?? null,
        $_SERVER['PHP_AUTH_DIGEST'] ?? null,
    ];

    $header = '';
    foreach ($candidateHeaders as $cand) {
        if (is_string($cand) && trim($cand) !== '') {
            $header = trim($cand);
            break;
        }
    }

    // 2. Check getallheaders() if available
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach (['Authorization', 'authorization', 'X-Authorization', 'x-authorization', 'X-Token', 'x-token'] as $k) {
            if (!empty($headers[$k]) && is_string($headers[$k])) {
                $header = trim($headers[$k]);
                break;
            }
        }
    }

    // 3. Check apache_request_headers() if available
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach (['Authorization', 'authorization', 'X-Authorization', 'x-authorization', 'X-Token', 'x-token'] as $k) {
            if (!empty($headers[$k]) && is_string($headers[$k])) {
                $header = trim($headers[$k]);
                break;
            }
        }
    }

    // Extract Bearer token from header
    if ($header !== '') {
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }
        // If header was passed as raw token without "Bearer " prefix (e.g. via X-Token)
        if (!str_contains($header, ' ') && strlen($header) > 20) {
            return $header;
        }
    }

    // 4. Fallback to query parameter ?token=
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }

    // 5. Fallback to POST body token
    if (!empty($_POST['token']) && is_string($_POST['token'])) {
        return trim($_POST['token']);
    }

    // 6. Fallback to cookie
    if (!empty($_COOKIE['token']) && is_string($_COOKIE['token'])) {
        $cToken = trim(urldecode((string) $_COOKIE['token']));
        if (str_starts_with(strtolower($cToken), 'bearer ')) {
            $cToken = trim(substr($cToken, 7));
        }
        if ($cToken !== '') {
            return $cToken;
        }
    }

    return null;
}

function require_authenticated_user(PDO $db): object
{
    $token = bearer_token();
    if ($token === null || $token === '') {
        header('WWW-Authenticate: Bearer');
        json_response(['detail' => 'Could not validate credentials'], 401);
    }

    $algorithm = (string) env_value('ALGORITHM', 'HS256');
    $user = getCurrentUser($db, $token, app_secret_key(), $algorithm);

    if (!$user) {
        header('WWW-Authenticate: Bearer');
        json_response(['detail' => 'Could not validate credentials'], 401);
    }
    if (isset($user->is_active) && !$user->is_active) {
        json_response(['detail' => 'Account is deactivated'], 403);
    }
    return $user;
}

/** Whether the user may perform $action (view/create/edit/delete) on $module. Does not stop the request. */
function user_has_permission(object $user, string $module, string $action): bool
{
    $role = strtolower((string) ($user->role ?? ''));
    if ($role === 'admin') {
        return true; // Only Admin has full system access by default
    }

    $permissions = $user->permissions ?? [];
    if (is_string($permissions)) {
        $permissions = json_decode($permissions, true) ?: [];
    }
    if (isset($permissions['permissions']) && is_array($permissions['permissions'])) {
        $permissions = $permissions['permissions'];
    }

    // Map module aliases (e.g. companies <=> settings)
    $hasPerm = !empty($permissions[$module][$action]);
    if (!$hasPerm && $module === 'companies' && !empty($permissions['settings'][$action])) {
        $hasPerm = true;
    }
    if (!$hasPerm && $module === 'settings' && !empty($permissions['companies'][$action])) {
        $hasPerm = true;
    }

    return $hasPerm;
}

function require_permission_for(PDO $db, object $user, string $module, string $action): void
{
    if (!user_has_permission($user, $module, $action)) {
        json_response(['detail' => "Permission denied: {$module}.{$action}"], 403);
    }
}

function find_template_file(string $name): string
{
    $path = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $name;
    if (is_file($path)) {
        return $path;
    }
    throw new RuntimeException("Template not found: {$name}");
}

function render_template(string $templateName, array $context = []): never
{
    $companyName = env_value('COMPANY_NAME', 'DATAPOINT Technologies');
    $context['company'] = $context['company'] ?? $companyName;

    $templatePath = find_template_file($templateName);
    $content = file_get_contents($templatePath);

    // Check for extends
    if (preg_match('/^{%\s*extends\s*["\']([^"\']+)["\']\s*%}/m', $content, $m)) {
        $parentName = $m[1];
        $parentPath = find_template_file($parentName);
        $parentContent = file_get_contents($parentPath);

        // Extract child blocks
        $blocks = [];
        if (preg_match_all('/{%\s*block\s+(\w+)\s*%}(.*?){%\s*endblock\s*%}/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $blocks[$match[1]] = $match[2];
            }
        }

        // Replace blocks in parent
        $content = preg_replace_callback('/{%\s*block\s+(\w+)\s*%}(.*?){%\s*endblock\s*%}/s', function ($m) use ($blocks) {
            $name = $m[1];
            return $blocks[$name] ?? $m[2];
        }, $parentContent);
    }

    // Resolve includes recursively (up to 5 levels)
    for ($i = 0; $i < 5; $i++) {
        if (!preg_match('/{%\s*include\s*["\']([^"\']+)["\']\s*%}/', $content)) {
            break;
        }
        $content = preg_replace_callback('/{%\s*include\s*["\']([^"\']+)["\']\s*%}/', function ($m) {
            $incFile = find_template_file($m[1]);
            return file_get_contents($incFile);
        }, $content);
    }

    // Evaluate simple conditionals:
    // 1. {% if not var %}...{% endif %}
    $content = preg_replace_callback('/{%\s*if\s+not\s+([a-zA-Z0-9_\.]+)\s*%}(.*?){%\s*endif\s*%}/s', function ($m) use ($context) {
        $var = $m[1];
        $val = resolve_context_var($var, $context);
        return empty($val) ? $m[2] : '';
    }, $content);

    // 2. {% if var %}...{% else %}...{% endif %}
    $content = preg_replace_callback('/{%\s*if\s+([a-zA-Z0-9_\.]+)\s*%}(.*?){%\s*else\s*%}(.*?){%\s*endif\s*%}/s', function ($m) use ($context) {
        $var = $m[1];
        $val = resolve_context_var($var, $context);
        return !empty($val) ? $m[2] : $m[3];
    }, $content);

    // 3. {% if var %}...{% endif %}
    $content = preg_replace_callback('/{%\s*if\s+([a-zA-Z0-9_\.]+)\s*%}(.*?){%\s*endif\s*%}/s', function ($m) use ($context) {
        $var = $m[1];
        $val = resolve_context_var($var, $context);
        return !empty($val) ? $m[2] : '';
    }, $content);

    // Interpolate {{ variable|tojson }}
    $content = preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*\|\s*tojson\s*}}/', function ($m) use ($context) {
        $var = $m[1];
        $val = resolve_context_var($var, $context);
        return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }, $content);

    // Interpolate {{ variable }}
    $content = preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($m) use ($context) {
        $var = $m[1];
        $val = resolve_context_var($var, $context);
        return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
    }, $content);

    // Replace sidebar company folder placeholders (only for visitors who are logged in:
    // company names and estimate counts must not be shown to anonymous visitors)
    $showSidebarData = request_has_valid_login();
    if (str_contains($content, '<!-- SIDEBAR_COMPANY_LINKS_PLACEHOLDER -->')) {
        $content = str_replace('<!-- SIDEBAR_COMPANY_LINKS_PLACEHOLDER -->', $showSidebarData ? get_sidebar_company_links_html() : '', $content);
    }
    if (str_contains($content, '<!-- SIDEBAR_TOTAL_ESTIMATES_COUNT -->')) {
        $content = str_replace('<!-- SIDEBAR_TOTAL_ESTIMATES_COUNT -->', $showSidebarData ? (string) get_sidebar_total_estimates_count() : '0', $content);
    }

    header('Content-Type: text/html; charset=utf-8');
    echo $content;
    exit;
}

/** True when the request carries a valid, active login token (header, cookie or ?token=). */
function request_has_valid_login(): bool
{
    try {
        $token = bearer_token();
        if ($token === null || $token === '') {
            return false;
        }
        $user = getCurrentUser(database_connection(), $token, app_secret_key(), (string) env_value('ALGORITHM', 'HS256'));
        return $user !== null && (!isset($user->is_active) || (bool) $user->is_active);
    } catch (\Throwable $e) {
        return false;
    }
}

function get_sidebar_company_links_html(): string
{
    try {
        $db = database_connection();
        if (!db_table_exists($db, 'companies')) {
            return '';
        }

        $hasEstimates = db_table_exists($db, 'estimates');
        if ($hasEstimates) {
            $stmt = $db->query('
                SELECT c.id, c.name, c.code, COUNT(e.id) AS estimates_count
                FROM companies c
                LEFT JOIN estimates e ON e.company_id = c.id
                GROUP BY c.id, c.name, c.code, c.sort_order
                ORDER BY c.sort_order ASC, c.id ASC
            ');
        } else {
            $stmt = $db->query('SELECT id, name, code, 0 AS estimates_count FROM companies ORDER BY sort_order ASC, id ASC');
        }
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($companies)) {
            return '<div class="px-2 py-1 text-[11px] text-slate-500">No companies found</div>';
        }

        $currentCid = $_GET['company_id'] ?? null;
        $reqUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($reqUri, PHP_URL_PATH) ?? '';
        $isEstimates = str_contains($path, '/estimates');

        $html = '';
        foreach ($companies as $co) {
            $cid = (int) $co['id'];
            $name = htmlspecialchars((string) $co['name'], ENT_QUOTES, 'UTF-8');
            $count = (int) ($co['estimates_count'] ?? 0);
            $isActive = ($isEstimates && $currentCid !== null && (string) $currentCid === (string) $cid);

            $activeClass = $isActive
                ? 'bg-indigo-600/30 text-white font-semibold border border-indigo-500/50 shadow-sm'
                : 'text-slate-300 hover:text-white hover:bg-slate-800/80';
            $iconClass = $isActive ? 'fa-folder-open text-indigo-400' : 'fa-folder text-amber-400/90';

            $html .= sprintf(
                '<a href="/estimates?company_id=%d" class="company-folder-link flex items-center justify-between px-2 py-1.5 text-xs rounded-md transition-colors %s" data-cid="%d" title="%s">' . "\n" .
                '    <span class="truncate flex items-center gap-1.5 mr-1 min-w-0">' . "\n" .
                '        <i class="fas %s text-[11px] shrink-0"></i>' . "\n" .
                '        <span class="truncate">%s</span>' . "\n" .
                '    </span>' . "\n" .
                '    <span class="flex items-center gap-1 shrink-0">' . "\n" .
                '        <span class="text-[10px] bg-slate-800/90 text-slate-400 px-1.5 py-0.2 rounded font-mono">%d</span>' . "\n" .
                '    </span>' . "\n" .
                '</a>' . "\n",
                $cid,
                $activeClass,
                $cid,
                $name,
                $iconClass,
                $name,
                $count
            );
        }
        return $html;
    } catch (\Throwable $e) {
        return '<div class="px-2 py-1 text-[11px] text-slate-500">Loading company folders...</div>';
    }
}

function get_sidebar_total_estimates_count(): int
{
    try {
        $db = database_connection();
        if (!db_table_exists($db, 'estimates')) {
            return 0;
        }
        return (int) $db->query('SELECT COUNT(*) FROM estimates')->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

function resolve_context_var(string $key, array $context): mixed
{
    if (str_starts_with($key, 'request.query_params.')) {
        $param = substr($key, strlen('request.query_params.'));
        return $_GET[$param] ?? null;
    }

    return $context[$key] ?? null;
}

