<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/invoices.php';
require_once __DIR__ . '/../routes/estimates.php';

echo "=== Testing Duplicate Invoice to Estimate Conversion Prevention ===\n\n";

$db = database_connection();

// 1. Get or create admin user and token
$adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$adminUser) {
    $db->exec("INSERT INTO users (username, email, hashed_password, role, is_active) VALUES ('admin', 'admin@example.com', 'test', 'admin', 1)");
    $adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$token = createAccessToken(['sub' => $adminUser['username']], app_secret_key());

// 2. Create test client
$unique = time() . '_' . rand(100, 999);
$db->prepare("INSERT INTO clients (name, company, email, phone) VALUES ('Dup Client {$unique}', 'Dup Co', 'dup@example.com', '+923001112233')")->execute();
$clientId = (int)$db->lastInsertId();

// 3. Scenario A: Invoice converted to Estimate once -> second attempt MUST be blocked!
echo "--- Scenario A: Convert Invoice to Estimate, then attempt second conversion ---\n";
$invNoA = "INV-DUP-A-{$unique}";
$db->prepare("
    INSERT INTO invoices (invoice_no, client_id, invoice_date, status, subtotal, tax_rate, tax_amount, total_amount, balance_due, company_id)
    VALUES (:no, :cid, :dt, 'draft', 10000, 18, 1800, 11800, 11800, 1)
")->execute(['no' => $invNoA, 'cid' => $clientId, 'dt' => date('Y-m-d')]);
$invIdA = (int)$db->lastInsertId();

$db->prepare("
    INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price, tax_rate, total_price)
    VALUES (:iid, 'Item A', 1, 'pcs', 10000, 18, 10000)
")->execute(['iid' => $invIdA]);

function run_worker(string $code): string {
    $file = __DIR__ . '/_worker_' . uniqid() . '.php';
    file_put_contents($file, '<?php ' . $code);
    $out = (string)shell_exec('php ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);
    return $out;
}

// First convert attempt -> should succeed
$outA1 = run_worker('
    require "php/bootstrap.php";
    require "php/routes/invoices.php";
    $db = database_connection();
    $_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
    $_SERVER["REQUEST_METHOD"] = "POST";
    handle_invoices_routes("POST", "/api/invoices/' . $invIdA . '/convert", $db);
');
$resA1 = json_decode(trim($outA1), true);
if (empty($resA1) && preg_match('/\{"message".*\}/', $outA1, $mA1)) {
    $resA1 = json_decode($mA1[0], true);
}
assert(!empty($resA1['estimate_id']), "First convert must succeed: " . $outA1);
$estIdA = (int)$resA1['estimate_id'];
$estNoA = $resA1['estimate_no'];
echo "1. First conversion succeeded: Invoice {$invNoA} -> Estimate #{$estNoA} (ID: {$estIdA})\n";

// Second convert attempt -> MUST BE BLOCKED
$outA2 = run_worker('
    require "php/bootstrap.php";
    require "php/routes/invoices.php";
    $db = database_connection();
    $_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
    $_SERVER["REQUEST_METHOD"] = "POST";
    handle_invoices_routes("POST", "/api/invoices/' . $invIdA . '/convert", $db);
');
$resA2 = json_decode(trim($outA2), true);
if (empty($resA2) && preg_match('/\{"detail".*\}/', $outA2, $mA2)) {
    $resA2 = json_decode($mA2[0], true);
}
assert(!empty($resA2['estimate_already_created']), "Second convert must be blocked with estimate_already_created flag: " . $outA2);
assert(strpos($resA2['detail'], 'Estimate already created') !== false, "Error message must state Estimate already created");
assert(strpos($resA2['detail'], 'No duplicate estimate') !== false, "Error message must state No duplicate estimate");
echo "2. Second conversion correctly BLOCKED: {$resA2['detail']}\n";

// 4. Scenario B: Estimate created, converted to Invoice -> invoice cannot convert to another estimate
echo "\n--- Scenario B: Estimate converted to Invoice, attempt invoice -> estimate ---\n";
$estNoB = "EST-ORIG-B-{$unique}";
$db->prepare("
    INSERT INTO estimates (estimate_no, client_id, company_id, currency, title, estimate_date, valid_until, subtotal, tax_rate, tax_amount, total_amount, status)
    VALUES (:no, :cid, 1, 'PKR', 'Orig Quote B', :dt, :dt, 20000, 18, 3600, 23600, 'draft')
")->execute(['no' => $estNoB, 'cid' => $clientId, 'dt' => date('Y-m-d')]);
$estIdB = (int)$db->lastInsertId();

$db->prepare("
    INSERT INTO estimate_items (estimate_id, description, quantity, unit, unit_price, tax_rate, total_price)
    VALUES (:eid, 'Item B', 1, 'pcs', 20000, 18, 20000)
")->execute(['eid' => $estIdB]);

// Convert Estimate to Invoice
$outB1 = run_worker('
    require "php/bootstrap.php";
    require "php/routes/estimates.php";
    $db = database_connection();
    $_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
    $_SERVER["REQUEST_METHOD"] = "POST";
    handle_estimates_routes("POST", "/api/estimates/' . $estIdB . '/convert", $db);
');
$resB1 = json_decode(trim($outB1), true);
if (empty($resB1) && preg_match('/\{"message".*\}/', $outB1, $mB1)) {
    $resB1 = json_decode($mB1[0], true);
}
assert(!empty($resB1['invoice_id']), "Estimate to Invoice conversion must succeed: " . $outB1);
$invIdB = (int)$resB1['invoice_id'];
$invNoB = $resB1['invoice_no'];
echo "3. Estimate #{$estNoB} converted to Invoice #{$invNoB} (ID: {$invIdB})\n";

// Now attempt to convert this invoice to an estimate -> MUST BE BLOCKED
$outB2 = run_worker('
    require "php/bootstrap.php";
    require "php/routes/invoices.php";
    $db = database_connection();
    $_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
    $_SERVER["REQUEST_METHOD"] = "POST";
    handle_invoices_routes("POST", "/api/invoices/' . $invIdB . '/convert", $db);
');
$resB2 = json_decode(trim($outB2), true);
if (empty($resB2) && preg_match('/\{"detail".*\}/', $outB2, $mB2)) {
    $resB2 = json_decode($mB2[0], true);
}
assert(!empty($resB2['estimate_already_created']), "Converting invoice that originated from estimate must be blocked: " . $outB2);
assert((int)$resB2['estimate_id'] === $estIdB, "Blocked estimate_id must match original estimate");
assert(strpos($resB2['detail'], 'Estimate already created') !== false, "Error message must state Estimate already created");
echo "4. Invoice -> Estimate conversion correctly BLOCKED: {$resB2['detail']}\n";

// 5. Verify UI template protection
echo "\n--- Scenario C: UI Templates Verification ---\n";
$detailHtml = file_get_contents(__DIR__ . '/../templates/invoices/detail.html');
assert(strpos($detailHtml, 'currentInvoiceData.estimate_id') !== false, "detail.html must check currentInvoiceData.estimate_id");
assert(strpos($detailHtml, 'Estimate already created') !== false, "detail.html must alert 'Estimate already created'");
assert(strpos($detailHtml, 'No duplicate estimate should be converted') !== false, "detail.html must alert 'No duplicate estimate should be converted'");

$listHtml = file_get_contents(__DIR__ . '/../templates/invoices/list.html');
assert(strpos($listHtml, 'i.estimate_id') !== false, "list.html must check i.estimate_id");
assert(strpos($listHtml, 'Estimate already created') !== false, "list.html must alert 'Estimate already created'");
assert(strpos($listHtml, 'No duplicate estimate should be converted') !== false, "list.html must alert 'No duplicate estimate should be converted'");
echo "5. Both detail.html and list.html UI templates have pop up message and duplicate conversion prevention\n";

echo "\n>>> ALL DUPLICATE CONVERSION PREVENTION TESTS PASSED 100%! <<<\n";
