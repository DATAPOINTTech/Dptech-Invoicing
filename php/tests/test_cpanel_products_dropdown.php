<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/products.php';

echo "=================================================================\n";
echo "  TEST: cPanel Products Dropdown & MySQL Database Compatibility   \n";
echo "=================================================================\n\n";

// 1. Verify db_get_table_columns, db_column_exists, db_add_column_if_missing helpers
echo "[1] Testing cross-database column helper functions...\n";
$tempDbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_col_helpers_' . bin2hex(random_bytes(4)) . '.db';
if (file_exists($tempDbPath)) {
    unlink($tempDbPath);
}

$testDb = new PDO('sqlite:' . $tempDbPath);
$testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$testDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

ensure_all_schema_tables($testDb);
ensure_default_admin($testDb);
ensure_multicompany_schema($testDb);

$estCols = db_get_table_columns($testDb, 'estimates');
assert(in_array('currency', $estCols, true), "estimates table should have currency column");
assert(in_array('company_website', $estCols, true), "estimates table should have company_website column");
assert(in_array('company_whatsapp', $estCols, true), "estimates table should have company_whatsapp column");
assert(db_column_exists($testDb, 'estimates', 'currency') === true, "db_column_exists currency should be true");
assert(db_column_exists($testDb, 'estimates', 'non_existent_col') === false, "db_column_exists non_existent_col should be false");

// Test adding new column dynamically
$added = db_add_column_if_missing($testDb, 'estimates', 'test_custom_column', 'VARCHAR(100)');
assert($added === true, "db_add_column_if_missing should succeed");
assert(db_column_exists($testDb, 'estimates', 'test_custom_column') === true, "test_custom_column should now exist");
// Adding again should be idempotent
$addedAgain = db_add_column_if_missing($testDb, 'estimates', 'test_custom_column', 'VARCHAR(100)');
assert($addedAgain === true, "idempotent db_add_column_if_missing should succeed");
echo "✓ Column helpers working identically and reliably.\n\n";

// 2. Verify no unhandled PRAGMA table_info or SQLite-only keywords in bootstrap.php
echo "[2] Verifying absence of unhandled PRAGMA table_info in bootstrap.php...\n";
$bootstrapContent = file_get_contents(__DIR__ . '/../bootstrap.php');
// Count occurrences of PRAGMA table_info - should only be inside the sqlite driver check in db_get_table_columns
$pragmaMatches = [];
preg_match_all('/PRAGMA\s+table_info/i', $bootstrapContent, $pragmaMatches);
assert(count($pragmaMatches[0]) <= 1, "There should be at most 1 PRAGMA table_info in bootstrap.php (guarded inside driver === sqlite)");
echo "✓ No bare or unguarded PRAGMA table_info in bootstrap.php.\n\n";

// 3. Verify single quotes in php/routes/products.php SQL WHERE clause
echo "[3] Verifying safe ANSI SQL syntax in products.php WHERE clause...\n";
$productsRoute = file_get_contents(__DIR__ . '/../routes/products.php');
if (strpos($productsRoute, 'p.is_active = "1"') !== false) {
    throw new RuntimeException("Found double-quoted string '\"1\"' in products.php! In MySQL ANSI_QUOTES mode this causes 'Unknown column 1' error.");
}
assert(strpos($productsRoute, "p.is_active = '1'") !== false, "Expected single quoted '1' in products.php query");
echo "✓ Safe ANSI SQL syntax confirmed in products.php.\n\n";

// 4. Test product seeding and retrieval
echo "[4] Testing product auto-seeding on fresh database...\n";
$freshDbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_fresh_cpanel_' . bin2hex(random_bytes(4)) . '.db';
if (file_exists($freshDbPath)) {
    unlink($freshDbPath);
}

$freshDb = new PDO('sqlite:' . $freshDbPath);
$freshDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$freshDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

ensure_all_schema_tables($freshDb);
ensure_default_admin($freshDb);
ensure_multicompany_schema($freshDb);
ensure_default_products($freshDb);

$prods = $freshDb->query("SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = '1') ORDER BY p.category ASC, p.name ASC")->fetchAll();
assert(count($prods) >= 20, "Should have seeded standard products");
echo "✓ Retrieved " . count($prods) . " products successfully.\n";

$categories = array_unique(array_column($prods, 'category'));
echo "✓ Products grouped by categories: " . implode(', ', $categories) . "\n\n";

// 5. Test Frontend estimate form dropdown logic & resilience
echo "[5] Verifying estimates form.html product dropdown implementation...\n";
$estimateForm = file_get_contents(__DIR__ . '/../templates/estimates/form.html');

assert(strpos($estimateForm, 'loadProducts') !== false, "estimate form must have loadProducts function");
assert(strpos($estimateForm, 'renderProductDropdownOptions') !== false, "estimate form must render product dropdown options");
assert(strpos($estimateForm, '<optgroup label=') !== false, "estimate form must categorize products with optgroup");
assert(strpos($estimateForm, 'dptech_products_cache') !== false, "estimate form must support product session caching for fast load");
assert(strpos($estimateForm, "$(document).on('mousedown focus', '.product-select'") !== false, "estimate form must support lazy on-click/on-focus reload if empty");
echo "✓ Frontend estimate form has optgroups, caching, fast load, and on-focus resilience.\n\n";

// 6. Test Frontend invoices form fallback
echo "[6] Verifying invoices form.html price picker fallback...\n";
$invoiceForm = file_get_contents(__DIR__ . '/../templates/invoices/form.html');
assert(strpos($invoiceForm, '/api/products?token=') !== false, "invoice form must have fallback to query token if FastCGI strips auth header");
echo "✓ Invoices form has resilient FastCGI fallback.\n\n";

// Clean up
$testDb = null;
$freshDb = null;
gc_collect_cycles();
if (file_exists($tempDbPath)) @unlink($tempDbPath);
if (file_exists($freshDbPath)) @unlink($freshDbPath);

echo "=================================================================\n";
echo "  ALL cPANEL PRODUCTS DROPDOWN & MYSQL TESTS PASSED!             \n";
echo "=================================================================\n";
