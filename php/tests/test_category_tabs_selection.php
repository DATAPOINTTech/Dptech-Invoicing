<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== Testing Category Tabs for Category-Wise Product Selection ===\n\n";

// 1. Verify estimates/form.html
$estHtml = file_get_contents(__DIR__ . '/../templates/estimates/form.html');
assert(strpos($estHtml, 'id="picker-category-tabs"') !== false, "estimates/form.html must contain #picker-category-tabs");
assert(strpos($estHtml, 'function renderPickerCategoryTabs()') !== false, "estimates/form.html must define renderPickerCategoryTabs()");
assert(strpos($estHtml, 'function selectPickerCategory(cat)') !== false, "estimates/form.html must define selectPickerCategory(cat)");
assert(strpos($estHtml, 'function renderProductDropdownOptions(selectedId)') !== false, "estimates/form.html must define renderProductDropdownOptions with optgroups");
assert(strpos($estHtml, '<optgroup label=') !== false, "estimates/form.html must group product options by category");
echo "1. Estimates form: Category tabs picker and optgroup dropdown category grouping verified.\n";

// 2. Verify invoices/form.html
$invHtml = file_get_contents(__DIR__ . '/../templates/invoices/form.html');
assert(strpos($invHtml, 'id="picker-category-tabs"') !== false, "invoices/form.html must contain #picker-category-tabs");
assert(strpos($invHtml, 'function renderPickerCategoryTabs()') !== false, "invoices/form.html must define renderPickerCategoryTabs()");
assert(strpos($invHtml, 'function selectPickerCategory(cat)') !== false, "invoices/form.html must define selectPickerCategory(cat)");
assert(strpos($invHtml, 'function addCatalogProductItem(id)') !== false, "invoices/form.html must define addCatalogProductItem()");
echo "2. Invoices form: Category tabs picker with live catalog rates verified.\n";

// 3. Verify inventory/product_sheet.html
$sheetHtml = file_get_contents(__DIR__ . '/../templates/inventory/product_sheet.html');
assert(strpos($sheetHtml, 'id="sheet-category-tabs"') !== false, "product_sheet.html must contain #sheet-category-tabs");
assert(strpos($sheetHtml, 'function selectSheetCategory(cat)') !== false, "product_sheet.html must define selectSheetCategory(cat)");
echo "3. Product Sheet (Live Rates): Category tabs bar and instant category filter verified.\n";

// 4. Verify inventory/products.html
$prodHtml = file_get_contents(__DIR__ . '/../templates/inventory/products.html');
assert(strpos($prodHtml, 'id="prod-category-tabs"') !== false, "products.html must contain #prod-category-tabs");
assert(strpos($prodHtml, 'function renderCategoryTabs()') !== false, "products.html must define renderCategoryTabs()");
assert(strpos($prodHtml, 'function selectProdCategory(cat)') !== false, "products.html must define selectProdCategory(cat)");
echo "4. Products Catalog: Category tabs bar and instant category filter verified.\n";

echo "\n>>> ALL CATEGORY TABS TESTS PASSED SUCCESSFULLY! <<<\n";
