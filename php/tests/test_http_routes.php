<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$cookieFile = tempnam(sys_get_temp_dir(), 'cookie_');

function test_request(string $url, ?array $post = null, bool $isJson = false): array {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isJson) {
            $body = json_encode($post);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($body)
            ]);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
    }
    $res = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $res];
}

echo "========================================================\n";
echo "  HTTP End-to-End Route Verification Test\n";
echo "========================================================\n\n";

echo "1. Testing Login (admin / admin123)...\n";
[$code, $html] = test_request('http://127.0.0.1:8000/login', [
    'username' => 'admin',
    'password' => 'admin123',
]);
echo "   Login HTTP Status: {$code}\n";
if (strpos($html, 'Dashboard') !== false || strpos($html, 'DATAPOINT') !== false) {
    echo "   -> [PASS] Session established and redirected to dashboard!\n";
} else {
    echo "   -> [WARN] Response does not contain expected dashboard text.\n";
}

$routes = [
    '/dashboard' => 'Dashboard',
    '/estimates' => 'Estimates',
    '/invoices' => 'Invoices',
    '/clients' => 'Clients',
    '/expenses' => 'Expenses',
    '/inventory' => 'Inventory',
    '/companies' => 'Companies',
    '/settings/companies' => 'Companies',
    '/reports' => 'Reports',
    '/api/companies' => 'DATAPOINT',
    '/api/clients' => '[',
    '/api/dashboard' => 'total_clients',
];

echo "\n2. Testing Authenticated Application Routes...\n";
$allPass = true;
foreach ($routes as $route => $expected) {
    [$code, $body] = test_request('http://127.0.0.1:8000' . $route);
    $pass = ($code === 200);
    if ($expected !== null && strpos($body, $expected) === false) {
        $pass = false;
    }
    echo sprintf("   Route %-22s -> HTTP %d %s\n", $route, $code, $pass ? '[PASS]' : '[FAIL]');
    if (!$pass) {
        $allPass = false;
    }
}

echo "\n3. Testing API Creation, Conversion and PDF Download...\n";
// Create estimate
$estPayload = [
    'client_id' => 1,
    'estimate_date' => date('Y-m-d'),
    'expiry_date' => date('Y-m-d', strtotime('+30 days')),
    'status' => 'draft',
    'notes' => 'Audited via HTTP Test Suite',
    'items' => [
        [
            'description' => 'Security Audit & Compliance Pack',
            'quantity' => 1,
            'unit_price' => 75000,
            'total_price' => 75000,
        ],
    ],
];

[$code, $resp] = test_request('http://127.0.0.1:8000/api/estimates', $estPayload, true);
$estData = json_decode($resp, true);
$estId = (int) ($estData['id'] ?? 0);
$batchId = (string) ($estData['batch_id'] ?? '');

