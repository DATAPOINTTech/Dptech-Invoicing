<?php

require __DIR__ . '/../services/pdf_service.php';

$invoice = [
    'company_name' => 'DATAPOINT Technologies',
    'company_address' => 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh',
    'company_phone' => '+92-316-7788990',
    'company_email' => 'info@datapointtechnology.com',
    'company_ntn' => '7123456-7',
    'company_strn' => '3277876123456',
    'invoice_no' => 'INV-2026-0001',
    'invoice_date' => '2026-09-30',
    'due_date' => '2026-10-15',
    'status' => 'partially_paid',
    'client_name' => 'Apex Logistics Corp',
    'client_company' => 'Apex Global Logistics Pvt Ltd',
    'client_phone' => '+92-300-1234567',
    'client_email' => 'accounts@apexlogistics.com',
    'client_address' => 'Plot 45, Phase 2, Industrial Area, Karachi',
    'client_ntn' => '8912345-1',
    'subtotal' => 125000,
    'discount_amount' => 5000,
    'tax_rate' => 18,
    'tax_amount' => 20400,
    'total_amount' => 140400,
    'amount_paid' => 50000,
    'balance_due' => 90400,
    'items' => [
        [
            'description' => 'Enterprise Server Installation & Configuration with Dual Xeon Platinum Setup',
            'quantity' => 1,
            'unit' => 'server',
            'unit_price' => 75000,
            'total_price' => 75000,
        ],
        [
            'description' => 'Cisco Managed Gigabit Switch 24-Port with PoE+ and Rack Mounting Kits',
            'quantity' => 2,
            'unit' => 'pcs',
            'unit_price' => 25000,
            'total_price' => 50000,
        ]
    ]
];

$pdfInv = generate_invoice_pdf($invoice);
file_put_contents(__DIR__ . '/test_invoice.pdf', $pdfInv);
echo "Invoice PDF generated: " . strlen($pdfInv) . " bytes\n";

$estimate = [
    'company_name' => 'TechPoint Solutions',
    'markup_percent' => 2.0,
    'estimate_no' => 'EST-2026-0001-B',
    'estimate_date' => '2026-09-30',
    'valid_until' => '2026-10-15',
    'client_name' => 'Apex Logistics Corp',
    'subtotal' => 127500,
    'tax_rate' => 17,
    'tax_amount' => 21675,
    'total_amount' => 149175,
    'items' => [
        [
            'description' => 'Enterprise Server Installation & Configuration (TechPoint)',
            'quantity' => 1,
            'unit' => 'server',
            'unit_price' => 76500,
            'total_price' => 76500,
        ],
        [
            'description' => 'Cisco Managed Switch (TechPoint)',
            'quantity' => 2,
            'unit' => 'pcs',
            'unit_price' => 25500,
            'total_price' => 51000,
        ]
    ]
];

$pdfEst = generate_estimate_pdf($estimate);
file_put_contents(__DIR__ . '/test_estimate.pdf', $pdfEst);
echo "Estimate PDF generated: " . strlen($pdfEst) . " bytes\n";

// Verify offsets of both
foreach (['test_invoice.pdf' => $pdfInv, 'test_estimate.pdf' => $pdfEst] as $name => $content) {
    preg_match('/startxref\s+(\d+)/', $content, $m);
    $sx = (int)$m[1];
    $trailer = substr($content, $sx);
    assert(str_starts_with($trailer, "xref\n"), "$name: trailer must point to xref");
    preg_match_all('/(\d{10})\s+00000\s+n/', $trailer, $matches);
    foreach ($matches[1] as $idx => $offStr) {
        $objNum = $idx + 1;
        $off = (int)$offStr;
        assert(str_starts_with(substr($content, $off, 15), "{$objNum} 0 obj"), "$name: obj $objNum offset must match");
    }
    echo "{$name} offsets 100% verified!\n";
}
