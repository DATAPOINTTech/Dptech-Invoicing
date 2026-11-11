<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=== Testing Contact Details in Estimates & Invoices ===\n\n";

$db = database_connection();

// 1. Verify Company 1 (DATAPOINT) record in database
echo "1. Checking Company 1 credentials in database...\n";
$co1 = $db->query("SELECT * FROM companies WHERE id = 1 OR is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert(!empty($co1), "Company 1 must exist");
echo "   Company: {$co1['name']}\n";
echo "   Email: {$co1['email']}\n";
echo "   Website: {$co1['website']}\n";
echo "   Phone: {$co1['phone']}\n";
echo "   WhatsApp: " . ($co1['whatsapp'] ?? $co1['mobile']) . "\n";

if ($co1['email'] !== 'sales@datapoinittechnology.com') {
    echo "FAIL: Expected email sales@datapoinittechnology.com, got: {$co1['email']}\n";
    exit(1);
}
if ($co1['website'] !== 'https://datapointtechnology.com') {
    echo "FAIL: Expected website https://datapointtechnology.com, got: {$co1['website']}\n";
    exit(1);
}
if ($co1['phone'] !== '+92 22 3669326') {
    echo "FAIL: Expected phone +92 22 3669326, got: {$co1['phone']}\n";
    exit(1);
}
if (($co1['whatsapp'] ?? $co1['mobile']) !== '+923712566540') {
    echo "FAIL: Expected whatsapp +923712566540, got: " . ($co1['whatsapp'] ?? $co1['mobile']) . "\n";
    exit(1);
}
echo "   -> PASS: Company table contains exact required contacts!\n\n";

// 2. Test PDF Generation for an Invoice
echo "2. Generating Invoice PDF and verifying contacts...\n";
$inv = $db->query("SELECT * FROM invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    // Insert a dummy invoice for testing
    $db->exec("INSERT INTO invoices (invoice_no, client_id, invoice_date, total_amount, company_id, company_name) VALUES ('INV-TEST-001', 1, '2026-11-11', 50000, 1, 'DATAPOINT Technologies')");
    $inv = $db->query("SELECT * FROM invoices ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$invPdf = generate_invoice_pdf($inv);
assert(strlen($invPdf) > 1000, "Invoice PDF must be generated");

$expectedStrings = [
    'sales@datapoinittechnology.com',
    'datapointtechnology.com',
    '+92 22 3669326',
    '+923712566540'
];

foreach ($expectedStrings as $needle) {
    if (strpos($invPdf, $needle) === false) {
        echo "FAIL: Invoice PDF missing expected string: '{$needle}'\n";
        exit(1);
    }
    echo "   ✓ Invoice PDF contains '{$needle}'\n";
}
echo "   -> PASS: Invoice PDF header contains all contact information!\n\n";

// 3. Test PDF Generation for an Estimate
echo "3. Generating Estimate PDF and verifying contacts...\n";
$est = $db->query("SELECT * FROM estimates ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$est) {
    $db->exec("INSERT INTO estimates (estimate_no, client_id, estimate_date, total_amount, company_id, company_name) VALUES ('EST-TEST-001', 1, '2026-11-11', 75000, 1, 'DATAPOINT Technologies')");
    $est = $db->query("SELECT * FROM estimates ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$estPdf = generate_estimate_pdf($est);
assert(strlen($estPdf) > 1000, "Estimate PDF must be generated");

foreach ($expectedStrings as $needle) {
    if (strpos($estPdf, $needle) === false) {
        echo "FAIL: Estimate PDF missing expected string: '{$needle}'\n";
        exit(1);
    }
    echo "   ✓ Estimate PDF contains '{$needle}'\n";
}
echo "   -> PASS: Estimate PDF header contains all contact information!\n\n";

// 4. Verify template files
echo "4. Verifying web template files...\n";
$invDetail = file_get_contents(__DIR__ . '/../templates/invoices/detail.html');
assert(strpos($invDetail, 'sales@datapoinittechnology.com') !== false, "invoices/detail.html has email");
assert(strpos($invDetail, 'datapointtechnology.com') !== false, "invoices/detail.html has website");
assert(strpos($invDetail, '+92 22 3669326') !== false, "invoices/detail.html has landline");
assert(strpos($invDetail, '+923712566540') !== false, "invoices/detail.html has whatsapp");
echo "   ✓ invoices/detail.html verified\n";

$estDetail = file_get_contents(__DIR__ . '/../templates/estimates/detail.html');
assert(strpos($estDetail, 'sales@datapoinittechnology.com') !== false, "estimates/detail.html has email");
assert(strpos($estDetail, 'datapointtechnology.com') !== false, "estimates/detail.html has website");
assert(strpos($estDetail, '+92 22 3669326') !== false, "estimates/detail.html has landline");
assert(strpos($estDetail, '+923712566540') !== false, "estimates/detail.html has whatsapp");
echo "   ✓ estimates/detail.html verified\n";

$compTpl = file_get_contents(__DIR__ . '/../templates/companies.html');
assert(strpos($compTpl, 'sales@datapoinittechnology.com') !== false, "companies.html has email");
assert(strpos($compTpl, 'datapointtechnology.com') !== false, "companies.html has website");
assert(strpos($compTpl, '+92 22 3669326') !== false, "companies.html has landline");
assert(strpos($compTpl, '+923712566540') !== false, "companies.html has whatsapp");
echo "   ✓ companies.html verified\n";

echo "\n>>> ALL ESTIMATES & INVOICES CONTACTS TESTS PASSED 100%! <<<\n";
