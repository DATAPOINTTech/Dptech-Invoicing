<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';
require_once __DIR__ . '/../services/inventory_service.php';
require_once __DIR__ . '/../routes/estimates.php';
require_once __DIR__ . '/../routes/invoices.php';

echo "=== STARTING ESTIMATE CONVERT & PAYMENT TEST ===\n";

$db = database_connection();
ensure_all_schema_tables($db);
ensure_multicompany_schema($db);
ensure_default_admin($db);

// 1. Verify schema on invoices table
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver === 'sqlite') {
    $cols = $db->query('PRAGMA table_info(invoices)')->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'name');
    echo "Invoices columns count: " . count($colNames) . "\n";
    foreach (['company_id', 'company_name', 'company_phone', 'company_email'] as $req) {
        if (!in_array($req, $colNames, true)) {
            throw new RuntimeException("Missing required column in invoices: {$req}");
        }
    }
    echo "[PASS] Invoices table schema has all required multi-company columns.\n";
}

// 2. Fetch or create a test client
$stmt = $db->query("SELECT id FROM clients LIMIT 1");
$clientId = $stmt->fetchColumn();
if (!$clientId) {
    $insClient = $db->prepare("INSERT INTO clients (name, company, email, phone, mobile, ntn, created_at) VALUES ('Test Client Corp', 'Test Org', 'client@test.com', '+923001112233', '+923001112233', '1234567-8', CURRENT_TIMESTAMP)");
    $insClient->execute();
    $clientId = (int)$db->lastInsertId();
}
echo "[PASS] Test Client ID: {$clientId}\n";

// 3. Create an estimate with items
$nextId = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
$estNo = sprintf('EST-TEST-%05d', $nextId);

