<?php

// 1. Login to get token
$loginCh = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt_array($loginCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'admin123']),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);
$loginResp = curl_exec($loginCh);
$tokenData = json_decode($loginResp ?: '{}', true);
$token = $tokenData['token'] ?? $tokenData['access_token'] ?? null;
curl_close($loginCh);

if (!$token) {
    echo "Login failed: $loginResp\n";
    exit(1);
}
echo "Obtained auth token successfully.\n";

$tests = [
    [
        'url' => 'http://127.0.0.1:8000/api/invoices/import',
        'file' => __DIR__ . '/../../output/pdf/invoice_pdf_import_template.pdf',
        'desc' => 'Invoice Template PDF -> /api/invoices/import'
    ],
    [
        'url' => 'http://127.0.0.1:8000/api/invoices/import',
        'file' => __DIR__ . '/../../output/pdf/sample_test_invoice.docx',
        'desc' => 'Sample Invoice DOCX -> /api/invoices/import'
    ],
    [
        'url' => 'http://127.0.0.1:8000/api/estimates/import',
        'file' => __DIR__ . '/../../output/pdf/estimate_pdf_import_template.pdf',
        'desc' => 'Estimate Template PDF -> /api/estimates/import'
    ],
    [
        'url' => 'http://127.0.0.1:8000/api/invoices/import',
        'file' => __DIR__ . '/sample_test_invoice.pdf',
        'desc' => 'Sample CCTV Invoice PDF -> /api/invoices/import'
    ],
    [
        'url' => 'http://127.0.0.1:8000/api/estimates/import',
        'file' => __DIR__ . '/sample_test_estimate.pdf',
        'desc' => 'Sample CCTV Estimate PDF -> /api/estimates/import'
    ],
];

$allPassed = true;

foreach ($tests as $t) {
    echo "\nTesting: {$t['desc']}\n";
    if (!file_exists($t['file'])) {
        echo "SKIPPED: Optional test file not present\n";
        continue;
    }
    $cfile = new CURLFile($t['file'], mime_content_type($t['file']), basename($t['file']));
    $ch = curl_init($t['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"]
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Status: $httpCode\n";
    if ($httpCode !== 200) {
        echo "FAIL response: $resp\n";
        $allPassed = false;
        continue;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['items'])) {
        echo "FAIL: Invalid JSON or missing items\n";
        $allPassed = false;
        continue;
    }

    echo "Client: " . ($data['client_name'] ?? 'null') . "\n";
    echo "Date: " . ($data['invoice_date'] ?? $data['estimate_date'] ?? 'null') . "\n";
    echo "Items Count: " . count($data['items']) . "\n";
    echo "SUCCESS!\n";
}

if ($allPassed) {
    echo "\n>>> ALL HTTP IMPORT ENDPOINT TESTS PASSED! <<<\n";
} else {
    echo "\n>>> SOME HTTP TESTS FAILED! <<<\n";
    exit(1);
}
