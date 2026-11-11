<?php

declare(strict_types=1);

// Can be run via CLI
require_once __DIR__ . '/../bootstrap.php';

$db = database_connection();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

$zeroProducts = in_array('--zero-products', $argv ?? [], true) || in_array('--all', $argv ?? [], true);

echo "========================================================\n";
echo "   DATAPOINT INVOICING SYSTEM - RESET ALL DATA TO ZERO  \n";
echo "========================================================\n\n";

if ($driver === 'sqlite') {
    $db->exec('PRAGMA foreign_keys = OFF;');
} elseif ($driver === 'mysql') {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0;');
}

// 1. Transactional and business operational tables to completely wipe to zero
$tablesToZero = [
    'invoice_items',
    'invoices',
    'estimate_items',
    'estimates',
    'payments',
    'projects',
    'clients',
    'expenses',
    'purchase_items',
    'purchase_payments',
    'purchase_invoices',
    'stock_movements',
    'suppliers',
    'agent_messages',
    'agent_conversations',
    'login_attempts',
];

echo "1. Clearing operational data tables to 0 rows...\n";
foreach ($tablesToZero as $table) {
    if (db_table_exists($db, $table)) {
        if ($driver === 'mysql') {
            $db->exec("TRUNCATE TABLE `{$table}`;");
        } else {
            $db->exec("DELETE FROM `{$table}`;");
        }
        echo "   - Table '{$table}' cleared to 0 rows.\n";
    }
}

// 2. Product and inventory tables
$productTables = ['inventory', 'price_list', 'products'];
foreach ($productTables as $table) {
    if (db_table_exists($db, $table)) {
        if ($driver === 'mysql') {
            $db->exec("TRUNCATE TABLE `{$table}`;");
        } else {
            $db->exec("DELETE FROM `{$table}`;");
        }
        echo "   - Table '{$table}' cleared.\n";
    }
}

// 3. Reset SQLite auto-increment sequences so all IDs restart at 1
if ($driver === 'sqlite') {
    if (db_table_exists($db, 'sqlite_sequence')) {
        $db->exec("DELETE FROM sqlite_sequence WHERE name IN (
            'invoice_items', 'invoices', 'estimate_items', 'estimates',
            'payments', 'projects', 'clients', 'expenses', 'purchase_items',
            'purchase_payments', 'purchase_invoices', 'stock_movements',
            'suppliers', 'agent_messages', 'agent_conversations', 'login_attempts',
            'products', 'price_list', 'inventory'
        );");
        echo "   - SQLite auto-increment sequences reset to 0.\n";
    }
}

// 4. Products handling
if ($zeroProducts) {
    set_app_setting($db, 'no_default_products', '1');
    echo "   - Products wiped to 0 rows (--zero-products flag passed).\n";
} else {
    set_app_setting($db, 'no_default_products', '0');
    ensure_default_products($db);
    $prodCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    echo "   - Product catalog seeded fresh with {$prodCount} standard products (IDs starting at 1).\n";
}

// 5. Retain Company 1 (DATAPOINT Technologies) with official contact info
if (db_table_exists($db, 'companies')) {
    $db->exec("DELETE FROM companies WHERE id > 1;");
    if ($driver === 'sqlite' && db_table_exists($db, 'sqlite_sequence')) {
        $db->exec("UPDATE sqlite_sequence SET seq = 1 WHERE name = 'companies';");
    }
    $officialEmail = env_value('COMPANY_EMAIL', 'sales@datapoinittechnology.com');
    $officialWebsite = env_value('COMPANY_WEBSITE', 'https://datapointtechnology.com');
    $officialLandline = env_value('COMPANY_PHONE', '+92 22 3669326');
    $officialWhatsapp = env_value('COMPANY_WHATSAPP', '+923712566540');
    $updCo = $db->prepare("UPDATE companies SET email = :em, website = :ws, phone = :ph, mobile = :wa, whatsapp = :wa WHERE id = 1");
    $updCo->execute([
        'em' => $officialEmail,
        'ws' => $officialWebsite,
        'ph' => $officialLandline,
        'wa' => $officialWhatsapp,
    ]);
    echo "   - Retained Company 1 (DATAPOINT Technologies) with official contacts.\n";
}

// 6. Retain Admin User
$adminUser = $db->query("SELECT id, username, email FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($adminUser) {
    echo "   - Retained Admin User: '{$adminUser['username']}' (ID: {$adminUser['id']}, Email: {$adminUser['email']}).\n";
}

// Re-enable foreign keys and vacuum database file
if ($driver === 'sqlite') {
    $db->exec('PRAGMA foreign_keys = ON;');
    $db->exec('VACUUM;');
} elseif ($driver === 'mysql') {
    $db->exec('SET FOREIGN_KEY_CHECKS = 1;');
}

echo "\n========================================================\n";
echo "DATABASE STATUS SUMMARY AFTER RESET:\n";
echo "========================================================\n";

$verifyTables = [
    'invoices',
    'invoice_items',
    'estimates',
    'estimate_items',
    'payments',
    'clients',
    'projects',
    'expenses',
    'purchase_invoices',
    'purchase_items',
    'purchase_payments',
    'stock_movements',
    'suppliers',
    'agent_conversations',
    'agent_messages',
    'login_attempts',
    'products',
    'companies',
    'users',
];

foreach ($verifyTables as $t) {
    if (db_table_exists($db, $t)) {
        $cnt = $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo str_pad($t, 25) . ": {$cnt} rows\n";
    }
}

echo "\n>>> ALL DATA SUCCESSFULLY RESET TO ZERO! <<<\n";
