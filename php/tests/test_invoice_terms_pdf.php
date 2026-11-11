<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/invoices.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=== Testing Invoice Terms & Conditions in Database and PDF Print ===\n\n";

$db = database_connection();

// 1. Create a client for test
$stmt = $db->query('SELECT id FROM clients LIMIT 1');
$clientId = (int) $stmt->fetchColumn();
if ($clientId === 0) {
    $db->prepare("INSERT INTO clients (name, company, email, phone) VALUES ('Terms Test Client', 'Test Corp', 'terms@test.com', '03001234567')")->execute();
    $clientId = (int) $db->lastInsertId();
}

// 2. Test direct PDF generation with custom terms_conditions and notes
echo "[1] Testing PDF generation with explicit terms_conditions and notes...\n";
$invData = [
    'invoice_no' => 'INV-TEST-TERMS-001',
    'invoice_date' => '2026-11-11',
    'due_date' => '2026-12-11',
    'client_name' => 'Terms Test Client',
    'client_company' => 'Test Corp',
    'terms_conditions' => "1. Payment: 100% advance via cross cheque\n2. Standard 1-year replacement warranty on hardware\n3. Prices inclusive of 18% sales tax",
    'notes' => 'Deliver to Hyderabad warehouse during business hours',
    'payment_terms' => 'Advance Payment',
    'items' => [
        [
            'description' => 'Test Camera 4MP',
            'quantity' => 2,
            'unit_price' => 15000,
            'unit' => 'pcs',
        ]
    ]
];

$pdf = generate_invoice_pdf($invData);
if (strlen($pdf) < 500 || !str_starts_with($pdf, '%PDF')) {
    throw new RuntimeException("Invalid PDF generated!");
}

// Check that the terms and notes text appear in the generated PDF stream
if (!str_contains($pdf, 'cross cheque') || !str_contains($pdf, 'replacement warranty')) {
    throw new RuntimeException("Custom terms_conditions not found in generated PDF stream!");
}
if (!str_contains($pdf, 'Hyderabad warehouse')) {
    throw new RuntimeException("Notes not found in generated PDF stream!");
}
echo "✓ Custom terms & conditions and notes rendered successfully in PDF!\n\n";

// 3. Test PDF generation when terms_conditions is NULL or empty
echo "[2] Testing PDF generation fallback when terms_conditions is empty/null...\n";
$invEmptyTerms = [
    'invoice_no' => 'INV-TEST-TERMS-002',
    'invoice_date' => '2026-11-11',
    'due_date' => '2026-12-11',
    'client_name' => 'Terms Test Client',
    'client_company' => 'Test Corp',
    'terms_conditions' => null,
    'notes' => 'Special delivery instructions only',
    'items' => [
        [
            'description' => 'Test Switch 8 Port',
            'quantity' => 1,
            'unit_price' => 8000,
            'unit' => 'pcs',
        ]
    ]
];

$pdfEmpty = generate_invoice_pdf($invEmptyTerms);
if (!str_contains($pdfEmpty, 'Payment: Net 30 days') || !str_contains($pdfEmpty, 'Pakistan tax law')) {
    throw new RuntimeException("Fallback terms & conditions not rendered when terms_conditions is null!");
}
if (!str_contains($pdfEmpty, 'Special delivery instructions only')) {
    throw new RuntimeException("Notes were lost when terms_conditions was null!");
}
echo "✓ Fallback terms & conditions AND notes rendered together when terms_conditions is null!\n\n";

// 4. Test invoice creation via database and verification of terms_conditions column
echo "[3] Testing Invoice insert and fallback in database...\n";
$stmtCo = $db->query('SELECT * FROM companies WHERE is_default = 1 LIMIT 1');
$co = $stmtCo->fetch(PDO::FETCH_ASSOC);

$invNo = 'INV-UNIT-' . time();
$customTerms = "1. Goods once inspected cannot be returned\n2. 50% advance with PO\n3. Balance on completion";

// Insert with custom terms
$stmtIns = $db->prepare('
    INSERT INTO invoices (
        invoice_no, client_id, invoice_date, status, subtotal, total_amount, balance_due,
        terms_conditions, notes, payment_terms, company_id, company_name, created_at, updated_at
    ) VALUES (
        :no, :cid, CURRENT_DATE, "draft", 1000, 1000, 1000,
        :terms, :notes, "Net 30", :coid, :coname, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$stmtIns->execute([
    'no' => $invNo,
    'cid' => $clientId,
    'terms' => $customTerms,
    'notes' => 'Urgent site installation',
    'coid' => $co['id'] ?? 1,
    'coname' => $co['name'] ?? 'DATAPOINT Technologies',
]);
$savedId = (int) $db->lastInsertId();

$savedInv = $db->query("SELECT * FROM invoices WHERE id = {$savedId}")->fetch(PDO::FETCH_ASSOC);
if (trim((string)$savedInv['terms_conditions']) !== trim($customTerms)) {
    throw new RuntimeException("Stored terms_conditions does not match saved input!");
}
echo "✓ Invoice #{$savedId} saved with custom terms: '{$savedInv['terms_conditions']}'\n\n";

// Clean up test invoice
$db->prepare("DELETE FROM invoices WHERE id = {$savedId}")->execute();

echo "=== All Invoice Terms & Conditions Tests PASSED 100%! ===\n";
