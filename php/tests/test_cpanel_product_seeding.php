<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/products.php';

echo "=== Testing Production cPanel Product Seeding and Product List API ===\n\n";

// 1. Create a fresh temporary SQLite database (simulating a clean cPanel deployment)
$tempDbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cpanel_prod_' . bin2hex(random_bytes(4)) . '.db';
if (file_exists($tempDbPath)) {
    unlink($tempDbPath);
}

$freshDb = new PDO('sqlite:' . $tempDbPath);
$freshDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$freshDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo "[1] Creating schema in fresh temporary database...\n";
ensure_all_schema_tables($freshDb);
ensure_default_admin($freshDb);
ensure_multicompany_schema($freshDb);

// Before seeding, products count must be 0
$initialCount = (int) $freshDb->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "  -> Products count before seeding: {$initialCount}\n";
if ($initialCount !== 0) {
    throw new RuntimeException("Expected 0 products in fresh db, got {$initialCount}");
}

// 2. Run ensure_default_products($freshDb)
echo "[2] Running ensure_default_products()...\n";
ensure_default_products($freshDb);

$afterCount = (int) $freshDb->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "  -> Products count after seeding: {$afterCount}\n";
if ($afterCount !== 25) {
    throw new RuntimeException("Expected 25 seeded products, got {$afterCount}");
}
echo "✓ Successfully seeded 25 standard catalog products.\n\n";

// 3. Verify sample products exist with correct prices, profit, and inventory
echo "[3] Verifying product details and inventory records...\n";
$first = $freshDb->query("SELECT * FROM products WHERE sku = 'HK-4MP-CVU'")->fetch();
if (!$first || $first['cost_price'] != 17800 || $first['unit_price'] != 19580) {
    throw new RuntimeException("First product (Hikvision ColorVu) details mismatched: " . json_encode($first));
}
echo "✓ HK-4MP-CVU verified: Cost PKR {$first['cost_price']}, Unit PKR {$first['unit_price']}\n";

$invCount = (int) $freshDb->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
echo "  -> Inventory records created: {$invCount}\n";
if ($invCount !== 25) {
    throw new RuntimeException("Expected 25 inventory records, got {$invCount}");
}
echo "✓ All 25 inventory records created.\n\n";

// 4. Test idempotency (running ensure_default_products again does not duplicate)
echo "[4] Testing idempotency of ensure_default_products()...\n";
ensure_default_products($freshDb);
$idempotentCount = (int) $freshDb->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($idempotentCount !== 25) {
    throw new RuntimeException("Idempotency test failed: count changed to {$idempotentCount}");
}
echo "✓ Idempotency confirmed (remains 25 products).\n\n";

// 5. Test GET /api/products query logic directly
echo "[5] Testing GET /api/products query logic...\n";
$hasInventory = db_table_exists($freshDb, 'inventory');
$stmt = $freshDb->query('SELECT p.*, COALESCE(i.quantity, 0) AS stock_quantity FROM products p LEFT JOIN inventory i ON i.product_id = p.id WHERE (p.is_active = 1 OR p.is_active IS NULL OR p.is_active = "1") ORDER BY p.category ASC, p.name ASC');
$rows = $stmt->fetchAll();
if (count($rows) !== 25) {
    throw new RuntimeException("Expected 25 products from query, got " . count($rows));
}
echo "✓ Query returned all 25 active products with stock_quantity.\n\n";

// Clean up temp database
$stmt = null;
$freshDb = null;
gc_collect_cycles();
if (file_exists($tempDbPath)) {
    @unlink($tempDbPath);
}

echo "=== All Product Seeding Tests PASSED Successfully! ===\n";