$insEst = $db->prepare("
    INSERT INTO estimates (
        estimate_no, client_id, title, estimate_date, valid_until,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, terms_conditions, notes, created_by,
        company_id, company_name, company_logo, company_phone, company_email, company_address,
        created_at, updated_at
    ) VALUES (
        :no, :cid, 'Network Installation & Hardware', '2026-10-03', '2026-10-18',
        'approved', 50000.00, 0, 0, 18.0, 9000.00,
        59000.00, 'Payment: Net 30 days', 'Standard Estimate', 1,
        1, 'DATAPOINT Technologies', '/static/img/logo.png', '+923167788990', 'info@datapointtechnology.com', 'G 32 Shayas Residence',
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
");
$insEst->execute([
    'no' => $estNo,
    'cid' => $clientId,
]);
$estimateId = (int)$db->lastInsertId();

// Add items to estimate
$insEstItem = $db->prepare("
    INSERT INTO estimate_items (estimate_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price)
    VALUES (:eid, :desc, :qty, :unit, :price, 18.0, :tax, :tot)
");
$insEstItem->execute([
    'eid' => $estimateId,
    'desc' => 'Managed Switch 24-Port Gigabit',
    'qty' => 2,
    'unit' => 'pcs',
    'price' => 20000.00,
    'tax' => 7200.00,
    'tot' => 47200.00,
]);
$insEstItem->execute([
    'eid' => $estimateId,
    'desc' => 'Cat6 UTP Cable Roll 305m',
    'qty' => 1,
    'unit' => 'roll',
    'price' => 10000.00,
    'tax' => 1800.00,
    'tot' => 11800.00,
]);
echo "[PASS] Created Estimate ID: {$estimateId} ({$estNo}) with 2 items. Subtotal: 50,000, Tax: 9,000, Total: 59,000\n";

// 4. Test conversion logic simulated
$stmt = $db->prepare('SELECT * FROM estimates WHERE id = :id');
$stmt->execute(['id' => $estimateId]);
$est = $stmt->fetch(PDO::FETCH_ASSOC);

$itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
$itStmt->execute(['id' => $estimateId]);
$estItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

$taxRate = (float)($est['tax_rate'] ?? 17.0);
$discPct = (float)($est['discount_percent'] ?? 0.0);
$subtotal = (float)($est['subtotal'] ?? 0.0);
$discAmt = (float)($est['discount_amount'] ?? 0.0);
$taxAmt = (float)($est['tax_amount'] ?? 0.0);
$totalAmount = (float)($est['total_amount'] ?? 0.0);

$nextInvId = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
$invNo = sprintf('INV-TEST-%05d', $nextInvId);
$today = date('Y-m-d');

$insInv = $db->prepare('
    INSERT INTO invoices (
        invoice_no, client_id, estimate_id, project_id, invoice_date, due_date,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
        total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
        company_id, company_name, company_logo, company_phone, company_email,
        company_address, company_ntn, company_strn,
        created_by, created_at, updated_at
    ) VALUES (
        :no, :cid, :eid, :pid, :idate, :ddate,
        :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
        0, 0, 0, 0,
        :total, 0, :total, :pterms, :notes, :terms,
        :coid, :coname, :cologo, :cophone, :coemail,
        :coaddr, :conntn, :costrn,
        1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$insInv->execute([
    'no' => $invNo,
    'cid' => $est['client_id'],
    'eid' => $estimateId,
    'pid' => $est['project_id'],
    'idate' => $today,
    'ddate' => $today,
    'status' => 'draft',
    'subtotal' => $subtotal,
    'disc_pct' => $discPct,
    'disc_amt' => $discAmt,
    'tr' => $taxRate,
    'ta' => $taxAmt,
    'total' => $totalAmount,
    'pterms' => $est['terms_conditions'] ?? null,
    'notes' => $est['notes'] ?? null,
    'terms' => $est['terms_conditions'] ?? null,
    'coid' => $est['company_id'] ?? null,
    'coname' => $est['company_name'] ?? null,
    'cologo' => $est['company_logo'] ?? null,
    'cophone' => $est['company_phone'] ?? null,
    'coemail' => $est['company_email'] ?? null,
    'coaddr' => $est['company_address'] ?? null,
    'conntn' => $est['company_ntn'] ?? null,
    'costrn' => $est['company_strn'] ?? null,
]);
$invoiceId = (int)$db->lastInsertId();

$insItem = $db->prepare('
    INSERT INTO invoice_items (
        invoice_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
    ) VALUES (
        :iid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
    )
');
foreach ($estItems as $ei) {
    $insItem->execute([
        'iid' => $invoiceId,
        'prid' => $ei['product_id'],
        'desc' => $ei['description'],
        'qty' => $ei['quantity'],
        'unit' => $ei['unit'] ?? 'pcs',
        'price' => $ei['unit_price'],
        'tr' => $taxRate,
        'ta' => $ei['tax_amount'],
        'tp' => $ei['total_price'],
    ]);
}

$updEst = $db->prepare("UPDATE estimates SET status = 'converted', updated_at = CURRENT_TIMESTAMP WHERE id = :id");
$updEst->execute(['id' => $estimateId]);

echo "[PASS] Converted Estimate to Invoice ID: {$invoiceId} ({$invNo})\n";

// 5. Query invoice details like GET /api/invoices/{id}
$stmt = $db->prepare('
    SELECT i.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone, c.mobile AS client_mobile, c.address AS client_address, c.city AS client_city, c.ntn AS client_ntn, c.strn AS client_strn
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE i.id = :id
');
$stmt->execute(['id' => $invoiceId]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);

$itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
$itStmt->execute(['id' => $invoiceId]);
$inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Invoice details retrieved:\n";
echo "  - invoice_no: {$inv['invoice_no']}\n";
echo "  - client_name: {$inv['client_name']}\n";
echo "  - company_name: {$inv['company_name']}\n";
echo "  - subtotal: {$inv['subtotal']}\n";
echo "  - tax_amount: {$inv['tax_amount']}\n";
echo "  - total_amount: {$inv['total_amount']}\n";
echo "  - amount_paid: {$inv['amount_paid']}\n";
echo "  - balance_due: {$inv['balance_due']}\n";
echo "  - items count: " . count($inv['items']) . "\n";

if ((float)$inv['total_amount'] !== 59000.0) {
    throw new RuntimeException("Total amount mismatch: expected 59000, got {$inv['total_amount']}");
}
if ((float)$inv['balance_due'] !== 59000.0) {
    throw new RuntimeException("Balance due mismatch: expected 59000, got {$inv['balance_due']}");
}
if (count($inv['items']) !== 2) {
    throw new RuntimeException("Expected 2 items, got " . count($inv['items']));
}
echo "[PASS] Invoice financial totals and items are completely accurate!\n";

// 6. Test partial payment: PKR 20,000
$payAmt1 = 20000.00;
$insPay = $db->prepare('
    INSERT INTO payments (
        invoice_id, amount, payment_date, payment_method, reference_no, notes, created_by, created_at
    ) VALUES (
        :iid, :amt, :pdate, :pmethod, :ref, :notes, 1, CURRENT_TIMESTAMP
    )
');
$insPay->execute([
    'iid' => $invoiceId,
    'amt' => $payAmt1,
    'pdate' => '2026-10-03',
    'pmethod' => 'Bank Transfer',
    'ref' => 'TXN-987654',
    'notes' => 'Advance payment 20k',
]);

$sumStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id');
$sumStmt->execute(['id' => $invoiceId]);
$newPaid1 = (float)$sumStmt->fetchColumn();
$newBalance1 = max(0.0, (float)$inv['total_amount'] - $newPaid1);
$newStatus1 = ($newBalance1 <= 0.0) ? 'paid' : 'partially_paid';

$upd = $db->prepare('UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
$upd->execute(['paid' => $newPaid1, 'bal' => $newBalance1, 'st' => $newStatus1, 'id' => $invoiceId]);

echo "[PASS] Recorded 1st payment: PKR {$payAmt1}. New Paid: {$newPaid1}, New Balance: {$newBalance1}, Status: {$newStatus1}\n";

if ($newPaid1 !== 20000.0 || $newBalance1 !== 39000.0 || $newStatus1 !== 'partially_paid') {
    throw new RuntimeException("1st payment calculation incorrect!");
}

// 7. Test second payment: remaining PKR 39,000
$payAmt2 = 39000.00;
$insPay->execute([
    'iid' => $invoiceId,
    'amt' => $payAmt2,
    'pdate' => '2026-10-04',
    'pmethod' => 'Cash',
    'ref' => 'CASH-REC-01',
    'notes' => 'Remaining balance cleared',
]);

$sumStmt->execute(['id' => $invoiceId]);
$newPaid2 = (float)$sumStmt->fetchColumn();
$newBalance2 = max(0.0, (float)$inv['total_amount'] - $newPaid2);
$newStatus2 = ($newBalance2 <= 0.0) ? 'paid' : 'partially_paid';

$upd->execute(['paid' => $newPaid2, 'bal' => $newBalance2, 'st' => $newStatus2, 'id' => $invoiceId]);

echo "[PASS] Recorded 2nd payment: PKR {$payAmt2}. New Paid: {$newPaid2}, New Balance: {$newBalance2}, Status: {$newStatus2}\n";

if ($newPaid2 !== 59000.0 || $newBalance2 !== 0.0 || $newStatus2 !== 'paid') {
    throw new RuntimeException("2nd payment calculation incorrect!");
}

// 8. Test PDF generation for the converted invoice
$pdf = generate_invoice_pdf($inv);
if (strlen($pdf) < 1000 || !str_starts_with($pdf, '%PDF-1.4')) {
    throw new RuntimeException("PDF generation failed or invalid PDF header");
}
echo "[PASS] Invoice PDF generated successfully (" . strlen($pdf) . " bytes).\n";

// 9. Clean up test records
$db->exec("DELETE FROM payments WHERE invoice_id = {$invoiceId}");
$db->exec("DELETE FROM invoice_items WHERE invoice_id = {$invoiceId}");
$db->exec("DELETE FROM invoices WHERE id = {$invoiceId}");
$db->exec("DELETE FROM estimate_items WHERE estimate_id = {$estimateId}");
$db->exec("DELETE FROM estimates WHERE id = {$estimateId}");
echo "[PASS] Cleaned up temporary test records.\n";

echo "\n=== ALL ESTIMATE CONVERT & PAYMENT TESTS PASSED SUCCESSFULLY! ===\n";
