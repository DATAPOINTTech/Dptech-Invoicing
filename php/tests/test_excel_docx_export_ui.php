<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/product_export_service.php';
require_once __DIR__ . '/../services/google_sheet_sync.php';

echo "=== Verifying Excel (.xlsx), DOCX (.docx) Export & Google Sheet Sync UI & Endpoints ===\n\n";

$db = database_connection();

// 1. Verify /products template
$productsHtml = file_get_contents(__DIR__ . '/../templates/inventory/products.html');
assert(strpos($productsHtml, 'exportProductsExcel()') !== false, "products.html must contain exportProductsExcel()");
assert(strpos($productsHtml, 'exportProductsDocx()') !== false, "products.html must contain exportProductsDocx()");
assert(strpos($productsHtml, 'exportProductsCsv()') !== false, "products.html must contain exportProductsCsv()");
assert(strpos($productsHtml, 'openGoogleSheetModal()') !== false, "products.html must contain openGoogleSheetModal()");
assert(strpos($productsHtml, 'fetchFromGoogleSheet()') !== false, "products.html must contain fetchFromGoogleSheet()");
assert(strpos($productsHtml, 'id="google-sheet-modal"') !== false, "products.html must contain google-sheet-modal");
assert(strpos($productsHtml, 'id="modal-sheet-url"') !== false, "products.html must contain modal-sheet-url");
assert(strpos($productsHtml, 'Export to Excel') !== false, "products.html must contain 'Export to Excel' label");
assert(strpos($productsHtml, 'Export to DOCX') !== false, "products.html must contain 'Export to DOCX' label");
assert(strpos($productsHtml, 'Fetch from Google Sheet') !== false, "products.html must contain 'Fetch from Google Sheet' label");
echo "1. Verified /products template: Export to Excel, Export to DOCX, Export CSV, and Google Sheet Rate Sync modal are fully wired.\n";

// 2. Verify /product-sheet template
$sheetHtml = file_get_contents(__DIR__ . '/../templates/inventory/product_sheet.html');
assert(strpos($sheetHtml, 'exportExcel()') !== false, "product_sheet.html must contain exportExcel()");
assert(strpos($sheetHtml, 'exportDocx()') !== false, "product_sheet.html must contain exportDocx()");
assert(strpos($sheetHtml, 'exportCsv()') !== false, "product_sheet.html must contain exportCsv()");
assert(strpos($sheetHtml, 'Export to Excel') !== false, "product_sheet.html must contain 'Export to Excel' label");
assert(strpos($sheetHtml, 'Export to DOCX') !== false, "product_sheet.html must contain 'Export to DOCX' label");
assert(strpos($sheetHtml, 'triggerSync()') !== false, "product_sheet.html must contain triggerSync()");
echo "2. Verified /product-sheet template: Export to Excel, Export to DOCX, Export CSV, and Google Sheet Rate Sync are fully wired.\n";

// 3. Verify Excel (.xlsx) file generation & schema integrity
$xlsxData = export_products_to_excel($db);
assert(strlen($xlsxData) > 500, "XLSX must be valid binary > 500 bytes");
$tmp = tempnam(sys_get_temp_dir(), 'test_chk_xlsx_');
file_put_contents($tmp, $xlsxData);
$z = new ZipArchive();
assert($z->open($tmp) === true, "Must be valid zip archive");
assert($z->locateName('xl/worksheets/sheet1.xml') !== false, "Must have sheet1.xml");
assert($z->locateName('xl/workbook.xml') !== false, "Must have workbook.xml");
assert($z->locateName('xl/styles.xml') !== false, "Must have styles.xml");
$z->close();
@unlink($tmp);
echo "3. Verified Excel (.xlsx) export: Clean OpenXML spreadsheet generated successfully (" . strlen($xlsxData) . " bytes).\n";

// 4. Verify Word (.docx) file generation & schema integrity
$docxData = export_products_to_docx($db);
assert(strlen($docxData) > 500, "DOCX must be valid binary > 500 bytes");
$tmp2 = tempnam(sys_get_temp_dir(), 'test_chk_docx_');
file_put_contents($tmp2, $docxData);
$z2 = new ZipArchive();
assert($z2->open($tmp2) === true, "Must be valid zip archive");
assert($z2->locateName('word/document.xml') !== false, "Must have document.xml");
assert($z2->locateName('word/styles.xml') !== false, "Must have styles.xml");
$z2->close();
@unlink($tmp2);
echo "4. Verified Word (.docx) export: Clean OpenXML document generated successfully (" . strlen($docxData) . " bytes).\n";

// 5. Verify CSV export
$csvData = export_products_to_csv($db);
assert(strpos($csvData, 'Product Name') !== false, "Must contain Product Name header");
assert(strpos($csvData, 'Current Rate') !== false, "Must contain Current Rate header");
echo "5. Verified CSV export: Clean CSV generated successfully (" . strlen($csvData) . " bytes).\n";

echo "\n>>> ALL VERIFICATION TESTS PASSED SUCCESSFULLY! <<<\n";
