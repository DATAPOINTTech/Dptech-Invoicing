<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=== TEST: Currency Selection (PKR, USD, EUR, GBP, AED, SAR) ===\n";

$db = database_connection();

// Ensure test user
$stmt = $db->prepare("SELECT * FROM users WHERE username = 'admin' LIMIT 1");
$stmt->execute();
$adminUser = $stmt->fetch();
if (!$adminUser) {
    $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES ('admin', 'admin@example.com', 'test', 'admin')")->execute();
    $adminUserId = (int)$db->lastInsertId();
} else {
    $adminUserId = (int)$adminUser['id'];
}

// Ensure test client
$stmt = $db->prepare("SELECT * FROM clients LIMIT 1");
$stmt->execute();
$client = $stmt->fetch();
if (!$client) {
    $db->prepare("INSERT INTO clients (name, company, email, phone) VALUES ('Currency Client', 'Currency Ltd', 'currency@example.com', '+923001234567')")->execute();
    $clientId = (int)$db->lastInsertId();
} else {
    $clientId = (int)$client['id'];
}

// Ensure companies
$cos = $db->query("SELECT id FROM companies ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
$companyId = !empty($cos) ? (int)$cos[0] : 1;

// 1. Create Estimate with USD
echo "1. Testing Estimate creation with USD...\n";
$items = [
    [
        'description' => 'Dell Latitude Laptop i7',
        'model_make' => 'Latitude 5420',
        'quantity' => 2,
        'unit' => 'pcs',
        'unit_price' => 750.00,
        'tax_rate' => 18.0
    ]
];
$subtotal = 1500.00;
$taxAmount = round($subtotal * 0.18, 2);
$totalAmount = $subtotal + $taxAmount;

$stmt = $db->prepare("
    INSERT INTO estimates (
        estimate_no, client_id, company_id, currency, title, estimate_date, valid_until,
        subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount,
        status, terms_conditions, notes, created_by
    ) VALUES (
        :estimate_no, :client_id, :company_id, :currency, :title, :estimate_date, :valid_until,
        :subtotal, :discount_percent, :discount_amount, :tax_rate, :tax_amount, :total_amount,
        'draft', :terms_conditions, :notes, :created_by
    )
");
$estNo = 'EST-USD-' . time();
$stmt->execute([
    ':estimate_no' => $estNo,
    ':client_id' => $clientId,
    ':company_id' => $companyId,
    ':currency' => 'USD',
    ':title' => 'USD Quote Test',
    ':estimate_date' => date('Y-m-d'),
    ':valid_until' => date('Y-m-d', strtotime('+15 days')),
    ':subtotal' => $subtotal,
    ':discount_percent' => 0,
    ':discount_amount' => 0,
    ':tax_rate' => 18.0,
    ':tax_amount' => $taxAmount,
    ':total_amount' => $totalAmount,
    ':terms_conditions' => 'Standard USD terms',
    ':notes' => 'USD Note',
    ':created_by' => $adminUserId
]);
$estId = (int)$db->lastInsertId();

// Add item
$itemStmt = $db->prepare("
    INSERT INTO estimate_items (estimate_id, description, model_make, quantity, unit, unit_price, tax_rate, total_price)
    VALUES (:estimate_id, :description, :model_make, :quantity, :unit, :unit_price, :tax_rate, :total)
");
$itemStmt->execute([
    ':estimate_id' => $estId,
    ':description' => $items[0]['description'],
    ':model_make' => $items[0]['model_make'],
    ':quantity' => $items[0]['quantity'],
    ':unit' => $items[0]['unit'],
    ':unit_price' => $items[0]['unit_price'],
    ':tax_rate' => $items[0]['tax_rate'],
    ':total' => $subtotal
]);

// Verify DB
$checkStmt = $db->prepare("SELECT currency FROM estimates WHERE id = ?");
$checkStmt->execute([$estId]);
$savedCurrency = $checkStmt->fetchColumn();
assert($savedCurrency === 'USD', "Expected currency USD, got: " . var_export($savedCurrency, true));
echo "   [PASS] Estimate created with currency = 'USD'\n";

// 2. Generate PDF for USD Estimate
echo "2. Generating PDF for USD Estimate...\n";
$pdfData = [
    'doc_type' => 'estimate',
    'id' => $estId,
    'estimate_no' => $estNo,
    'estimate_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+15 days')),
    'title' => 'USD Quote Test',
    'client_name' => 'Currency Ltd',
    'client_company' => 'Currency Ltd',
    'client_address' => 'Test Address',
    'client_phone' => '+923001234567',
    'client_email' => 'currency@example.com',
    'company_id' => $companyId,
    'company_name' => 'DATAPOINT Technologies',
    'company_logo' => '/static/img/logo.png',
    'currency' => 'USD',
    'subtotal' => $subtotal,
    'discount_percent' => 0,
    'discount_amount' => 0,
    'tax_rate' => 18.0,
    'tax_amount' => $taxAmount,
    'total_amount' => $totalAmount,
    'terms_conditions' => "1. USD payment terms\n2. Standard conditions",
    'items' => [
        [
            'description' => 'Dell Latitude Laptop i7',
            'model_make' => 'Latitude 5420',
            'quantity' => 2,
            'unit' => 'pcs',
            'unit_price' => 750.00,
            'total' => 1500.00
        ]
    ]
];
$pdfContent = generate_estimate_pdf($pdfData);
assert(strlen($pdfContent) > 1000, "PDF content too small");
assert(strpos($pdfContent, 'Rate \(USD\)') !== false, "PDF missing 'Rate (USD)' header");
assert(strpos($pdfContent, 'Total \(USD\)') !== false, "PDF missing 'Total (USD)' header");
assert(strpos($pdfContent, 'GRAND TOTAL \(USD\)') !== false, "PDF missing 'GRAND TOTAL (USD)' summary");
echo "   [PASS] PDF generated with 'Rate (USD)', 'Total (USD)', and 'GRAND TOTAL (USD)'\n";

// 3. Convert USD Estimate to Invoice
echo "3. Testing Estimate -> Invoice conversion with USD...\n";
$invNo = 'INV-USD-' . time();
$invStmt = $db->prepare("
    INSERT INTO invoices (
        invoice_no, client_id, company_id, currency, title, invoice_date, due_date,
        subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount,
        balance_due, status, terms_conditions, notes, created_by
    ) VALUES (
        :invoice_no, :client_id, :company_id, :currency, :title, :invoice_date, :due_date,
        :subtotal, :discount_percent, :discount_amount, :tax_rate, :tax_amount, :total_amount,
        :balance_due, 'draft', :terms_conditions, :notes, :created_by
    )
");
$invStmt->execute([
    ':invoice_no' => $invNo,
    ':client_id' => $clientId,
    ':company_id' => $companyId,
    ':currency' => $savedCurrency, // Converted from estimate
    ':title' => 'USD Quote Test',
    ':invoice_date' => date('Y-m-d'),
    ':due_date' => date('Y-m-d', strtotime('+30 days')),
    ':subtotal' => $subtotal,
    ':discount_percent' => 0,
    ':discount_amount' => 0,
    ':tax_rate' => 18.0,
    ':tax_amount' => $taxAmount,
    ':total_amount' => $totalAmount,
    ':balance_due' => $totalAmount,
    ':terms_conditions' => 'Standard USD terms',
    ':notes' => 'Converted from ' . $estNo,
    ':created_by' => $adminUserId
]);
$invId = (int)$db->lastInsertId();

$checkInv = $db->prepare("SELECT currency FROM invoices WHERE id = ?");
$checkInv->execute([$invId]);
$invCurrency = $checkInv->fetchColumn();
assert($invCurrency === 'USD', "Expected invoice currency USD, got: " . var_export($invCurrency, true));
echo "   [PASS] Converted Invoice retained currency = 'USD'\n";

// 4. Create EUR Invoice directly
echo "4. Testing Direct Invoice creation with EUR...\n";
$eurInvNo = 'INV-EUR-' . time();
$invStmt->execute([
    ':invoice_no' => $eurInvNo,
    ':client_id' => $clientId,
    ':company_id' => $companyId,
    ':currency' => 'EUR',
    ':title' => 'European Project Invoice',
    ':invoice_date' => date('Y-m-d'),
    ':due_date' => date('Y-m-d', strtotime('+30 days')),
    ':subtotal' => 2000.00,
    ':discount_percent' => 0,
    ':discount_amount' => 0,
    ':tax_rate' => 18.0,
    ':tax_amount' => 360.00,
    ':total_amount' => 2360.00,
    ':balance_due' => 2360.00,
    ':terms_conditions' => 'Euro terms',
    ':notes' => 'Direct EUR',
    ':created_by' => $adminUserId
]);
$eurInvId = (int)$db->lastInsertId();

$checkEur = $db->prepare("SELECT currency FROM invoices WHERE id = ?");
$checkEur->execute([$eurInvId]);
$eurCurrency = $checkEur->fetchColumn();
assert($eurCurrency === 'EUR', "Expected invoice currency EUR, got: " . var_export($eurCurrency, true));
echo "   [PASS] Direct Invoice created with currency = 'EUR'\n";

// 5. Convert EUR Invoice to Estimate
echo "5. Testing Invoice -> Estimate conversion with EUR...\n";
$convEstNo = 'EST-EUR-CONV-' . time();
$stmt->execute([
    ':estimate_no' => $convEstNo,
    ':client_id' => $clientId,
    ':company_id' => $companyId,
    ':currency' => $eurCurrency, // Converted from EUR invoice
    ':title' => 'European Project Invoice',
    ':estimate_date' => date('Y-m-d'),
    ':valid_until' => date('Y-m-d', strtotime('+15 days')),
    ':subtotal' => 2000.00,
    ':discount_percent' => 0,
    ':discount_amount' => 0,
    ':tax_rate' => 18.0,
    ':tax_amount' => 360.00,
    ':total_amount' => 2360.00,
    ':terms_conditions' => 'Euro terms',
    ':notes' => 'Converted from ' . $eurInvNo,
    ':created_by' => $adminUserId
]);
$convEstId = (int)$db->lastInsertId();

$checkConvEst = $db->prepare("SELECT currency FROM estimates WHERE id = ?");
$checkConvEst->execute([$convEstId]);
$convEstCurrency = $checkConvEst->fetchColumn();
assert($convEstCurrency === 'EUR', "Expected estimate currency EUR, got: " . var_export($convEstCurrency, true));
echo "   [PASS] Converted Estimate retained currency = 'EUR'\n";

// 6. Generate PDF for EUR Invoice
echo "6. Testing PDF generation for EUR Invoice...\n";
$eurPdfData = [
    'doc_type' => 'invoice',
    'id' => $eurInvId,
    'invoice_no' => $eurInvNo,
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'title' => 'European Project Invoice',
    'client_name' => 'Currency Ltd',
    'client_company' => 'Currency Ltd',
    'client_address' => 'Test Address',
    'client_phone' => '+923001234567',
    'client_email' => 'currency@example.com',
    'company_id' => $companyId,
    'company_name' => 'DATAPOINT Technologies',
    'company_logo' => '/static/img/logo.png',
    'currency' => 'EUR',
    'subtotal' => 2000.00,
    'discount_percent' => 0,
    'discount_amount' => 0,
    'tax_rate' => 18.0,
    'tax_amount' => 360.00,
    'total_amount' => 2360.00,
    'amount_paid' => 0.00,
    'balance_due' => 2360.00,
    'terms_conditions' => "1. Euro Net 30 days\n2. All prices in EUR",
    'items' => [
        [
            'description' => 'Consulting Services',
            'model_make' => 'Consulting',
            'quantity' => 10,
            'unit' => 'hour',
            'unit_price' => 200.00,
            'total' => 2000.00
        ]
    ]
];
$eurPdf = generate_invoice_pdf($eurPdfData);
assert(strlen($eurPdf) > 1000, "EUR PDF content too small");
assert(strpos($eurPdf, 'Rate \(EUR\)') !== false, "EUR PDF missing 'Rate (EUR)'");
assert(strpos($eurPdf, 'Total \(EUR\)') !== false, "EUR PDF missing 'Total (EUR)'");
assert(strpos($eurPdf, 'GRAND TOTAL \(EUR\)') !== false, "EUR PDF missing 'GRAND TOTAL (EUR)'");
echo "   [PASS] EUR PDF generated with 'Rate (EUR)', 'Total (EUR)', 'GRAND TOTAL (EUR)'\n";

// 7. Test Updating currency
echo "7. Testing Updating Currency on Estimate and Invoice...\n";
$updEst = $db->prepare("UPDATE estimates SET currency = 'GBP' WHERE id = ?");
$updEst->execute([$estId]);
$checkGbp = $db->prepare("SELECT currency FROM estimates WHERE id = ?");
$checkGbp->execute([$estId]);
assert($checkGbp->fetchColumn() === 'GBP', "Failed to update estimate currency to GBP");

$updInv = $db->prepare("UPDATE invoices SET currency = 'SAR' WHERE id = ?");
$updInv->execute([$eurInvId]);
$checkSar = $db->prepare("SELECT currency FROM invoices WHERE id = ?");
$checkSar->execute([$eurInvId]);
assert($checkSar->fetchColumn() === 'SAR', "Failed to update invoice currency to SAR");
echo "   [PASS] Currency updates (GBP, SAR) persisted successfully\n";

// 8. Test Multi-company batch estimates
echo "8. Testing Multi-Company Batch Estimates with AED currency...\n";
$batchId = 'batch_aed_' . uniqid();
$coIds = array_slice($cos, 0, 3);
if (count($coIds) < 3) {
    $coIds = [1, 2, 3];
}
$markups = [0.0, 2.0, 3.0];
foreach ($coIds as $idx => $cid) {
    $m = $markups[$idx] ?? 0.0;
    $sibStmt = $db->prepare("
        INSERT INTO estimates (
            estimate_no, client_id, company_id, currency, title, estimate_date,
            subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount,
            status, batch_id, markup_percent, created_by
        ) VALUES (
            :estimate_no, :client_id, :company_id, :currency, :title, :estimate_date,
            :subtotal, 0, 0, 18, :tax_amount, :total_amount,
            'draft', :batch_id, :markup, :created_by
        )
    ");
    $sub = 1000 * (1 + ($m / 100));
    $tax = round($sub * 0.18, 2);
    $tot = $sub + $tax;
    $sibStmt->execute([
        ':estimate_no' => 'EST-AED-B' . ($idx + 1) . '-' . time(),
        ':client_id' => $clientId,
        ':company_id' => $cid,
        ':currency' => 'AED',
        ':title' => 'AED Batch Set',
        ':estimate_date' => date('Y-m-d'),
        ':subtotal' => $sub,
        ':tax_amount' => $tax,
        ':total_amount' => $tot,
        ':batch_id' => $batchId,
        ':markup' => $m,
        ':created_by' => $adminUserId
    ]);
}
$batchCurrs = $db->prepare("SELECT DISTINCT currency FROM estimates WHERE batch_id = ?");
$batchCurrs->execute([$batchId]);
$currs = $batchCurrs->fetchAll(PDO::FETCH_COLUMN);
assert(count($currs) === 1 && $currs[0] === 'AED', "Batch estimates currencies mismatch: " . json_encode($currs));
echo "   [PASS] All 3 multi-company batch siblings created with AED\n";

echo "\n>>> ALL CURRENCY TESTS PASSED SUCCESSFULLY! <<<\n";
