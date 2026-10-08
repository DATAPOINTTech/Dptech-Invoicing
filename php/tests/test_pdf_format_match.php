<?php

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

$sampleDoc = [
    'invoice_no' => 'UoS-CCTV-ZONE-1/A',
    'estimate_no' => 'UoS-CCTV-ZONE-1/A',
    'title' => 'ACTIVE COMPONENTS-ZONE-1',
    'invoice_date' => '2026-06-04',
    'estimate_date' => '2026-06-04',
    'client_name' => 'University of Sindh - Main Campus',
    'client_phone' => '022-9213181',
    'client_address' => 'Allama I.I. Kazi Campus, Jamshoro, Sindh',
    'company_name' => 'DATAPOINT Technologies',
    'company_ntn' => '7178396-5',
    'company_strn' => '3277876124452',
    'company_phone' => '0316 7788990',
    'company_email' => 'info@datapointtechnology.com',
    'company_address' => 'G32 Shayas Residence, Jamshoro Road, Citizen Colony, Hyderabad',
    'tax_rate' => 18.0,
    'subtotal' => 844000.00,
    'tax_amount' => 151920.00,
    'total_amount' => 995920.00,
    'items' => [
        [
            'description' => "Junctions, Roundabouts\n4 MP 4 mm ColorVu Network Camera",
            'model_make' => "Hikvision 1047G2H-LIU 4 MP\n4 mm ColorVu Network Camera",
            'unit' => 'No.',
            'quantity' => 5,
            'unit_price' => 22000.00,
            'total_price' => 110000.00,
        ],
        [
            'description' => "Roads, Hostels\n4 MP 4 mm Network Camera",
            'model_make' => "Hikvision 1043G2H-LIU 4 MP\n4 mm Network Camera",
            'unit' => 'No.',
            'quantity' => 5,
            'unit_price' => 16500.00,
            'total_price' => 82500.00,
        ],
        [
            'description' => '64-Channel NVR AcuSense',
            'model_make' => 'Hikvision DS-7764NXI-M4',
            'unit' => 'No.',
            'quantity' => 1,
            'unit_price' => 150000.00,
            'total_price' => 150000.00,
        ],
        [
            'description' => '8TB HDD',
            'model_make' => 'WD Purple Pro WD80PURZ',
            'unit' => 'No.',
            'quantity' => 2,
            'unit_price' => 52000.00,
            'total_price' => 104000.00,
        ],
        [
            'description' => '48-Port All-SFP Managed Switch (Core)',
            'model_make' => '48 Port All SFP Managed switch Huawei',
            'unit' => 'No.',
            'quantity' => 1,
            'unit_price' => 300000.00,
            'total_price' => 300000.00,
        ],
        [
            'description' => '8-Port PoE Switch with SFP Uplink (Field)',
            'model_make' => 'Generic PoE+ 8P SFP',
            'unit' => 'No.',
            'quantity' => 5,
            'unit_price' => 19500.00,
            'total_price' => 97500.00,
        ],
    ],
    'terms_conditions' => "1. Delivery: Within 10-15 working days from purchase order.\n2. Warranty: 1-Year standard manufacturer warranty on active components.\n3. Payment Terms: 50% advance along with confirmed PO, balance upon delivery.",
];

$invPdf = generate_invoice_pdf($sampleDoc);
$estPdf = generate_estimate_pdf($sampleDoc);

file_put_contents(__DIR__ . '/sample_test_invoice.pdf', $invPdf);
file_put_contents(__DIR__ . '/sample_test_estimate.pdf', $estPdf);

echo "=== Invoice PDF Verification ===\n";
echo "Size: " . strlen($invPdf) . " bytes\n";
$invChecks = [
    'SALES TAX INVOICE' => str_contains($invPdf, 'SALES TAX INVOICE'),
    'Offering complete Suite' => str_contains($invPdf, 'Offering complete Suite'),
    'ACTIVE COMPONENTS-ZONE-1' => str_contains($invPdf, 'ACTIVE COMPONENTS-ZONE-1'),
    'Model / Make column' => str_contains($invPdf, 'Model / Make'),
    'Hikvision DS-7764NXI-M4' => str_contains($invPdf, 'Hikvision DS-7764NXI-M4'),
    'STAMP box' => str_contains($invPdf, 'STAMP'),
    'GST @ 18%' => str_contains($invPdf, 'GST @ 18%'),
    'GRAND TOTAL (escaped in pdf)' => str_contains($invPdf, 'GRAND TOTAL \\(PKR\\)'),
    'Footer Banner Image' => str_contains($invPdf, '/DCTDecode'),
];
foreach ($invChecks as $label => $pass) {
    echo " - $label: " . ($pass ? "PASS" : "FAIL") . "\n";
    if (!$pass) exit(1);
}

echo "=== Estimate / Quotation PDF Verification ===\n";
echo "Size: " . strlen($estPdf) . " bytes\n";
$estChecks = [
    'QUOTATION' => str_contains($estPdf, 'QUOTATION'),
    'EST: #' => str_contains($estPdf, 'EST: #'),
    'Offering complete Suite' => str_contains($estPdf, 'Offering complete Suite'),
    'Model / Make column' => str_contains($estPdf, 'Model / Make'),
    'STAMP box' => str_contains($estPdf, 'STAMP'),
    'GRAND TOTAL (escaped in pdf)' => str_contains($estPdf, 'GRAND TOTAL \\(PKR\\)'),
];
foreach ($estChecks as $label => $pass) {
    echo " - $label: " . ($pass ? "PASS" : "FAIL") . "\n";
    if (!$pass) exit(1);
}

echo "\nALL TESTS PASSED!\n";
