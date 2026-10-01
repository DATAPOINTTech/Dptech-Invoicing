<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/taxation.php';

echo "========================================================\n";
echo "  TEST: Company Folders & Additional Estimates          \n";
echo "========================================================\n\n";

$db = database_connection();

// 1. Verify /api/companies query with estimate counts and total sums
echo "1. Checking companies with folder counts and totals...\n";
$companies = $db->query('
    SELECT c.*, 
           COUNT(e.id) AS estimates_count,
           COALESCE(SUM(e.total_amount), 0) AS total_estimates_amount
    FROM companies c
    LEFT JOIN estimates e ON e.company_id = c.id
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.id ASC
')->fetchAll();

assert(count($companies) >= 3, "Expected at least 3 companies");
foreach ($companies as $c) {
    echo "   [Folder: {$c['name']}] (ID: {$c['id']}, Markup: {$c['markup_percent']}%) => Estimates: {$c['estimates_count']}, Total PKR: {$c['total_estimates_amount']}\n";
}
echo "   -> PASS: Company folder stats retrieved successfully.\n\n";

// 2. Test filtering estimates by company_id
echo "2. Testing estimate filtering by company folder...\n";
foreach ($companies as $c) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM estimates WHERE company_id = :cid');
    $stmt->execute([':cid' => $c['id']]);
    $folderCount = (int)$stmt->fetchColumn();
    echo "   Folder '{$c['name']}' has {$folderCount} estimates.\n";
    assert($folderCount === (int)$c['estimates_count'], "Counts should match exactly");
}
echo "   -> PASS: Filter by company folder verified.\n\n";

// 3. Test creating an additional estimate specifically inside Company 2's folder
echo "3. Testing creation of an additional estimate inside Company 2 Folder...\n";
$co2 = $companies[1];
$co2Id = (int)$co2['id'];
$co2Markup = (float)$co2['markup_percent'];
echo "   Target Folder: {$co2['name']} (ID: {$co2Id}, Preset Markup: +{$co2Markup}%)\n";

$client = $db->query("SELECT id FROM clients LIMIT 1")->fetch();
$clientId = $client ? (int)$client['id'] : 1;

$testItem = [
    'description' => 'Dedicated Core Fiber Switch 24-Port',
    'quantity' => 1,
    'unit' => 'pcs',
    'unit_price' => 5000.00
];

// Calculation with Co 2 markup (+2%)
$basePrice = $testItem['unit_price'];
$markedPrice = round($basePrice * (1 + ($co2Markup / 100.0)), 2);
assert($markedPrice == 5100.00, "5000 + 2% should be 5100.00");

$taxRate = 17.0;
$line = calculate_item_tax($markedPrice, 1.0, $taxRate, false);
$line['item'] = array_merge($testItem, ['unit_price' => $markedPrice]);
$totals = calculate_invoice_tax([$line], 0.0, $taxRate, false, false);

$nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
$estNo = sprintf('EST-%s-%05d', date('Ym'), $nextId);

$stmt = $db->prepare('
    INSERT INTO estimates (
        estimate_no, client_id, project_id, title, estimate_date, valid_until,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, terms_conditions, notes, created_by,
        company_id, company_name, company_logo, company_phone, company_email, company_address, company_ntn, company_strn,
        batch_id, markup_percent, created_at, updated_at
    ) VALUES (
        :no, :cid, NULL, :title, :edate, :vdate,
        :status, :subtotal, 0, 0, :tax_rate, :tax_amt,
        :total, :terms, :notes, 1,
        :coid, :coname, :cologo, :cophone, :coemail, :coaddr, :conntn, :costrn,
        NULL, :markup, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
');
$stmt->execute([
    'no' => $estNo,
    'cid' => $clientId,
    'title' => 'Additional Estimate in ' . $co2['name'] . ' Folder',
    'edate' => date('Y-m-d'),
    'vdate' => date('Y-m-d', strtotime('+15 days')),
    'status' => 'draft',
    'subtotal' => $totals['subtotal'],
    'tax_rate' => $totals['tax_rate'],
    'tax_amt' => $totals['tax_amount'],
    'total' => $totals['total_amount'],
    'terms' => $co2['terms'] ?? 'Standard terms',
    'notes' => 'Created directly under folder ' . $co2['name'],
    'coid' => $co2Id,
    'coname' => $co2['name'],
    'cologo' => $co2['logo_url'] ?? '/static/img/logo.png',
    'cophone' => $co2['phone'] ?? null,
    'coemail' => $co2['email'] ?? null,
    'coaddr' => $co2['address'] ?? null,
    'conntn' => $co2['ntn'] ?? null,
    'costrn' => $co2['strn'] ?? null,
    'markup' => $co2Markup
]);
$newEstId = (int)$db->lastInsertId();

$itemStmt = $db->prepare('
    INSERT INTO estimate_items (estimate_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price)
    VALUES (:eid, NULL, :desc, :qty, :unit, :price, :tr, :ta, :tp)
');
$itemStmt->execute([
    'eid' => $newEstId,
    'desc' => $line['item']['description'],
    'qty' => $line['item']['quantity'],
    'unit' => $line['item']['unit'],
    'price' => $line['item']['unit_price'],
    'tr' => $taxRate,
    'ta' => $line['tax_amount'],
    'tp' => $line['total']
]);

echo "   -> PASS: Additional estimate {$estNo} created under {$co2['name']} folder.\n\n";

// 4. Verify that the new estimate appears in Company 2 folder
echo "4. Verifying new estimate in Company 2 Folder query...\n";
$folder2Query = $db->prepare('SELECT id, estimate_no, company_name, markup_percent, total_amount FROM estimates WHERE company_id = :cid ORDER BY id DESC');
$folder2Query->execute([':cid' => $co2Id]);
$co2Estimates = $folder2Query->fetchAll();

assert(count($co2Estimates) > 0, "Company 2 folder must contain estimates");
$found = false;
foreach ($co2Estimates as $e) {
    if ((int)$e['id'] === $newEstId) {
        $found = true;
        echo "   Found: [ID: {$e['id']}] {$e['estimate_no']} under folder '{$e['company_name']}' with total PKR {$e['total_amount']}\n";
    }
}
assert($found, "New estimate must be listed in Company 2 folder");
echo "   -> PASS: Additional estimate successfully organized under its company folder!\n\n";

echo "========================================================\n";
echo "  ALL TESTS PASSED! COMPANY FOLDERS FULLY OPERATIONAL.  \n";
echo "========================================================\n";
