<?php

require __DIR__ . '/../services/pdf_service.php';

$data = [
    'invoice_no' => 'INV-2026-0001',
    'invoice_date' => '2026-09-30',
    'due_date' => '2026-10-15',
    'client_name' => 'Test Client Corp',
    'subtotal' => 50000,
    'tax_amount' => 8500,
    'tax_rate' => 17,
    'total_amount' => 58500,
    'items' => [
        [
            'description' => 'IT Infrastructure Support & Maintenance',
            'quantity' => 1,
            'unit_price' => 50000,
            'total_price' => 50000,
        ]
    ]
];

$pdf = generate_invoice_pdf($data);
file_put_contents(__DIR__ . '/test_output.pdf', $pdf);
echo "PDF generated successfully, length: " . strlen($pdf) . " bytes\n";
echo "First 100 bytes:\n" . substr($pdf, 0, 100) . "\n";
echo "Last 100 bytes:\n" . substr($pdf, -100) . "\n";
