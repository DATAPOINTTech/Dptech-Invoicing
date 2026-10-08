<?php

declare(strict_types=1);

/**
 * DATAPOINT Invoicing System - Production Database Reset Script
 * Clears all test/demo transaction records and restores a pristine production state.
 */

require_once __DIR__ . '/../bootstrap.php';

echo "========================================================\n";
echo "  DATAPOINT INVOICING - PRODUCTION DATABASE RESET       \n";
echo "========================================================\n\n";

$db = database_connection();

// Tables to wipe clean
$tablesToClear = [
    'estimate_items',
    'estimates',
    'invoice_items',
    'invoices',
    'payments',
    'purchase_payments',
    'purchase_items',
    'purchase_invoices',
    'expenses',
    'clients',
    'suppliers',
    'inventory',
    'stock_movements',
    'price_list',
    'products',
    'projects',
    'companies',
    'users'
];

$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $db->exec('PRAGMA foreign_keys = OFF;');
}

echo "1. Clearing all transactional and demo records...\n";
foreach ($tablesToClear as $table) {
    if (db_table_exists($db, $table)) {
        $db->exec("DELETE FROM {$table};");
        echo "   - Cleared table: {$table}\n";
    }
}

// Reset SQLite auto-increment sequences
if ($driver === 'sqlite') {
    if (db_table_exists($db, 'sqlite_sequence')) {
        $db->exec("DELETE FROM sqlite_sequence;");
        echo "   - Reset sqlite_sequence auto-increment counters to 1.\n";
    }
    $db->exec('PRAGMA foreign_keys = ON;');
}

echo "\n2. Initializing clean Multi-Company production records...\n";
$companies = [
    [
        'name' => env_value('COMPANY_NAME', 'DATAPOINT Technologies'),
        'code' => 'DPT',
        'markup_percent' => 0.0,
        'is_default' => 1,
        'sort_order' => 1,
        'logo_url' => '/static/img/logo.png',
        'email' => env_value('COMPANY_EMAIL', 'info@datapointtechnology.com'),
        'phone' => env_value('COMPANY_PHONE', '+923167788990'),
        'mobile' => env_value('COMPANY_MOBILE', '+923167788990'),
        'address' => env_value('COMPANY_ADDRESS', 'G 32 Shayas Residence, Jamshoro Road'),
        'city' => 'Hyderabad',
        'ntn' => '7192834-5',
        'strn' => '17-00-7192-834-11',
        'terms' => "1. Payment: Net 30 days from invoice date.\n2. Prices are subject to prevailing government taxes.\n3. Validity: 15 days from issuance."
    ],
    [
        'name' => 'Pakistan Technocrates Works & Services',
        'code' => 'PTC',
        'markup_percent' => 2.0,
        'is_default' => 0,
        'sort_order' => 2,
        'logo_url' => '/static/uploads/logo_6ac6793528d74.png',
        'email' => 'contact@paktechnocrates.com',
        'phone' => '+92 21 34567891',
        'mobile' => '+92 300 2345678',
        'address' => 'Suite 204, Commercial Zone, Qasimabad',
        'city' => 'Hyderabad',
        'ntn' => '2345678-9',
        'strn' => '17-00-2345-678-90',
        'terms' => "1. Payment: Within 15 days upon milestone completion.\n2. Supplies strictly in compliance with engineering specifications.\n3. Validity: 15 days."
    ],
    [
        'name' => 'M tech Cybernet and Electronics',
        'code' => 'MTC',
        'markup_percent' => 3.0,
        'is_default' => 0,
        'sort_order' => 3,
        'logo_url' => '/static/uploads/logo_6ac6794d40860.png',
        'email' => 'sales@mtechcybernet.com',
        'phone' => '+92 21 34567892',
        'mobile' => '+92 300 3456789',
        'address' => 'Floor 3, Executive Tower, Auto Bhan Road',
        'city' => 'Hyderabad',
        'ntn' => '3456789-0',
        'strn' => '17-00-3456-789-01',
        'terms' => "1. Payment due upon delivery and acceptance.\n2. Telecommunications and hardware covered under standard warranty.\n3. Validity: 15 days."
    ]
];

$coStmt = $db->prepare('
    INSERT INTO companies (name, code, markup_percent, is_default, sort_order, logo_url, email, phone, mobile, address, city, ntn, strn, terms, created_at, updated_at)
    VALUES (:name, :code, :markup_percent, :is_default, :sort_order, :logo_url, :email, :phone, :mobile, :address, :city, :ntn, :strn, :terms, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');

foreach ($companies as $co) {
    $coStmt->execute($co);
    echo "   + Created Company Folder: {$co['name']} (Markup: {$co['markup_percent']}%, Code: {$co['code']})\n";
}

echo "\n3. Creating clean Administrator Account...\n";
$adminUsername = trim((string) env_value('ADMIN_USERNAME', 'admin'));
$adminEmail = trim((string) env_value('ADMIN_EMAIL', 'admin@dptech.local'));
$adminPassword = (string) env_value('ADMIN_PASSWORD', 'admin123');
$adminHash = getPasswordHash($adminPassword);

$adminStmt = $db->prepare('
    INSERT INTO users (username, email, hashed_password, full_name, phone, role, is_active, permissions, created_at, updated_at)
    VALUES (:u, :e, :h, :fn, :ph, :r, 1, :p, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');

$adminStmt->execute([
    'u' => $adminUsername,
    'e' => $adminEmail,
    'h' => $adminHash,
    'fn' => 'System Administrator',
    'ph' => env_value('COMPANY_PHONE', '+92 316 7788990'),
    'r' => 'admin',
    'p' => json_encode(['all' => true])
]);

echo "   + Administrator account initialized:\n";
echo "     - Username: {$adminUsername}\n";
echo "     - Email:    {$adminEmail}\n";
echo "     - Role:     admin (Unrestricted Granular Permission Authority)\n";

echo "\n4. Seeding Products & Live Price List Catalog...\n";
require_once __DIR__ . '/seed_catalog.php';

echo "\n========================================================\n";
echo "  PRODUCTION DATABASE RESET COMPLETED SUCCESSFULLY!     \n";
echo "  System is clean, secured, and ready for deployment.   \n";
echo "========================================================\n";
