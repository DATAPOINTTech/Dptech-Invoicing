<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/fbr_service.php';
require_once __DIR__ . '/../services/pdf_service.php';

$db = database_connection();

echo "=== 1. Testing fbr_resolve_qr_verification_url ===\n";
$fbrNo = '4130643057439DIQ1FM16059066';
$resolvedUrl = fbr_resolve_qr_verification_url($fbrNo);
echo "Resolved URL: " . $resolvedUrl . "\n";
assert(str_contains($resolvedUrl, 'https://e.fbr.gov.pk/esr/InvoiceVerification.aspx?InvoiceNo=' . $fbrNo), "Must match official FBR verification URL");

// Test idempotence when already a URL
$alreadyUrl = 'https://custom-verify.fbr.gov.pk/verify?no=123';
$resAlready = fbr_resolve_qr_verification_url($alreadyUrl);
assert($resAlready === $alreadyUrl, "Must preserve existing URL");
echo "URL Idempotence check PASSED\n";

echo "=== 2. Testing fbr_get_config with verify_url ===\n";
$config = fbr_get_config($db);
echo "Verify URL configured: " . ($config['verify_url'] ?? 'NONE') . "\n";
assert(!empty($config['verify_url']), "verify_url must be present in fbr_get_config");

echo "=== 3. Testing fbr_get_invoice_qr_target ===\n";
$dummyInv = [
    'id' => 99,
    'fbr_invoice_no' => '4130643057439DIQ1FM16059066',
    'fbr_qr_code' => '',
];
$target = fbr_get_invoice_qr_target($db, $dummyInv);
echo "QR target: " . $target . "\n";
assert(str_starts_with($target, 'https://e.fbr.gov.pk/esr/InvoiceVerification.aspx?InvoiceNo='), "Target must be FBR verification URL");

echo "=== 4. Testing PurePhpQr generation for Verification URL ===\n";
$svg = PurePhpQr::toSvg($target, 180);
$dataUri = PurePhpQr::toDataUri($target, 180);
assert(strlen($svg) > 1000, "SVG must be valid");
assert(str_starts_with($dataUri, 'data:image/svg+xml;base64,'), "Data URI must be valid");
echo "SVG length: " . strlen($svg) . " bytes\n";
echo "Data URI prefix: " . substr($dataUri, 0, 50) . "...\n";

echo "=== 5. Testing PDF generation with FBR QR Code pointing to verification URL ===\n";
$sampleInvoice = [
    'id' => 1,
    'invoice_no' => 'INV-2026-FBR-TEST',
    'invoice_date' => '2026-10-30',
    'due_date' => '2026-11-15',
    'status' => 'approved',
    'fbr_status' => 'posted',
    'fbr_invoice_no' => '4130643057439DIQ1FM16059066',
    'fbr_qr_code' => '', // Will be resolved dynamically
    'client_name' => 'Apex Solutions Pvt Ltd',
    'client_ntn' => '7178396-7',
    'client_strn' => '3277876123456',
    'client_address' => 'Clifton Block 5, Karachi',
    'tax_rate' => 18.0,
    'subtotal' => 100000.0,
    'tax_amount' => 18000.0,
    'total_amount' => 118000.0,
    'items' => [
        [
            'description' => 'Dell Latitude 5540 Core i7 16GB 512GB SSD',
            'model_make' => 'Dell Enterprise',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 100000.0,
            'pct_code' => '8471.3010',
            'tax_rate' => 18.0,
        ]
    ]
];

$pdf = generate_invoice_pdf($sampleInvoice);
assert(str_starts_with($pdf, '%PDF-1.4'), "Generated string must be valid PDF");
echo "PDF generated successfully! Size: " . strlen($pdf) . " bytes\n";

echo "\n>>> ALL FBR QR CODE VERIFICATION TESTS PASSED! <<<\n";
