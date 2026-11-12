<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/invoices.php';

echo "=== Testing Invoice Payment Adjustment & Voiding ===\n\n";

$db = database_connection();

// 1. Create Test Client
$unique = time() . '_' . rand(100, 999);
$stmt = $db->prepare('INSERT INTO clients (name, company, email, phone, address, created_at, updated_at) VALUES (:n, :c, :e, :p, :a, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
$stmt->execute([
    'n' => "Client AdjustPay {$unique}",
    'c' => "Company AdjustPay {$unique}",
    'e' => "adjust_{$unique}@example.com",
    'p' => '+92-300-9876543',
    'a' => 'Main Boulevard, Gulberg, Lahore'
]);
$clientId = (int) $db->lastInsertId();

// 2. Create Test Invoice
$invNo = 'INV-ADJ-' . $unique;
$totalAmount = 100000.0;
$insInv = $db->prepare('
    INSERT INTO invoices (
        invoice_no, client_id, title, invoice_date, due_date,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, amount_paid, balance_due, company_id,
        created_at, updated_at
    ) VALUES (
        :no, :cid, :title, :idate, :ddate,
        :status, :subtotal, 0, 0, 0, 0,
        :total, 0, :total, 1,
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$insInv->execute([
    'no' => $invNo,
    'cid' => $clientId,
    'title' => "Payment Adjustment Test Invoice {$unique}",
    'idate' => date('Y-m-d'),
    'ddate' => date('Y-m-d', strtotime('+15 days')),
    'status' => 'approved',
    'subtotal' => $totalAmount,
    'total' => $totalAmount,
]);
$invoiceId = (int) $db->lastInsertId();

echo "1. Created Test Invoice #{$invNo} (ID: {$invoiceId}) with total PKR 100,000.\n";

// Helper to run route via subprocess
$adminUser = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$token = createAccessToken(['sub' => $adminUser['username']], app_secret_key());

function call_invoice_api(string $method, string $path, array $data = []): array {
    global $token;
    $workerCode = '
    require_once "php/bootstrap.php";
    require_once "php/routes/invoices.php";
    $db = database_connection();
    $_SERVER["HTTP_AUTHORIZATION"] = "Bearer ' . $token . '";
    $_SERVER["REQUEST_METHOD"] = "' . $method . '";
    $_SERVER["CONTENT_TYPE"] = "application/json";
    $GLOBALS["_TEST_INPUT_DATA"] = ' . var_export($data, true) . ';
    $GLOBALS["_TEST_INPUT_RAW"] = ' . var_export(json_encode($data), true) . ';
    
    // Call route handler
    handle_invoices_routes("' . $method . '", "' . $path . '", $db);
    ';

    $workerFile = __DIR__ . '/_worker_adj_' . rand(100,999) . '.php';
    file_put_contents($workerFile, '<?php ' . $workerCode);

    $output = shell_exec('php ' . escapeshellarg($workerFile));
    @unlink($workerFile);

    $res = json_decode(trim((string)$output), true);
    if (empty($res) && preg_match('/\{"message".*\}/', (string)$output, $m)) {
        $res = json_decode($m[0], true);
    }
    return $res ?: ['raw' => $output];
}

// 3. Record Payment 1 (PKR 30,000)
$res1 = call_invoice_api('POST', "/api/invoices/{$invoiceId}/payments", [
    'amount' => 30000.0,
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'Cash',
    'reference_no' => 'REC-001',
    'notes' => 'First partial cash payment'
]);
assert(!empty($res1['payment_id']), "Payment 1 must be created: " . json_encode($res1));
$pay1Id = (int)$res1['payment_id'];
assert((float)$res1['amount_paid'] === 30000.0, "amount_paid must be 30,000");
assert((float)$res1['balance_due'] === 70000.0, "balance_due must be 70,000");
assert($res1['status'] === 'partially_paid', "status must be partially_paid");
echo "2. Recorded Payment 1 of PKR 30,000 -> Status: partially_paid, Balance: 70,000\n";

// 4. Record Payment 2 (PKR 70,000) -> Invoice becomes Paid in full
$res2 = call_invoice_api('POST', "/api/invoices/{$invoiceId}/payments", [
    'amount' => 70000.0,
    'payment_date' => date('Y-m-d'),
    'payment_method' => 'Bank Transfer',
    'reference_no' => 'HBL-998877',
    'notes' => 'Final balance settlement'
]);
assert(!empty($res2['payment_id']), "Payment 2 must be created: " . json_encode($res2));
$pay2Id = (int)$res2['payment_id'];
assert((float)$res2['amount_paid'] === 100000.0, "amount_paid must be 100,000");
assert((float)$res2['balance_due'] === 0.0, "balance_due must be 0");
assert($res2['status'] === 'paid', "status must be paid");
echo "3. Recorded Payment 2 of PKR 70,000 -> Status: paid, Balance: 0\n";

// 5. ADJUST Payment 2: Change amount from PKR 70,000 down to PKR 40,000
$resAdj = call_invoice_api('PUT', "/api/invoices/{$invoiceId}/payments/{$pay2Id}", [
    'amount' => 40000.0,
    'payment_date' => '2026-11-10',
    'payment_method' => 'Cheque',
    'reference_no' => 'CHQ-123456',
    'notes' => 'Adjusted: Cheque cleared for partial PKR 40,000 only'
]);
assert(!empty($resAdj['message']), "Payment adjustment must succeed: " . json_encode($resAdj));
assert((float)$resAdj['amount'] === 40000.0, "adjusted amount must be 40,000");
assert((float)$resAdj['amount_paid'] === 70000.0, "new amount_paid must be 70,000 (30,000 + 40,000)");
assert((float)$resAdj['balance_due'] === 30000.0, "new balance_due must be 30,000 (100,000 - 70,000)");
assert($resAdj['status'] === 'partially_paid', "invoice status must revert back to partially_paid");

// Verify in DB directly
$pay2Db = $db->query("SELECT * FROM payments WHERE id = {$pay2Id}")->fetch(PDO::FETCH_ASSOC);
assert((float)$pay2Db['amount'] === 40000.0, "DB payment amount must be 40,000");
assert($pay2Db['payment_method'] === 'Cheque', "DB payment method must be Cheque");
assert($pay2Db['reference_no'] === 'CHQ-123456', "DB reference must be CHQ-123456");

$invDb = $db->query("SELECT amount_paid, balance_due, status FROM invoices WHERE id = {$invoiceId}")->fetch(PDO::FETCH_ASSOC);
assert((float)$invDb['amount_paid'] === 70000.0, "DB invoice amount_paid must be 70,000");
assert((float)$invDb['balance_due'] === 30000.0, "DB invoice balance_due must be 30,000");
assert($invDb['status'] === 'partially_paid', "DB invoice status must be partially_paid");
echo "4. Adjusted Payment 2 from 70,000 to 40,000 -> Verified DB: Paid: 70,000, Balance: 30,000, Status: partially_paid\n";

// 6. DELETE Payment 1 (PKR 30,000)
$resDel = call_invoice_api('DELETE', "/api/invoices/{$invoiceId}/payments/{$pay1Id}");
assert(!empty($resDel['message']), "Payment deletion must succeed: " . json_encode($resDel));
assert((float)$resDel['amount_paid'] === 40000.0, "amount_paid after deleting pay1 must be 40,000");
assert((float)$resDel['balance_due'] === 60000.0, "balance_due after deleting pay1 must be 60,000");

$pay1Check = $db->query("SELECT COUNT(*) FROM payments WHERE id = {$pay1Id}")->fetchColumn();
assert((int)$pay1Check === 0, "Payment 1 must be deleted from DB");

$invDb2 = $db->query("SELECT amount_paid, balance_due, status FROM invoices WHERE id = {$invoiceId}")->fetch(PDO::FETCH_ASSOC);
assert((float)$invDb2['amount_paid'] === 40000.0, "DB invoice amount_paid must be 40,000");
assert((float)$invDb2['balance_due'] === 60000.0, "DB invoice balance_due must be 60,000");
echo "5. Deleted Payment 1 -> Verified DB: Payment 1 removed, Paid: 40,000, Balance: 60,000\n";

// 7. DELETE Remaining Payment 2 (PKR 40,000) -> Invoice reverts to unpaid
$resDel2 = call_invoice_api('DELETE', "/api/invoices/{$invoiceId}/payments/{$pay2Id}");
assert((float)$resDel2['amount_paid'] === 0.0, "amount_paid must be 0 after deleting all payments");
assert((float)$resDel2['balance_due'] === 100000.0, "balance_due must be 100,000");
assert($resDel2['status'] === 'approved', "invoice status must revert to approved");
echo "6. Deleted Payment 2 -> Verified DB: All payments 0, Paid: 0, Balance: 100,000, Status: approved\n";

// 8. Verify UI templates for Adjust Payment components
$detailHtml = file_get_contents(__DIR__ . '/../templates/invoices/detail.html');
assert(strpos($detailHtml, 'adjust-payment-modal') !== false, "detail.html must contain adjust-payment-modal");
assert(strpos($detailHtml, 'btn-adjust-payment') !== false, "detail.html must contain btn-adjust-payment");
assert(strpos($detailHtml, 'btn-delete-payment') !== false, "detail.html must contain btn-delete-payment");
assert(strpos($detailHtml, 'openAdjustPaymentModal') !== false, "detail.html must contain openAdjustPaymentModal");
echo "7. Verified Web UI Template (adjust-payment-modal, adjust button, delete button, and script handlers verified)\n";

// Cleanup test invoice & client
$db->exec("DELETE FROM payments WHERE invoice_id = {$invoiceId}");
$db->exec("DELETE FROM invoices WHERE id = {$invoiceId}");
$db->exec("DELETE FROM clients WHERE id = {$clientId}");
echo "8. Test records cleaned up.\n";

echo "\n>>> ALL INVOICE PAYMENT ADJUSTMENT TESTS PASSED 100%! <<<\n";
