<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/product_export_service.php';
require_once __DIR__ . '/../services/google_sheet_sync.php';

echo "=== Testing Product Exports (Excel, DOCX, CSV) and Google Sheet Sync ===\n\n";

$db = database_connection();

// 1. Ensure test product exists
$testSku = 'TEST-EXP-' . time();
$testName = 'Solar Inverter 5KW ' . time();
$stmt = $db->prepare('INSERT INTO products (name, sku, category, unit, cost_price, unit_price, description, is_active, created_at, updated_at) VALUES (:name, :sku, "Solar", "pcs", 120000.00, 132000.00, "5KW Hybrid Inverter", 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
$stmt->execute([
    'name' => $testName,
    'sku' => $testSku
]);
$testId = (int)$db->lastInsertId();
$stmt->closeCursor();
unset($stmt);

echo "1. Seeded test product: ID #{$testId} ({$testName}, SKU: {$testSku}, Rate: 120,000, Selling: 132,000)\n";

// 2. Test Excel (.xlsx) Generation
echo "2. Generating Microsoft Excel (.xlsx) export...\n";
$excelBinary = export_products_to_excel($db);
assert(strlen($excelBinary) > 1000, "Excel binary must be at least 1KB");

// Verify that it's a valid ZIP with xl/worksheets/sheet1.xml
$tmpXlsx = tempnam(sys_get_temp_dir(), 'test_v_xlsx_');
file_put_contents($tmpXlsx, $excelBinary);
$zip = new ZipArchive();
$openRes = $zip->open($tmpXlsx);
assert($openRes === true, "Excel file must be a valid OpenXML Zip archive");
assert($zip->locateName('xl/worksheets/sheet1.xml') !== false, "Excel must contain xl/worksheets/sheet1.xml");
assert($zip->locateName('xl/styles.xml') !== false, "Excel must contain xl/styles.xml");
$sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
assert(strpos($sheetContent, htmlspecialchars($testName, ENT_XML1, 'UTF-8')) !== false, "Excel sheet must contain the test product name");
assert(strpos($sheetContent, 'Current Rate (Purchase Price)') !== false, "Excel sheet must contain Current Rate header");
assert(strpos($sheetContent, 'Quotation Rate (+10% Profit)') !== false, "Excel sheet must contain Quotation Rate header");
$zip->close();
@unlink($tmpXlsx);
echo "   -> [PASS] Valid .xlsx generated (Size: " . strlen($excelBinary) . " bytes). Verified OpenXML structure and product content.\n";

// 3. Test Word (.docx) Generation
echo "3. Generating Microsoft Word (.docx) export...\n";
$docxBinary = export_products_to_docx($db);
assert(strlen($docxBinary) > 1000, "DOCX binary must be at least 1KB");

// Verify that it's a valid ZIP with word/document.xml
$tmpDocx = tempnam(sys_get_temp_dir(), 'test_v_docx_');
file_put_contents($tmpDocx, $docxBinary);
$zipDocx = new ZipArchive();
$openDocx = $zipDocx->open($tmpDocx);
assert($openDocx === true, "Word file must be a valid OpenXML Zip archive");
assert($zipDocx->locateName('word/document.xml') !== false, "Word file must contain word/document.xml");
$docContent = $zipDocx->getFromName('word/document.xml');
assert(strpos($docContent, 'DATAPOINT TECHNOLOGIES') !== false, "DOCX must contain DATAPOINT TECHNOLOGIES header");
assert(strpos($docContent, htmlspecialchars($testName, ENT_XML1, 'UTF-8')) !== false, "DOCX must contain the test product name");
$zipDocx->close();
@unlink($tmpDocx);
echo "   -> [PASS] Valid .docx generated (Size: " . strlen($docxBinary) . " bytes). Verified OpenXML structure and company catalog table.\n";

// 4. Test CSV Export
echo "4. Generating CSV export...\n";
$csvContent = export_products_to_csv($db);
assert(strpos($csvContent, 'Product Name') !== false, "CSV must contain Product Name header");
assert(strpos($csvContent, 'Current Rate') !== false, "CSV must contain Current Rate header");
assert(strpos($csvContent, $testName) !== false, "CSV must contain test product");
echo "   -> [PASS] Valid CSV generated (Size: " . strlen($csvContent) . " bytes).\n";

// 5. Test Fetch and Insert / Update Rates from Google Sheet / CSV format
echo "5. Testing Rate Update and New Product Insertion from CSV (Google Sheet format)...\n";
$newProductFromSheet = 'Lithium Battery 48V ' . time();
$newSkuFromSheet = 'BAT-48V-' . time();
$updatedRate = 129000.00; // Updated rate for existing product
$newProductRate = 350000.00; // Rate for new product to insert

$simulatedSheetCsv = "Product Name,SKU,Category,Current Rate,Unit,Description\n" .
    "\"{$testName}\",\"{$testSku}\",Solar,{$updatedRate},pcs,\"Updated from sheet\"\n" .
    "\"{$newProductFromSheet}\",\"{$newSkuFromSheet}\",Solar,{$newProductRate},pcs,\"Brand new battery inserted from sheet\"\n";

$syncResult = sync_products_from_csv_content($db, $simulatedSheetCsv, 10.0);
assert($syncResult['success'] === true, "Sync must succeed");
assert($syncResult['updated_count'] >= 1, "Must update at least 1 existing product");
assert($syncResult['created_count'] >= 1, "Must insert at least 1 new product");

// Verify in DB that existing product rate was updated
$checkStmt = $db->prepare('SELECT cost_price, unit_price FROM products WHERE id = :id');
$checkStmt->execute(['id' => $testId]);
$checkRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
$checkStmt->closeCursor();
unset($checkStmt);

assert((float)$checkRow['cost_price'] === $updatedRate, "Cost price must be updated to {$updatedRate}");
assert((float)$checkRow['unit_price'] === round($updatedRate * 1.10, 2), "Unit price must have +10% profit");
echo "   -> [PASS] Existing product rate updated in database: Cost = PKR {$checkRow['cost_price']}, Selling (+10%) = PKR {$checkRow['unit_price']}\n";

// Verify in DB that brand new product was inserted
$newStmt = $db->prepare('SELECT id, cost_price, unit_price FROM products WHERE sku = :sku');
$newStmt->execute(['sku' => $newSkuFromSheet]);
$newRow = $newStmt->fetch(PDO::FETCH_ASSOC);
$newStmt->closeCursor();
unset($newStmt);

assert($newRow !== false, "New product must be inserted in database");
assert((float)$newRow['cost_price'] === $newProductRate, "New product cost price must match {$newProductRate}");
assert((float)$newRow['unit_price'] === round($newProductRate * 1.10, 2), "New product unit price must have +10% profit");
echo "   -> [PASS] New product inserted into database: ID #{$newRow['id']}, Cost = PKR {$newRow['cost_price']}, Selling (+10%) = PKR {$newRow['unit_price']}\n";

// Clean up test products
$delStmt = $db->prepare('DELETE FROM products WHERE id IN (:id1, :id2)');
$delStmt->execute(['id1' => $testId, 'id2' => (int)$newRow['id']]);
$delStmt->closeCursor();
unset($delStmt);

echo "\n>>> ALL PRODUCT EXPORT (EXCEL, DOCX, CSV) AND RATE SYNC TESTS PASSED 100%! <<<\n";
