<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/invoice_import.php';

$db = database_connection();

$files = [
    'Invoice Template PDF' => __DIR__ . '/../../output/pdf/invoice_pdf_import_template.pdf',
    'Estimate Template PDF' => __DIR__ . '/../../output/pdf/estimate_pdf_import_template.pdf',
    'Invoice DOCX' => __DIR__ . '/../../output/pdf/sample_test_invoice.docx',
    'CCTV Invoice PDF' => __DIR__ . '/sample_test_invoice.pdf',
    'CCTV Estimate PDF' => __DIR__ . '/sample_test_estimate.pdf',
];

$allPassed = true;

foreach ($files as $label => $filePath) {
    echo "========================================\n";
    echo "Testing: $label ($filePath)\n";
    if (!file_exists($filePath)) {
        echo "SKIPPED: Optional test file not present\n";
        continue;
    }

    $content = file_get_contents($filePath);
    $filename = basename($filePath);

    // Test standard argument order
    try {
        $result = parse_invoice_file($filename, $content, $db);
        echo "SUCCESS (Standard args):\n";
        echo "  Client: " . ($result['client_name'] ?? 'NONE') . " (ID: " . ($result['client_id'] ?? 'null') . ")\n";
        echo "  Doc No: " . ($result['invoice_no'] ?? $result['estimate_no'] ?? 'NONE') . "\n";
        echo "  Date: " . ($result['invoice_date'] ?? $result['estimate_date'] ?? 'NONE') . "\n";
        echo "  Due/Valid: " . ($result['due_date'] ?? $result['valid_until'] ?? 'NONE') . "\n";
        echo "  Tax Rate: " . ($result['tax_rate'] !== null ? $result['tax_rate'] . '%' : 'NONE') . "\n";
        echo "  Title: " . ($result['title'] ?? 'NONE') . "\n";
        echo "  Items Count: " . count($result['items'] ?? []) . "\n";
        if (!empty($result['items'])) {
            foreach ($result['items'] as $idx => $it) {
                echo "    [$idx] {$it['description']} | Qty: {$it['quantity']} | Unit: {$it['unit']} | Rate: {$it['unit_price']}\n";
            }
        } else {
            echo "  WARNING: 0 items parsed\n";
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $allPassed = false;
    }

    // Test inverted argument order defense
    try {
        $resultInv = parse_invoice_file($content, $filename, $db);
        if (count($resultInv['items'] ?? []) !== count($result['items'] ?? [])) {
            echo "FAIL: Inverted arguments returned different item count\n";
            $allPassed = false;
        } else {
            echo "SUCCESS: Inverted arguments defensive check passed.\n";
        }
    } catch (Throwable $e) {
        echo "FAIL (Inverted args): " . $e->getMessage() . "\n";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "\n>>> ALL PARSER TESTS PASSED! <<<\n";
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
