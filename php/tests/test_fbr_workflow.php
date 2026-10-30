<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/fbr_service.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=== Testing FBR Pakistan Digital Invoicing Workflow ===\n";

$db = database_connection();

// 1. Setup a test client
$clientStmt = $db->query('SELECT id FROM clients LIMIT 1');
$clientId = (int) $clientStmt->fetchColumn();
if (!$clientId) {
    $db->exec("INSERT INTO clients (name, company, email, phone, ntn, strn) VALUES ('Federal Testing Client', 'FBR Test Org', 'fbrtest@client.pk', '03001234567', '7654321-0', '12-34-5678-000-11')");
    $clientId = (int) $db->lastInsertId();
}

// 2. Create a test invoice in DRAFT status
$invNo = 'INV-FBR-' . time();
$db->exec("
    INSERT INTO invoices (
        invoice_no, client_id, invoice_date, due_date, status,
        subtotal, tax_rate, tax_amount, total_amount, balance_due,
        title, payment_terms, company_name, company_ntn, company_strn
    ) VALUES (
        '{$invNo}', {$clientId}, date('now'), date('now', '+15 days'), 'draft',
        50000.0, 18.0, 9000.0, 59000.0, 59000.0,
        'CCTV SURVEILLANCE & SECURITY SYSTEM', 'Net 30 Days', 'DATAPOINT Technologies', '7178396-5', '3277876124452'
    )
");
$invoiceId = (int) $db->lastInsertId();

// Add items
$db->exec("
    INSERT INTO invoice_items (invoice_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price, pct_code)
    VALUES ({$invoiceId}, 'Hikvision 4MP ColorVu Bullet Camera', 'DS-2CD1047G2-LU', 5, 'pcs', 10000.0, 18.0, 9000.0, 59000.0, '8528.59.00')
");

echo "1. Created Test Invoice #{$invNo} (ID {$invoiceId}) in 'draft' status.\n";

// 3. Attempt to post while DRAFT (must fail approval check)
$postDraftResult = post_invoice_to_fbr($db, $invoiceId);
echo "2. Attempt to post unapproved draft:\n";
echo "   Success: " . ($postDraftResult['success'] ? 'YES' : 'NO') . "\n";
echo "   Error: " . ($postDraftResult['error'] ?? '') . "\n";
if ($postDraftResult['success'] === false && !empty($postDraftResult['requires_approval'])) {
    echo "   -> PASS: Blocked unapproved draft as required by workflow!\n";
} else {
    echo "   -> FAIL: Draft was allowed to post without approval!\n";
    exit(1);
}

// 4. Manually Approve Invoice
echo "3. Approving invoice...\n";
$approveResult = approve_invoice($db, $invoiceId, 1);
echo "   Status after approval: " . $approveResult['status'] . "\n";
if ($approveResult['status'] === 'approved') {
    echo "   -> PASS: Invoice is approved and ready for FBR!\n";
} else {
    echo "   -> FAIL: Invoice status was not updated to approved!\n";
    exit(1);
}

// 4b. Pre-validate with FBR Sandbox Gateway
echo "3b. Pre-validating invoice with FBR Sandbox Gateway...\n";
$valResult = validate_invoice_with_fbr($db, $invoiceId);
echo "   Success: " . ($valResult['success'] ? 'YES' : 'NO') . "\n";
echo "   Status: " . ($valResult['status'] ?? '') . "\n";
if ($valResult['success'] && ($valResult['status'] === 'Valid' || ($valResult['statusCode'] ?? '') === '00')) {
    echo "   -> PASS: FBR Pre-validation check succeeded (Status: Valid)!\n";
} else {
    echo "   -> FAIL: FBR Pre-validation failed: " . ($valResult['error'] ?? '') . "\n";
    exit(1);
}

// 5. Post Approved Invoice to FBR Portal
echo "4. Posting approved invoice to FBR Pakistan Portal...\n";
$fbrResult = post_invoice_to_fbr($db, $invoiceId, 1);
echo "   Success: " . ($fbrResult['success'] ? 'YES' : 'NO') . "\n";
echo "   FBR Invoice No: " . ($fbrResult['fbr_invoice_no'] ?? '') . "\n";
echo "   Message: " . ($fbrResult['message'] ?? '') . "\n";

if ($fbrResult['success'] && !empty($fbrResult['fbr_invoice_no'])) {
    echo "   -> PASS: Official FBR Invoice Number generated successfully!\n";
} else {
    echo "   -> FAIL: Posting failed: " . ($fbrResult['error'] ?? '') . "\n";
    exit(1);
}

// 6. Verify Database State
$stmt = $db->prepare('SELECT status, fbr_status, fbr_invoice_no, fbr_posted_at, fbr_qr_code FROM invoices WHERE id = :id');
$stmt->execute(['id' => $invoiceId]);
$invRow = $stmt->fetch(PDO::FETCH_ASSOC);
echo "5. Database Verification:\n";
echo "   FBR Status: {$invRow['fbr_status']}\n";
echo "   FBR Invoice #: {$invRow['fbr_invoice_no']}\n";
echo "   Posted At: {$invRow['fbr_posted_at']}\n";
assert($invRow['fbr_status'] === 'posted');
assert(!empty($invRow['fbr_invoice_no']));

// 7. Test PDF Generation with FBR QR Code and Official Badge
echo "6. Generating Branded PDF with FBR Verification Badge...\n";
$stmt = $db->prepare('
    SELECT i.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE i.id = :id
');
$stmt->execute(['id' => $invoiceId]);
$invFull = $stmt->fetch(PDO::FETCH_ASSOC);
$itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
$itStmt->execute(['id' => $invoiceId]);
$invFull['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

$pdfData = generate_invoice_pdf($invFull);
echo "   PDF Generated: " . strlen($pdfData) . " bytes\n";
assert(str_contains($pdfData, $invRow['fbr_invoice_no']));
assert(str_contains($pdfData, 'FBR DIGITAL INVOICE'));
assert(str_contains($pdfData, 'SCAN TO VERIFY INVOICE'));

echo "\nALL FBR INTEGRATION WORKFLOW CHECKS PASSED 100%!\n";

