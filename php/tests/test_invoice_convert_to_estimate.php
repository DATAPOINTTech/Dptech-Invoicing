<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

echo "=== Testing Invoice Convert to Estimate ===\n\n";

$db = database_connection();

// 1. Create Test Client
$unique = time() . '_' . rand(100, 999);
$stmt = $db->prepare('INSERT INTO clients (name, company, email, phone, address, created_at, updated_at) VALUES (:n, :c, :e, :p, :a, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
$stmt->execute([
    'n' => "Client InvConv {$unique}",
    'c' => "Company InvConv {$unique}",
    'e' => "client_{$unique}@example.com",
    'p' => '+92-300-1234567',
    'a' => '123 Main Blvd, Clifton, Karachi'
]);
$clientId = (int) $db->lastInsertId();

// 2. Create Test Invoice with Items
$invNo = 'INV-TEST-CONV-' . $unique;
$subtotal = 100000.0;
$taxRate = 18.0;
$taxAmount = 18000.0;
$totalAmount = 118000.0;

$insInv = $db->prepare('
    INSERT INTO invoices (
        invoice_no, client_id, title, invoice_date, due_date,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
        company_id, company_name, company_phone, company_email, company_website, company_whatsapp,
        created_at, updated_at
    ) VALUES (
        :no, :cid, :title, :idate, :ddate,
        :status, :subtotal, 0, 0, :tr, :ta,
        :total, 0, :total, :pterms, :notes, :terms,
        1, :coname, :cophone, :coemail, :coweb, :cowa,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$insInv->execute([
    'no' => $invNo,
    'cid' => $clientId,
    'title' => "Hospital CCTV System {$unique}",
    'idate' => date('Y-m-d'),
    'ddate' => date('Y-m-d', strtotime('+15 days')),
    'status' => 'draft',
    'subtotal' => $subtotal,
    'tr' => $taxRate,
    'ta' => $taxAmount,
    'total' => $totalAmount,
    'pterms' => '50% Advance with PO, Balance on installation',
    'notes' => 'Includes 1-year on-site maintenance',
    'terms' => "1. Standard warranty applies\n2. Validity: 15 days",
    'coname' => 'DATAPOINT Technologies',
    'cophone' => '+92 22 3669326',
    'coemail' => 'sales@datapoinittechnology.com',
    'coweb' => 'https://datapointtechnology.com',
    'cowa' => '+923712566540',
]);
$invoiceId = (int) $db->lastInsertId();

$insItem = $db->prepare('
    INSERT INTO invoice_items (
        invoice_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
    ) VALUES (
        :iid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
    )
');
$insItem->execute([
    'iid' => $invoiceId,
    'desc' => 'Hikvision 4MP IP Dome Camera',
    'mm' => 'DS-2CD2143G2-I',
    'qty' => 5,
    'unit' => 'pcs',
    'price' => 12000.0,
    'tr' => 18.0,
    'ta' => 0.0,
    'tp' => 60000.0,
]);
$insItem->execute([
    'iid' => $invoiceId,
    'desc' => '16-Channel 4K Network Video Recorder (NVR)',
    'mm' => 'DS-7616NI-K2',
    'qty' => 1,
    'unit' => 'unit',
    'price' => 40000.0,
    'tr' => 18.0,
    'ta' => 0.0,
    'tp' => 40000.0,
]);

echo "1. Created Test Invoice #{$invNo} (ID: {$invoiceId}) with 2 items, total PKR {$totalAmount}\n";

// 3. Test convert route execution via PHP CLI child process
$adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    $db->exec("INSERT INTO users (username, email, hashed_password, role, is_active) VALUES ('admin', 'admin@example.com', 'test', 'admin', 1)");
    $adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$token = createAccessToken(['sub' => $adminUser['username']], app_secret_key());

function run_worker(string $code): string {
    $file = __DIR__ . '/_worker_' . uniqid() . '.php';
    file_put_contents($file, '<?php ' . $code);
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}

$output = run_worker('
require_once "php/bootstrap.php";
require_once "php/routes/invoices.php";
$db = database_connection();
$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
$_SERVER["REQUEST_METHOD"] = "POST";
handle_invoices_routes("POST", "/api/invoices/' . $invoiceId . '/convert", $db);
');

$res = json_decode(trim((string)$output), true);
if (empty($res) || empty($res['estimate_id'])) {
    // If output had warning headers or extra text, parse JSON from it
    if (preg_match('/\{"message".*\}/', (string)$output, $matches)) {
        $res = json_decode($matches[0], true);
    }
}

assert(!empty($res), "Response must be valid JSON: " . $output);
assert(!empty($res['estimate_id']), "Response must contain estimate_id");
assert(!empty($res['estimate_no']), "Response must contain estimate_no");

$estimateId = (int) $res['estimate_id'];
$estNo = $res['estimate_no'];
echo "2. Successfully executed POST /api/invoices/{$invoiceId}/convert -> Estimate #{$estNo} (ID: {$estimateId})\n";

// 4. Verify Database Records
$stmtEst = $db->prepare('SELECT * FROM estimates WHERE id = :id');
$stmtEst->execute(['id' => $estimateId]);
$est = $stmtEst->fetch(PDO::FETCH_ASSOC);
$stmtEst->closeCursor();
unset($stmtEst);

assert(!empty($est), "Created estimate must exist in database");
assert((int)$est['client_id'] === $clientId, "client_id must match invoice");
assert($est['title'] === "Hospital CCTV System {$unique}", "title must match invoice");
assert((float)$est['total_amount'] === $totalAmount, "total_amount must match invoice");
assert((float)$est['subtotal'] === $subtotal, "subtotal must match invoice");
assert((float)$est['tax_rate'] === $taxRate, "tax_rate must match invoice");
assert($est['status'] === 'draft', "Estimate status must be 'draft'");
assert($est['company_email'] === 'sales@datapoinittechnology.com', "company_email must be preserved");
assert($est['company_website'] === 'https://datapointtechnology.com', "company_website must be preserved");
assert($est['company_phone'] === '+92 22 3669326', "company_phone must be preserved");
assert($est['company_whatsapp'] === '+923712566540', "company_whatsapp must be preserved");

echo "3. Verified Estimate record in DB (client, title, amounts, contacts matched)\n";

// 5. Verify Estimate Items
$stmtItems = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id ORDER BY id ASC');
$stmtItems->execute(['id' => $estimateId]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
$stmtItems->closeCursor();
unset($stmtItems);

assert(count($items) === 2, "Estimate must have 2 items");
assert($items[0]['description'] === 'Hikvision 4MP IP Dome Camera', "First item description matched");
assert($items[0]['model_make'] === 'DS-2CD2143G2-I', "First item model matched");
assert((float)$items[0]['quantity'] === 5.0, "First item quantity matched");
assert((float)$items[0]['unit_price'] === 12000.0, "First item unit price matched");
assert((float)$items[0]['total_price'] === 60000.0, "First item total price matched");

assert($items[1]['description'] === '16-Channel 4K Network Video Recorder (NVR)', "Second item description matched");
assert((float)$items[1]['quantity'] === 1.0, "Second item quantity matched");
assert((float)$items[1]['total_price'] === 40000.0, "Second item total price matched");

echo "4. Verified Estimate Items in DB (all 2 items copied accurately)\n";

// 6. Test PDF Generation for converted estimate
$est['items'] = $items;
$pdf = generate_estimate_pdf($est);
assert(strlen($pdf) > 1000, "PDF must be generated for converted estimate");
assert(strpos($pdf, 'Hikvision 4MP IP Dome Camera') !== false, "PDF contains item description");
assert(strpos($pdf, 'sales@datapoinittechnology.com') !== false, "PDF contains official email");
assert(strpos($pdf, '+92 22 3669326') !== false, "PDF contains landline");

echo "5. Verified PDF generation for converted estimate (size: " . strlen($pdf) . " bytes)\n";

// 7. Check invoice linkage
$stmtInv = $db->prepare('SELECT estimate_id FROM invoices WHERE id = :id');
$stmtInv->execute(['id' => $invoiceId]);
$linkedEstId = (int) $stmtInv->fetchColumn();
$stmtInv->closeCursor();
unset($stmtInv);
assert($linkedEstId === $estimateId, "Invoice estimate_id must link to newly created estimate");

echo "6. Verified Invoice linkage (invoice.estimate_id = {$estimateId})\n";

// 7a. Test DUPLICATE prevention on same invoice
$outputDup = run_worker('
require_once "php/bootstrap.php";
require_once "php/routes/invoices.php";
$db = database_connection();
$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
$_SERVER["REQUEST_METHOD"] = "POST";
handle_invoices_routes("POST", "/api/invoices/' . $invoiceId . '/convert", $db);
');

$resDup = json_decode(trim((string)$outputDup), true);
if (empty($resDup) && preg_match('/\{"detail".*\}/', (string)$outputDup, $matchesDup)) {
    $resDup = json_decode($matchesDup[0], true);
}
assert(!empty($resDup), "Duplicate response must be valid JSON: " . $outputDup);
assert(!empty($resDup['estimate_already_created']), "Response must flag estimate_already_created = true");
assert(strpos($resDup['detail'], 'Estimate already created') !== false, "Error message must state 'Estimate already created'");
assert(strpos($resDup['detail'], 'No duplicate estimate') !== false, "Error message must state 'No duplicate estimate'");
echo "7. Verified duplicate prevention: Second convert attempt on Invoice #{$invNo} blocked with pop up error message\n";

// 7b. Test Estimate -> Invoice -> Estimate conversion prevention
// Create an estimate first
$origEstNo = 'EST-ORIG-' . $unique;
$insOrig = $db->prepare("
    INSERT INTO estimates (
        estimate_no, client_id, company_id, currency, title, estimate_date, valid_until,
        subtotal, discount_percent, discount_amount, tax_rate, tax_amount, total_amount,
        status, terms_conditions, notes, created_by
    ) VALUES (
        :no, :cid, 1, 'PKR', 'Orig Quote', :dt, :dt,
        50000, 0, 0, 18, 9000, 59000,
        'draft', 'terms', 'notes', 1
    )
");
$insOrig->execute(['no' => $origEstNo, 'cid' => $clientId, 'dt' => date('Y-m-d')]);
$origEstId = (int)$db->lastInsertId();
$insOrig->closeCursor();
unset($insOrig);

$insOrigItem = $db->prepare("
    INSERT INTO estimate_items (estimate_id, description, model_make, quantity, unit, unit_price, tax_rate, total_price)
    VALUES (:eid, 'Test Item', 'Model', 1, 'pcs', 50000, 18, 50000)
");
$insOrigItem->execute(['eid' => $origEstId]);
$insOrigItem->closeCursor();
unset($insOrigItem);

// Convert Estimate to Invoice
$outputE2I = run_worker('
require_once "php/bootstrap.php";
require_once "php/routes/estimates.php";
$db = database_connection();
$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
$_SERVER["REQUEST_METHOD"] = "POST";
handle_estimates_routes("POST", "/api/estimates/' . $origEstId . '/convert", $db);
');

$resE2I = json_decode(trim((string)$outputE2I), true);
if (empty($resE2I) && preg_match('/\{"message".*\}/', (string)$outputE2I, $mE2I)) {
    $resE2I = json_decode($mE2I[0], true);
}
assert(!empty($resE2I['invoice_id']), "Estimate must convert to invoice successfully: " . $outputE2I);
$e2iInvoiceId = (int)$resE2I['invoice_id'];

// Now attempt to convert this converted invoice back to an estimate
$outputBack = run_worker('
require_once "php/bootstrap.php";
require_once "php/routes/invoices.php";
$db = database_connection();
$_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
$_SERVER["REQUEST_METHOD"] = "POST";
handle_invoices_routes("POST", "/api/invoices/' . $e2iInvoiceId . '/convert", $db);
');

$resBack = json_decode(trim((string)$outputBack), true);
if (empty($resBack) && preg_match('/\{"detail".*\}/', (string)$outputBack, $mBack)) {
    $resBack = json_decode($mBack[0], true);
}
assert(!empty($resBack), "Response must be valid JSON: " . $outputBack);
assert(!empty($resBack['estimate_already_created']), "Converting converted invoice must flag estimate_already_created");
assert($resBack['estimate_id'] === $origEstId, "Blocked estimate_id must match original estimate");
echo "8. Verified Estimate -> Invoice -> Estimate conversion prevention: Invoice originating from Estimate #{$origEstNo} blocked from duplicate estimate conversion\n";

// 9. Verify UI templates for "Convert to Estimate" elements
$detailHtml = file_get_contents(__DIR__ . '/../templates/invoices/detail.html');
assert(strpos($detailHtml, 'convertInvoiceToEstimate') !== false, "invoices/detail.html must contain convertInvoiceToEstimate()");
assert(strpos($detailHtml, 'invoice-convert-estimate-btn') !== false, "invoices/detail.html must contain 'invoice-convert-estimate-btn' on the invoice card");
assert(strpos($detailHtml, 'header-convert-estimate-btn') === false, "invoices/detail.html must NOT contain 'header-convert-estimate-btn' on top of page");
assert(strpos($detailHtml, 'Estimate already created') !== false, "invoices/detail.html must check for 'Estimate already created'");
assert(strpos($detailHtml, 'No duplicate estimate should be converted') !== false, "invoices/detail.html must state 'No duplicate estimate should be converted'");

$listHtml = file_get_contents(__DIR__ . '/../templates/invoices/list.html');
assert(strpos($listHtml, 'convert-to-estimate') !== false, "invoices/list.html must contain convert-to-estimate button class");
assert(strpos($listHtml, 'Estimate already created') !== false, "invoices/list.html must check for 'Estimate already created'");
assert(strpos($listHtml, 'No duplicate estimate should be converted') !== false, "invoices/list.html must state 'No duplicate estimate should be converted'");

echo "9. Verified Web UI Templates (detail.html and list.html have duplicate pop up alert, button badges, and prevent duplicate conversion)\n";

echo "\n>>> ALL INVOICE TO ESTIMATE CONVERSION TESTS PASSED 100%! <<<\n";
