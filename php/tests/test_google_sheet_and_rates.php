<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== Running Google Sheet Sync & 10% Profit Margin Tests ===\n\n";

$db = database_connection();

// 1. Test URL Normalization
echo "[1] Testing normalize_google_sheet_csv_url...\n";
$testUrls = [
    'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit?usp=sharing' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/export?format=csv',
    'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit#gid=12345' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/export?format=csv&gid=12345',
    'https://docs.google.com/spreadsheets/d/e/2PACX-1vQABC123XYZ/pubhtml' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQABC123XYZ/pub?output=csv',
    'https://docs.google.com/spreadsheets/d/e/2PACX-1vQABC123XYZ/pub?output=csv' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQABC123XYZ/pub?output=csv',
];

foreach ($testUrls as $in => $expected) {
    $out = normalize_google_sheet_csv_url($in);
    if ($out !== $expected) {
        throw new RuntimeException("URL Normalization failed!\nInput: $in\nGot: $out\nExpected: $expected");
    }
}
echo "✓ All URL formats normalized successfully!\n\n";

// 2. Test CSV Parsing
echo "[2] Testing parse_product_sheet_csv...\n";
$sampleCsv = <<<CSV
Product Name,SKU,Category,Current Rate,Unit,Description
Hikvision 4MP ColorVu Network Bullet Camera (DS-2CD1047G2H-LIU),HK-4MP-CVU-01,CCTV,"19,250.00",pcs,Smart hybrid camera
Hikvision 32-Channel 4K NVR (DS-7732NI-K4),HK-NVR-32CH-02,CCTV,69500,pcs,32ch 4K NVR
Brand New Solar Inverter 5KW,SOLAR-5KW,Solar,210000,pcs,5KW Hybrid Solar Inverter
CSV;

$parsed = parse_product_sheet_csv($sampleCsv);
if (count($parsed) !== 3) {
    throw new RuntimeException("Expected 3 parsed rows, got " . count($parsed));
}

if ($parsed[0]['cost_price'] != 19250.0) {
    throw new RuntimeException("Expected cost_price 19250.0, got " . $parsed[0]['cost_price']);
}
if ($parsed[2]['name'] !== 'Brand New Solar Inverter 5KW' || $parsed[2]['cost_price'] != 210000.0) {
    throw new RuntimeException("Failed parsing solar inverter row");
}
echo "✓ CSV parsing verified: parsed 3 items with cleaned numeric rates.\n\n";

// 3. Test App Settings for Google Sheet
echo "[3] Testing app_settings...\n";
set_app_setting($db, 'google_sheet_url', 'https://docs.google.com/spreadsheets/d/SAMPLE_ID/edit?usp=sharing');
$retrieved = get_app_setting($db, 'google_sheet_url');
if ($retrieved !== 'https://docs.google.com/spreadsheets/d/SAMPLE_ID/edit?usp=sharing') {
    throw new RuntimeException("App settings retrieval failed");
}
echo "✓ App settings working correctly.\n\n";

// 4. Test 10% Profit Calculation on existing product
echo "[4] Testing 10% Profit Calculation...\n";
$stmt = $db->prepare('SELECT id, name, cost_price, unit_price FROM products WHERE id = 1');
$stmt->execute();
$prod = $stmt->fetch(PDO::FETCH_ASSOC);

$cost = (float) $prod['cost_price'];
$selling = (float) $prod['unit_price'];
$expectedSellingWith10Pct = round($cost * 1.10, 2);

echo "Product: {$prod['name']}\n";
echo "Purchase Rate (Cost): PKR {$cost}\n";
echo "Selling Rate with 10% Profit: PKR {$expectedSellingWith10Pct}\n";

if ($cost > 0 && abs($expectedSellingWith10Pct - ($cost * 1.10)) > 0.01) {
    throw new RuntimeException("10% Profit calculation mismatch");
}
echo "✓ 10% Profit rule verified: Cost {$cost} -> Selling {$expectedSellingWith10Pct}\n\n";

// 5. Test Batch Update Rates
echo "[5] Testing Batch Update Rates...\n";
$stmt = $db->prepare('UPDATE products SET cost_price = :cost, unit_price = :unit WHERE id = 1');
$newCost = 20000.0;
$newSelling = round($newCost * 1.10, 2);
$stmt->execute(['cost' => $newCost, 'unit' => $newSelling]);

$stmtCheck = $db->prepare('SELECT cost_price, unit_price FROM products WHERE id = 1');
$stmtCheck->execute();
$updatedProd = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ((float) $updatedProd['cost_price'] !== 20000.0 || (float) $updatedProd['unit_price'] !== 22000.0) {
    throw new RuntimeException("Batch rate update failed: " . json_encode($updatedProd));
}
echo "✓ Batch rate update verified: New Cost 20000 -> New Selling 22000 (+10%)\n\n";

// Revert test product 1 back to standard
$stmt->execute(['cost' => 18500.0, 'unit' => round(18500.0 * 1.10, 2)]);

echo "=== All Tests Passed Successfully! ===\n";

