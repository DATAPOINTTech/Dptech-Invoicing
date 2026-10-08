<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';

$db = database_connection();
$companies = $db->query("SELECT * FROM companies ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$sampleItems = [
    [
        'description' => 'Hikvision 4K IP Camera DS-2CD2183G2-IU with Audio',
        'model_make' => 'DS-2CD2183G2-IU / Hikvision',
        'quantity' => 4,
        'unit' => 'pcs',
        'unit_price' => 14500.00,
        'total_price' => 58000.00
    ],
    [
        'description' => 'Cat6 UTP Network Cable 305M Roll Solid Copper',
        'model_make' => 'Schneider Actassi 24AWG',
        'quantity' => 2,
        'unit' => 'roll',
        'unit_price' => 28500.00,
        'total_price' => 57000.00
    ]
];

foreach ($companies as $idx => $co) {
    $markup = (float)($co['markup_percent'] ?? 0);
    $subtotal = 115000.00 * (1.0 + ($markup / 100.0));
    $tax = round($subtotal * 0.17, 2);
    $total = $subtotal + $tax;

    $doc = [
        'company_id' => $co['id'],
        'company_name' => $co['name'],
        'company_code' => $co['code'],
        'company_logo' => $co['logo_url'],
        'company_phone' => $co['phone'] ?: '021-3456789' . $idx,
        'company_email' => $co['email'],
        'company_address' => $co['address'] ?: 'Hyderabad',
        'company_ntn' => $co['ntn'] ?: '1234567-' . $idx,
        'company_strn' => $co['strn'] ?: '1700123456' . $idx,
        'markup_percent' => $markup,
        'estimate_no' => 'EST-TEST-' . ($idx + 1),
        'estimate_date' => date('Y-m-d'),
        'valid_until' => date('Y-m-d', strtotime('+15 days')),
        'title' => 'CCTV SURVEILLANCE & NETWORKING INFRASTRUCTURE',
        'client_name' => 'Sindh Education Foundation',
        'client_company' => 'Govt of Sindh',
        'client_phone' => '03001234567',
        'client_address' => 'Plot 21, Block 5, Clifton, Karachi',
        'subtotal' => $subtotal,
        'tax_rate' => 17,
        'tax_amount' => $tax,
        'total_amount' => $total,
        'items' => $sampleItems
    ];

    $theme = get_company_color_theme($doc);
    echo "Testing Company " . ($idx + 1) . ": {$co['name']}\n";
    echo "  Theme Name: {$theme['name']} | Key: {$theme['theme_key']} | Header: {$theme['header_style']}\n";
    echo "  Doc Title: {$theme['doc_title']} | Slogan: {$theme['slogan']}\n";

    $pdf = generate_estimate_pdf($doc);
    $outPath = __DIR__ . "/estimate_theme_{$theme['theme_key']}.pdf";
    file_put_contents($outPath, $pdf);
    echo "  Generated PDF: {$outPath} (" . strlen($pdf) . " bytes)\n\n";
}

echo "All 3 themes generated successfully!\n";

