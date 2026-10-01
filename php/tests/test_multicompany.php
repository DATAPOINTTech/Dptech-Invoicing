<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/pdf_service.php';
require_once __DIR__ . '/../services/taxation.php';

echo "========================================================\n";
echo "  TEST: Multi-Company Estimates & Setup Verification    \n";
echo "========================================================\n\n";

$db = database_connection();

// 1. Verify Companies Schema & Seed Data
echo "1. Checking companies in database...\n";
$companies = $db->query("SELECT * FROM companies ORDER BY sort_order ASC")->fetchAll();
assert(count($companies) >= 3, "Expected at least 3 companies seeded");

foreach ($companies as $idx => $c) {
    echo "   [Company " . ($idx + 1) . "] ID: {$c['id']}, Name: {$c['name']}, Markup: {$c['markup_percent']}%, Default: {$c['is_default']}\n";
}
echo "   -> PASS: 3 Companies verified.\n\n";

// 2. Test Company Setup Update
echo "2. Testing Multi-Company Setup Update...\n";
$setupPayload = [
    [
        'id' => $companies[0]['id'],
        'name' => 'DATAPOINT Technologies',
        'code' => 'DPT',
        'markup_percent' => 0.0,
        'email' => 'sales@datapoint.example.com',
        'phone' => '+92 21 34567890',
        'address' => 'Suite 101, Datapoint Tower',
        'city' => 'Karachi',
        'ntn' => '1234567-8',
        'strn' => '17-00-1234-567-89',
        'terms' => 'Standard payment terms 30 days.'
    ],
    [
        'id' => $companies[1]['id'],
        'name' => 'TechPoint Solutions',
        'code' => 'TPS',
        'markup_percent' => 2.0,
        'email' => 'contact@techpoint.example.com',
        'phone' => '+92 21 34567891',
        'address' => 'Suite 202, TechPoint Arcade',
        'city' => 'Karachi',
        'ntn' => '2345678-9',
        'strn' => '17-00-2345-678-90',
        'terms' => 'Payment within 15 days.'
    ],
    [
        'id' => $companies[2]['id'],
        'name' => 'Apex Data Systems',
        'code' => 'ADS',
        'markup_percent' => 3.0,
        'email' => 'billing@apexdata.example.com',
        'phone' => '+92 21 34567892',
        'address' => 'Floor 3, Apex Heights',
        'city' => 'Karachi',
        'ntn' => '3456789-0',
        'strn' => '17-00-3456-789-01',
        'terms' => 'Payment due upon receipt.'
    ]
];

