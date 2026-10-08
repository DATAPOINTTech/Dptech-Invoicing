<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

$db = database_connection();

// 1. Ensure user and client exist
$user = $db->query("SELECT * FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "No user found in DB\n";
    exit(1);
}

$client = $db->query("SELECT * FROM clients LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    $db->exec("INSERT INTO clients (name, company, email, phone, address, created_at, updated_at) VALUES ('Sindh University', 'UoS Jamshoro', 'cctv@usindh.edu.pk', '022-9213181', 'Jamshoro Campus', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
    $clientId = (int)$db->lastInsertId();
} else {
    $clientId = (int)$client['id'];
}

// 2. Create Estimate with title and model_make
$estNo = 'EST-TEST-' . time();
$title = 'ACTIVE COMPONENTS-ZONE-1';
$taxRate = 18.0;

$items = [
    [
        'description' => "Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera",
        'model_make' => "Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera",
        'unit' => 'No.',
        'quantity' => 5,
        'unit_price' => 22000.00,
        'total_price' => 110000.00,
    ],
    [
        'description' => '64-Channel NVR AcuSense',
        'model_make' => 'Hikvision DS-7764NXI-M4',
        'unit' => 'No.',
        'quantity' => 1,
        'unit_price' => 150000.00,
        'total_price' => 150000.00,
    ],
];

$subtotal = 260000.00;
$taxAmt = 46800.00;
$total = 306800.00;

$stmt = $db->prepare("
    INSERT INTO estimates (
        estimate_no, client_id, title, estimate_date, valid_until, status,
        subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount,
        terms_conditions, notes, created_by, company_name, company_ntn, company_strn, created_at, updated_at
    ) VALUES (
        :no, :cid, :title, :edate, :vdate, 'draft',
        :subtotal, 0, 0, :tr, :ta, :total,
        'Terms valid for 15 days', 'Testing notes', :uid, 'DATAPOINT Technologies', '7178396-5', '3277876124452', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
");
$stmt->execute([
    'no' => $estNo,
    'cid' => $clientId,
    'title' => $title,
    'edate' => '2026-06-04',
    'vdate' => '2026-06-19',
    'subtotal' => $subtotal,
    'tr' => $taxRate,
    'ta' => $taxAmt,
    'total' => $total,
    'uid' => $user['id'],
]);
$estId = (int)$db->lastInsertId();

$itStmt = $db->prepare("
    INSERT INTO estimate_items (
        estimate_id, description, model_make, unit, quantity, unit_price, tax_rate, tax_amount, total_price
    ) VALUES (
        :eid, :desc, :mm, :unit, :qty, :price, :tr, :ta, :tp
    )
");
foreach ($items as $it) {
    $itStmt->execute([
        'eid' => $estId,
        'desc' => $it['description'],
        'mm' => $it['model_make'],
        'unit' => $it['unit'],
        'qty' => $it['quantity'],
        'price' => $it['unit_price'],
        'tr' => $taxRate,
        'ta' => $it['total_price'] * ($taxRate / 100.0),
        'tp' => $it['total_price'],
    ]);
}

echo "Created estimate #$estId ($estNo)\n";

// 3. Test Estimate PDF generation
$estRecord = $db->query("SELECT * FROM estimates WHERE id = $estId")->fetch(PDO::FETCH_ASSOC);
$estItems = $db->query("SELECT * FROM estimate_items WHERE estimate_id = $estId")->fetchAll(PDO::FETCH_ASSOC);
$estRecord['items'] = $estItems;

$estPdf = generate_estimate_pdf($estRecord);
echo "Generated Estimate PDF: " . strlen($estPdf) . " bytes\n";
if (!str_contains($estPdf, 'QUOTATION') || !str_contains($estPdf, 'Hikvision DS-7764NXI-M4')) {
    echo "FAILED: Estimate PDF missing key fields\n";
    exit(1);
}

// 4. Convert Estimate to Invoice via conversion logic in routes/estimates.php
$taxRate = (float) ($estRecord['tax_rate'] ?? 17.0);
$discPct = (float) ($estRecord['discount_percent'] ?? 0.0);
$nextInvId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
$invNo = sprintf('INV-%s-%05d', date('Ym'), $nextInvId);
$today = date('Y-m-d');
$dueDate = !empty($estRecord['valid_until']) ? (string) $estRecord['valid_until'] : $today;

$insInv = $db->prepare('
    INSERT INTO invoices (
        invoice_no, client_id, estimate_id, project_id, title, invoice_date, due_date,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
        total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
        company_id, company_name, company_logo, company_phone, company_email,
        company_address, company_ntn, company_strn,
        created_by, created_at, updated_at
    ) VALUES (
        :no, :cid, :eid, :pid, :title, :idate, :ddate,
        :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
        0, 0, 0, 0,
        :total, 0, :total, :pterms, :notes, :terms,
        :coid, :coname, :cologo, :cophone, :coemail,
        :coaddr, :conntn, :costrn,
        :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$insInv->execute([
    'no' => $invNo,
    'cid' => $estRecord['client_id'],
    'eid' => $estId,
    'pid' => $estRecord['project_id'] ?? null,
    'title' => $estRecord['title'] ?? null,
    'idate' => $today,
    'ddate' => $dueDate,
    'status' => 'draft',
    'subtotal' => $estRecord['subtotal'],
    'disc_pct' => $discPct,
    'disc_amt' => $estRecord['discount_amount'],
    'tr' => $taxRate,
    'ta' => $estRecord['tax_amount'],
    'total' => $estRecord['total_amount'],
    'pterms' => $estRecord['terms_conditions'] ?? null,
    'notes' => $estRecord['notes'] ?? null,
    'terms' => $estRecord['terms_conditions'] ?? null,
    'coid' => $estRecord['company_id'] ?? null,
    'coname' => $estRecord['company_name'] ?? null,
    'cologo' => $estRecord['company_logo'] ?? null,
    'cophone' => $estRecord['company_phone'] ?? null,
    'coemail' => $estRecord['company_email'] ?? null,
    'coaddr' => $estRecord['company_address'] ?? null,
    'conntn' => $estRecord['company_ntn'] ?? null,
    'costrn' => $estRecord['company_strn'] ?? null,
    'uid' => $user['id'],
]);
$invoiceId = (int) $db->lastInsertId();

$insItem = $db->prepare('
    INSERT INTO invoice_items (
        invoice_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
    ) VALUES (
        :iid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
    )
');
foreach ($estItems as $ei) {
    $insItem->execute([
        'iid' => $invoiceId,
        'prid' => $ei['product_id'] ?? null,
        'desc' => $ei['description'],
        'mm' => $ei['model_make'] ?? null,
        'qty' => $ei['quantity'],
        'unit' => $ei['unit'] ?? 'pcs',
        'price' => $ei['unit_price'],
        'tr' => $ei['tax_rate'] ?? $taxRate,
        'ta' => $ei['tax_amount'] ?? 0,
        'tp' => $ei['total_price'] ?? 0,
    ]);
}

$db->prepare("UPDATE estimates SET status = 'converted' WHERE id = :id")->execute(['id' => $estId]);

echo "Successfully converted estimate #$estId to Invoice #$invoiceId ($invNo)\n";

// 5. Verify Invoice details in database
$invRecord = $db->query("SELECT * FROM invoices WHERE id = $invoiceId")->fetch(PDO::FETCH_ASSOC);
$invItems = $db->query("SELECT * FROM invoice_items WHERE invoice_id = $invoiceId")->fetchAll(PDO::FETCH_ASSOC);
$invRecord['items'] = $invItems;

echo "Invoice Title: " . $invRecord['title'] . "\n";
echo "Invoice Total Amount: " . $invRecord['total_amount'] . "\n";
echo "Invoice Items Count: " . count($invItems) . "\n";
foreach ($invItems as $idx => $ii) {
    echo " - Item " . ($idx+1) . ": " . $ii['description'] . " | Model: " . $ii['model_make'] . "\n";
}

if ($invRecord['title'] !== 'ACTIVE COMPONENTS-ZONE-1') {
    echo "FAILED: Title not preserved in invoice!\n";
    exit(1);
}
if (empty($invItems[0]['model_make'])) {
    echo "FAILED: model_make not preserved in invoice items!\n";
    exit(1);
}

// 6. Generate Invoice PDF
$invPdf = generate_invoice_pdf($invRecord);
echo "Generated Invoice PDF: " . strlen($invPdf) . " bytes\n";
if (!str_contains($invPdf, 'SALES TAX INVOICE') || !str_contains($invPdf, 'Hikvision 1047G2H-LIU 4 MP')) {
    echo "FAILED: Invoice PDF missing title or model_make\n";
    exit(1);
}

// 7. Receive Payment on the converted Invoice
$payAmount = 150000.00;
$stmtPay = $db->prepare("
    INSERT INTO payments (
        invoice_id, amount, payment_date, payment_method, reference_no, notes, created_by, created_at
    ) VALUES (
        :iid, :amt, CURRENT_DATE, 'bank_transfer', 'TXN-998877', 'Advance partial payment', :uid, CURRENT_TIMESTAMP
    )
");
$stmtPay->execute([
    'iid' => $invoiceId,
    'amt' => $payAmount,
    'uid' => $user['id'],
]);

$newPaid = (float)$invRecord['amount_paid'] + $payAmount;
$newBal = (float)$invRecord['total_amount'] - $newPaid;
$newStatus = ($newBal <= 0) ? 'paid' : 'partially_paid';

$db->prepare("UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st WHERE id = :id")->execute([
    'paid' => $newPaid,
    'bal' => $newBal,
    'st' => $newStatus,
    'id' => $invoiceId,
]);

$updatedInv = $db->query("SELECT * FROM invoices WHERE id = $invoiceId")->fetch(PDO::FETCH_ASSOC);
echo "Payment recorded: Paid PKR $payAmount, Balance Due PKR " . $updatedInv['balance_due'] . ", Status: " . $updatedInv['status'] . "\n";

if ($updatedInv['status'] !== 'partially_paid' || (float)$updatedInv['balance_due'] !== (306800.00 - 150000.00)) {
    echo "FAILED: Payment balance calculation incorrect!\n";
    exit(1);
}

echo "\nALL WORKFLOW CHECKS PASSED PERFECTLY!\n";