if (in_array($code, [200, 201], true) && $estId > 0) {
    echo "   Created Estimate ID: {$estId} (HTTP {$code}) -> [PASS]\n";

    // Test Estimate PDF
    [$pdfCode, $pdfBytes] = test_request("http://127.0.0.1:8000/api/estimates/{$estId}/pdf");
    $isPdf = str_starts_with($pdfBytes, '%PDF');
    echo "   Estimate PDF Download -> HTTP {$pdfCode}, " . strlen($pdfBytes) . " bytes, Valid Header: " . ($isPdf ? '[PASS]' : '[FAIL]') . "\n";
    if ($pdfCode !== 200 || !$isPdf) $allPass = false;

    // Convert to Invoice
    [$convCode, $convResp] = test_request("http://127.0.0.1:8000/api/estimates/{$estId}/convert", [], true);
    $invData = json_decode($convResp, true);
    $invId = (int) ($invData['invoice_id'] ?? $invData['id'] ?? 0);
    if ($convCode === 200 && $invId > 0) {
        echo "   Converted to Invoice ID: {$invId} (HTTP {$convCode}) -> [PASS]\n";
        echo "   Invoice Total Amount: " . ($invData['total_amount'] ?? 0) . ", Balance Due: " . ($invData['balance_due'] ?? 0) . "\n";

        // Test Invoice PDF
        [$invPdfCode, $invPdfBytes] = test_request("http://127.0.0.1:8000/api/invoices/{$invId}/pdf");
        $isInvPdf = str_starts_with($invPdfBytes, '%PDF');
        $hasSalesTax = str_contains($invPdfBytes, 'SALES TAX INVOICE');
        echo "   Invoice PDF Download -> HTTP {$invPdfCode}, " . strlen($invPdfBytes) . " bytes, Valid Header: " . ($isInvPdf ? '[PASS]' : '[FAIL]') . "\n";
        echo "   Invoice PDF Contains 'SALES TAX INVOICE': " . ($hasSalesTax ? '[PASS]' : '[FAIL]') . "\n";
        if ($invPdfCode !== 200 || !$isInvPdf || !$hasSalesTax) $allPass = false;

        // Test Recording Partial Payment
        [$p1Code, $p1Resp] = test_request("http://127.0.0.1:8000/api/invoices/{$invId}/payments", [
            'amount' => 30000,
            'payment_method' => 'Bank Transfer',
            'notes' => '1st Installment'
        ], true);
        $p1Data = json_decode($p1Resp, true);
        $p1Ok = ($p1Code === 200 && ($p1Data['status'] ?? '') === 'partially_paid' && (float)($p1Data['amount_paid'] ?? 0) === 30000.0);
        echo "   1st Payment (PKR 30,000) -> HTTP {$p1Code}, Status: " . ($p1Data['status'] ?? 'unknown') . ", Bal Due: " . ($p1Data['balance_due'] ?? 0) . " " . ($p1Ok ? '[PASS]' : '[FAIL]') . "\n";
        if (!$p1Ok) $allPass = false;

        // Test Recording Full Balance Payment
        [$p2Code, $p2Resp] = test_request("http://127.0.0.1:8000/api/invoices/{$invId}/pay", [
            'amount' => 57750,
            'payment_method' => 'Cheque',
            'notes' => 'Settlement'
        ], true);
        $p2Data = json_decode($p2Resp, true);
        $p2Ok = ($p2Code === 200 && ($p2Data['status'] ?? '') === 'paid' && (float)($p2Data['balance_due'] ?? -1) === 0.0);
        echo "   2nd Payment (PKR 57,750) -> HTTP {$p2Code}, Status: " . ($p2Data['status'] ?? 'unknown') . ", Bal Due: " . ($p2Data['balance_due'] ?? -1) . " " . ($p2Ok ? '[PASS]' : '[FAIL]') . "\n";
        if (!$p2Ok) $allPass = false;

        // Cleanup test invoice
        $db = database_connection();
        $db->prepare("DELETE FROM payments WHERE invoice_id = :id")->execute([':id' => $invId]);
        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = :id")->execute([':id' => $invId]);
        $db->prepare("DELETE FROM invoices WHERE id = :id")->execute([':id' => $invId]);
    } else {
        echo "   [FAIL] Could not convert estimate to invoice (HTTP {$convCode}): {$convResp}\n";
        $allPass = false;
    }

    // Cleanup test estimate & sibling batch estimates
    $db = database_connection();
    if ($batchId !== '') {
        $db->prepare("DELETE FROM estimate_items WHERE estimate_id IN (SELECT id FROM estimates WHERE batch_id = :b)")->execute([':b' => $batchId]);
        $db->prepare("DELETE FROM estimates WHERE batch_id = :b")->execute([':b' => $batchId]);
    } else {
        $db->prepare("DELETE FROM estimate_items WHERE estimate_id = :id")->execute([':id' => $estId]);
        $db->prepare("DELETE FROM estimates WHERE id = :id")->execute([':id' => $estId]);
    }
} else {
    echo "   [FAIL] Could not create estimate (HTTP {$code}): {$resp}\n";
    $allPass = false;
}

@unlink($cookieFile);

echo "\n========================================================\n";
if ($allPass) {
    echo "  ALL APPLICATION HTTP ROUTES RETURNED 200 OK! [PASS]\n";
} else {
    echo "  SOME ROUTES FAILED AUDIT!\n";
}
echo "========================================================\n";