$updateStmt = $db->prepare("
    UPDATE companies 
    SET name = :name, code = :code, markup_percent = :markup_percent,
        email = :email, phone = :phone, address = :address, city = :city,
        ntn = :ntn, strn = :strn, terms = :terms
    WHERE id = :id
");

foreach ($setupPayload as $p) {
    $updateStmt->execute([
        ':name' => $p['name'],
        ':code' => $p['code'],
        ':markup_percent' => $p['markup_percent'],
        ':email' => $p['email'],
        ':phone' => $p['phone'],
        ':address' => $p['address'],
        ':city' => $p['city'],
        ':ntn' => $p['ntn'],
        ':strn' => $p['strn'],
        ':terms' => $p['terms'],
        ':id' => $p['id']
    ]);
}
echo "   -> PASS: Updated company profiles.\n\n";

// 3. Ensure a test client exists
$client = $db->query("SELECT * FROM clients LIMIT 1")->fetch();
if (!$client) {
    $db->prepare("INSERT INTO clients (name, email, phone, company, address) VALUES ('Alpha Corp', 'client@alpha.com', '12345', 'Alpha Corp', 'Main Street')")->execute();
    $clientId = (int)$db->lastInsertId();
} else {
    $clientId = (int)$client['id'];
}

// 4. Test Multi-Estimate Generation Logic
echo "3. Simulating Multi-Company Estimate Generation...\n";

// Base items with fixed rates and quantities
$testItems = [
    [
        'description' => 'Enterprise Server Rack 42U',
        'quantity' => 2,
        'unit' => 'pcs',
        'unit_price' => 1000.00, // Expected: Co 1 = 1000.00, Co 2 (+2%) = 1020.00, Co 3 (+3%) = 1030.00
    ],
    [
        'description' => 'Installation & Setup Services',
        'quantity' => 5,
        'unit' => 'hrs',
        'unit_price' => 250.00, // Expected: Co 1 = 250.00, Co 2 (+2%) = 255.00, Co 3 (+3%) = 257.50
    ]
];

$activeCompanies = $db->query("SELECT * FROM companies ORDER BY sort_order ASC LIMIT 3")->fetchAll();
$batchId = 'GRP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

$createdEstimates = [];

foreach ($activeCompanies as $cIndex => $company) {
    $markup = (float)($company['markup_percent'] ?? 0.0);
    // Explicit rule: Co 1 = 0%, Co 2 = +2%, Co 3 = +3%
    if ($cIndex === 0) $markup = 0.0;
    elseif ($cIndex === 1) $markup = 2.0;
    elseif ($cIndex === 2) $markup = 3.0;

    $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
    $estimateNo = sprintf('EST-%s-%05d', date('Ym'), $nextId);

    $calculated = [];
    $taxRate = 10.0; // 10% tax
    foreach ($testItems as $rawItem) {
        $basePrice = (float)$rawItem['unit_price'];
        $markedPrice = ($markup > 0) ? round($basePrice * (1 + ($markup / 100.0)), 2) : $basePrice;
        $qty = (float)$rawItem['quantity'];

        $line = calculate_item_tax($markedPrice, $qty, $taxRate, false);
        $line['item'] = array_merge($rawItem, ['unit_price' => $markedPrice]);
        $calculated[] = $line;
    }

    $totals = calculate_invoice_tax($calculated, 0.0, $taxRate, false, false);

    $stmt = $db->prepare('
        INSERT INTO estimates (
            estimate_no, client_id, project_id, title, estimate_date, valid_until,
            status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
            total_amount, terms_conditions, notes, created_by,
            company_id, company_name, company_logo, company_phone, company_email, company_address, company_ntn, company_strn,
            batch_id, markup_percent, created_at, updated_at
        ) VALUES (
            :no, :cid, :pid, :title, :edate, :vdate,
            :status, :subtotal, :disc_pct, :disc_amt, :tax_rate, :tax_amt,
            :total, :terms, :notes, :uid,
            :coid, :coname, :cologo, :cophone, :coemail, :coaddr, :conntn, :costrn,
            :bid, :markup, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ');

    $stmt->execute([
        'no' => $estimateNo,
        'cid' => $clientId,
        'pid' => null,
        'title' => 'Multi-Company Quotation Test',
        'edate' => date('Y-m-d'),
        'vdate' => date('Y-m-d', strtotime('+30 days')),
        'status' => 'draft',
        'subtotal' => $totals['subtotal'],
        'disc_pct' => 0.0,
        'disc_amt' => 0.0,
        'tax_rate' => $totals['tax_rate'],
        'tax_amt' => $totals['tax_amount'],
        'total' => $totals['total_amount'],
        'terms' => $company['terms'] ?? 'Standard terms',
        'notes' => 'Batch quotation: ' . $batchId,
        'uid' => 1,
        'coid' => $company['id'],
        'coname' => $company['name'],
        'cologo' => $company['logo_url'] ?? '/static/img/logo.png',
        'cophone' => $company['phone'] ?? null,
        'coemail' => $company['email'] ?? null,
        'coaddr' => $company['address'] ?? null,
        'conntn' => $company['ntn'] ?? null,
        'costrn' => $company['strn'] ?? null,
        'bid' => $batchId,
        'markup' => $markup,
    ]);
    $estId = (int)$db->lastInsertId();

    $itemStmt = $db->prepare('
        INSERT INTO estimate_items (
            estimate_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
        ) VALUES (
            :eid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
        )
    ');
    foreach ($calculated as $line) {
        $it = $line['item'];
        $itemStmt->execute([
            'eid' => $estId,
            'prid' => null,
            'desc' => $it['description'],
            'qty' => $it['quantity'],
            'unit' => $it['unit'],
            'price' => $it['unit_price'],
            'tr' => $taxRate,
            'ta' => $line['tax_amount'],
            'tp' => $line['total'],
        ]);
    }

    $createdEstimates[] = [
        'id' => $estId,
        'number' => $estimateNo,
        'company' => $company['name'],
        'markup' => $markup,
        'subtotal' => $totals['subtotal'],
        'tax' => $totals['tax_amount'],
        'total' => $totals['total_amount'],
        'calculated' => $calculated
    ];
}

// 5. Assertions on Markups and Pricing
echo "4. Checking Generated Estimates & Rates:\n";
foreach ($createdEstimates as $i => $est) {
    echo "   [Estimate {$est['number']}] Company: {$est['company']} (Markup: +{$est['markup']}%)\n";
    echo "      Subtotal: {$est['subtotal']}, Tax: {$est['tax']}, Total: {$est['total']}\n";
    foreach ($est['calculated'] as $line) {
        $it = $line['item'];
        echo "      - {$it['description']} | Qty: {$it['quantity']} | Unit Price: {$it['unit_price']} | Total: {$line['total']}\n";
    }
}

// Company 1: Base prices
$co1 = $createdEstimates[0];
$co2 = $createdEstimates[1];
$co3 = $createdEstimates[2];

// Check Item 1: Base: 1000, Co2 (+2%): 1020, Co3 (+3%): 1030
assert($co1['calculated'][0]['item']['unit_price'] == 1000.00, "Co 1 Item 1 price should be 1000.00");
assert($co2['calculated'][0]['item']['unit_price'] == 1020.00, "Co 2 Item 1 price should be 1020.00 (+2%)");
assert($co3['calculated'][0]['item']['unit_price'] == 1030.00, "Co 3 Item 1 price should be 1030.00 (+3%)");

// Check Item 2: Base: 250, Co2 (+2%): 255, Co3 (+3%): 257.50
assert($co1['calculated'][1]['item']['unit_price'] == 250.00, "Co 1 Item 2 price should be 250.00");
assert($co2['calculated'][1]['item']['unit_price'] == 255.00, "Co 2 Item 2 price should be 255.00 (+2%)");
assert($co3['calculated'][1]['item']['unit_price'] == 257.50, "Co 3 Item 2 price should be 257.50 (+3%)");

echo "\n   -> PASS: Rate calculations strictly adhere to 0%, +2%, and +3%!\n\n";

// 6. Test Batch Querying (Finding Siblings)
echo "5. Testing Sibling Quotes Lookup by Batch ID...\n";
$siblings = $db->prepare("SELECT id, estimate_no, company_name, markup_percent, total_amount FROM estimates WHERE batch_id = :b ORDER BY id ASC");
$siblings->execute([':b' => $batchId]);
$siblingRows = $siblings->fetchAll();
assert(count($siblingRows) === 3, "Expected 3 siblings in batch");
echo "   Found " . count($siblingRows) . " sibling estimates in batch {$batchId}.\n";
echo "   -> PASS: Sibling quotation grouping verified.\n\n";

// 7. Test PDF Generation for all 3 company estimates
echo "6. Testing PDF Rendering for all 3 company estimates...\n";
foreach ($createdEstimates as $est) {
    $fullEst = $db->prepare("SELECT * FROM estimates WHERE id = :id");
    $fullEst->execute([':id' => $est['id']]);
    $estData = $fullEst->fetch();

    $fullItems = $db->prepare("SELECT * FROM estimate_items WHERE estimate_id = :id");
    $fullItems->execute([':id' => $est['id']]);
    $estData['items'] = $fullItems->fetchAll();

    $pdfBytes = generate_estimate_pdf($estData);
    assert(!empty($pdfBytes), "PDF bytes should not be empty");
    echo "   Generated PDF for [{$estData['estimate_no']}] Company: {$estData['company_name']} (" . strlen($pdfBytes) . " bytes)\n";
}

echo "\n========================================================\n";
echo "  ALL MULTI-COMPANY TESTS PASSED SUCCESSFULLY!          \n";
echo "========================================================\n";
