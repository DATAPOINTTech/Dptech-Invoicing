<?php

declare(strict_types=1);

// Runs against a throwaway SQLite database so it never touches real data.
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dptech_sheet_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);
$dbPath = str_replace('\\', '/', $tmpDir) . '/test.db';
putenv('DATABASE_URL=sqlite:///' . $dbPath);

require_once __DIR__ . '/../bootstrap.php';

echo "=== Running Google Sheet blank-rate sync tests ===\n\n";

$db = database_connection();

function write_sheet(string $dir, string $name, string $csv): string
{
    $path = str_replace('\\', '/', $dir) . '/' . $name;
    file_put_contents($path, $csv);
    return 'file://' . (str_starts_with($path, '/') ? $path : '/' . $path);
}

function product_by_sku(PDO $db, string $sku): array
{
    $stmt = $db->prepare('SELECT cost_price, unit_price, description, category FROM products WHERE sku = :sku');
    $stmt->execute(['sku' => $sku]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("Product {$sku} not found");
    }
    return $row;
}

function assert_equal(mixed $expected, mixed $actual, string $label): void
{
    if ((float) $expected !== (float) $actual && $expected !== $actual) {
        throw new RuntimeException("{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

try {
    // 1. First sync: a new product with a blank rate is still inserted (at 0).
    echo "[1] New product with blank rate is inserted at 0...\n";
    $url = write_sheet($tmpDir, 'a.csv', "Product Name,SKU,Category,Current Rate,Unit,Description\nCamera,CAM-1,CCTV,100,pcs,First\nBig NVR,NVR-64,CCTV,,pcs,No rate yet\n");
    sync_products_from_google_sheet($db, $url, 10.0);
    $nvr = product_by_sku($db, 'NVR-64');
    assert_equal(0.0, $nvr['cost_price'], 'blank-rate insert cost');
    assert_equal(0.0, $nvr['unit_price'], 'blank-rate insert price');
    echo "✓ Inserted with cost 0\n\n";

    // 2. Price entered in the app, then the sheet is re-synced with the rate still blank.
    echo "[2] Blank sheet rate keeps price set in the app...\n";
    $db->exec("UPDATE products SET cost_price = 150000, unit_price = 165000 WHERE sku = 'NVR-64'");
    $url = write_sheet($tmpDir, 'b.csv', "Product Name,SKU,Category,Current Rate,Unit,Description\nCamera,CAM-1,CCTV,200,pcs,Second\nBig NVR,NVR-64,Storage,,pcs,Updated text\n");
    $result = sync_products_from_google_sheet($db, $url, 10.0);
    if ($result['updated_count'] !== 2 || $result['created_count'] !== 0) {
        throw new RuntimeException('Expected 2 updated / 0 created, got ' . json_encode($result));
    }
    $nvr = product_by_sku($db, 'NVR-64');
    assert_equal(150000.0, $nvr['cost_price'], 'kept cost');
    assert_equal(165000.0, $nvr['unit_price'], 'kept price');
    if ($nvr['description'] !== 'Updated text' || $nvr['category'] !== 'Storage') {
        throw new RuntimeException('Description/category should still update: ' . json_encode($nvr));
    }
    echo "✓ Price kept at 150000 / 165000, description and category still updated\n\n";

    // 3. A real rate in the sheet still updates cost and selling price.
    echo "[3] Non-blank sheet rate still updates prices...\n";
    $cam = product_by_sku($db, 'CAM-1');
    assert_equal(200.0, $cam['cost_price'], 'camera cost');
    assert_equal(220.0, $cam['unit_price'], 'camera price');
    $url = write_sheet($tmpDir, 'c.csv', "Product Name,SKU,Category,Current Rate,Unit,Description\nBig NVR,NVR-64,Storage,160000,pcs,\n");
    sync_products_from_google_sheet($db, $url, 10.0);
    $nvr = product_by_sku($db, 'NVR-64');
    assert_equal(160000.0, $nvr['cost_price'], 'new cost');
    assert_equal(176000.0, $nvr['unit_price'], 'new price');
    echo "✓ Rates 200 -> 220 and 160000 -> 176000 applied (+10%)\n\n";

    echo "=== All Tests Passed Successfully! ===\n";
} finally {
    $db = null;
    foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
}
