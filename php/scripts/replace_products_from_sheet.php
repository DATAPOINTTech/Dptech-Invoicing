<?php

declare(strict_types=1);

// Command-line only: this file must never run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * DATAPOINT Invoicing - Replace all products with a Google Sheet
 *
 * Clears the products catalogue and re-imports it from a shared Google Sheet.
 * Only the products and inventory tables are touched (clients, estimates,
 * invoices, purchases, the Prices page etc. are left alone).
 *
 * Safe by default: without --apply it only prints what it WOULD do.
 *
 * Usage from cPanel Terminal, SSH, or a one-off Cron job:
 *   php php/scripts/replace_products_from_sheet.php --sheet-url="<link>"            (preview)
 *   php php/scripts/replace_products_from_sheet.php --sheet-url="<link>" --apply    (do it)
 *
 * Options:
 *   --sheet-url=URL        Shared Google Sheet link (defaults to the link saved in the app)
 *   --set-price=SKU:RATE   After the import, give SKU this purchase rate if the sheet left it
 *                          blank (selling price = rate + the app's profit %). Repeatable.
 *   --apply                Actually delete and import.
 *
 * What happens with --apply:
 *   1. Saves a JSON snapshot of products + inventory to storage/ (aborts if it cannot).
 *   2. Products that no estimate/invoice/purchase/stock record uses are deleted.
 *      Products that ARE used are only deactivated (hidden) so old documents stay intact.
 *   3. Imports every row of the sheet (existing SKU/name matches are re-activated).
 */

require_once __DIR__ . '/../bootstrap.php';

const PRODUCT_REFERENCE_TABLES = ['estimate_items', 'invoice_items', 'purchase_items', 'stock_movements'];

function table_has_product_id(PDO $db, string $table): bool
{
    try {
        $db->query("SELECT product_id FROM {$table} WHERE 1 = 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function referenced_product_ids(PDO $db): array
{
    $ids = [];
    foreach (PRODUCT_REFERENCE_TABLES as $table) {
        if (!table_has_product_id($db, $table)) {
            continue;
        }
        foreach ($db->query("SELECT DISTINCT product_id FROM {$table} WHERE product_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }
    }
    return $ids;
}

function delete_by_ids(PDO $db, string $table, string $column, array $ids): int
{
    $deleted = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare("DELETE FROM {$table} WHERE {$column} IN ({$marks})");
        $stmt->execute($chunk);
        $deleted += $stmt->rowCount();
    }
    return $deleted;
}

// ---- Arguments -----------------------------------------------------------------------------

$apply = false;
$sheetUrl = '';
$setPrices = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (str_starts_with($arg, '--sheet-url=')) {
        $sheetUrl = trim(substr($arg, strlen('--sheet-url=')), " \t\"'");
    } elseif (str_starts_with($arg, '--set-price=')) {
        $pair = substr($arg, strlen('--set-price='));
        $pos = strrpos($pair, ':');
        if ($pos === false || !is_numeric(substr($pair, $pos + 1))) {
            fwrite(STDERR, "Bad --set-price value '{$pair}'. Expected SKU:RATE, e.g. HK-NVR-64:150000\n");
            exit(1);
        }
        $setPrices[substr($pair, 0, $pos)] = (float) substr($pair, $pos + 1);
    } else {
        fwrite(STDERR, "Unknown option '{$arg}'.\n");
        exit(1);
    }
}

echo "========================================================\n";
echo "  DATAPOINT Invoicing - Replace Products From Google Sheet\n";
echo "  Mode: " . ($apply ? "APPLY (changes will be made)" : "PREVIEW ONLY (nothing is changed)") . "\n";
echo "========================================================\n\n";

$db = database_connection();

if ($sheetUrl === '') {
    $sheetUrl = (string) get_app_setting($db, 'google_sheet_url', '');
}
if ($sheetUrl === '') {
    fwrite(STDERR, "No sheet link given and none saved in the app. Pass --sheet-url=\"<link>\".\n");
    exit(1);
}
$margin = (float) get_app_setting($db, 'product_profit_percent', '10.0');

// ---- Look at what is there now -------------------------------------------------------------

$products = $db->query('SELECT * FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$allIds = array_map(fn($p) => (int) $p['id'], $products);
$referenced = referenced_product_ids($db);
$keepIds = array_values(array_filter($allIds, fn($id) => isset($referenced[$id])));
$deleteIds = array_values(array_filter($allIds, fn($id) => !isset($referenced[$id])));

echo "Products now in this database : " . count($products) . "\n";
echo "  - not used by any document   : " . count($deleteIds) . "  (will be DELETED)\n";
echo "  - used by estimates/invoices/purchases/stock : " . count($keepIds) . "  (will only be HIDDEN)\n";
echo "Profit margin applied          : {$margin}%\n\n";

// ---- Read the sheet (needs internet from this server) ---------------------------------------

echo "Reading the Google Sheet...\n";
try {
    $rows = parse_product_sheet_csv(fetch_remote_csv(normalize_google_sheet_csv_url($sheetUrl)));
} catch (Throwable $e) {
    fwrite(STDERR, "Could not read the sheet: " . $e->getMessage() . "\nNothing was changed.\n");
    exit(1);
}
if ($rows === []) {
    fwrite(STDERR, "The sheet has no product rows. Nothing was changed.\n");
    exit(1);
}
echo "Sheet products found           : " . count($rows) . "\n";
foreach ($rows as $r) {
    $rate = $r['cost_price'] > 0 ? number_format((float) $r['cost_price'], 2) : '(blank)';
    echo sprintf("  - %-60s %-14s %s\n", mb_substr($r['name'], 0, 60), $r['sku'] ?? '-', $rate);
}
echo "\n";

if (!$apply) {
    echo "PREVIEW ONLY - nothing was changed. Run again with --apply to do it.\n";
    exit(0);
}

// ---- 1. Snapshot ---------------------------------------------------------------------------

$storageDir = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage';
$snapshotPath = $storageDir . DIRECTORY_SEPARATOR . 'products_backup_' . date('Ymd_His') . '.json';
$inventory = $db->query('SELECT * FROM inventory')->fetchAll(PDO::FETCH_ASSOC);
$written = is_dir($storageDir) && is_writable($storageDir)
    ? @file_put_contents($snapshotPath, json_encode(['products' => $products, 'inventory' => $inventory], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
    : false;
if ($written === false) {
    fwrite(STDERR, "Could not write a snapshot to {$storageDir}. Aborting so nothing is lost. Nothing was changed.\n");
    exit(1);
}
echo "[1] Snapshot saved: {$snapshotPath}\n";

// ---- 2. Clear products ---------------------------------------------------------------------

$db->beginTransaction();
try {
    $deletedInventory = delete_by_ids($db, 'inventory', 'product_id', $deleteIds);
    $deletedProducts = delete_by_ids($db, 'products', 'id', $deleteIds);
    $hidden = 0;
    foreach (array_chunk($keepIds, 500) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare("UPDATE products SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$marks})");
        $stmt->execute($chunk);
        $hidden += $stmt->rowCount();
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, "Clearing products failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}
echo "[2] Deleted {$deletedProducts} products and {$deletedInventory} inventory rows; hid {$hidden} used by documents.\n";

// ---- 3. Import -----------------------------------------------------------------------------

try {
    $result = sync_products_from_google_sheet($db, $sheetUrl, $margin);
} catch (Throwable $e) {
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\nProducts were already cleared; restore from {$snapshotPath} if needed.\n");
    exit(1);
}
echo "[3] " . $result['message'] . "\n";

// ---- 4. Fill in rates the sheet left blank -------------------------------------------------

foreach ($setPrices as $sku => $rate) {
    $stmt = $db->prepare('UPDATE products SET cost_price = :c, unit_price = :u, updated_at = CURRENT_TIMESTAMP WHERE sku = :sku AND is_active = 1 AND (cost_price IS NULL OR cost_price <= 0)');
    $stmt->execute(['c' => $rate, 'u' => round($rate * (1 + $margin / 100), 2), 'sku' => $sku]);
    echo "[4] {$sku}: " . ($stmt->rowCount() > 0 ? "rate set to " . number_format($rate, 2) . " (+{$margin}% selling price)" : "left as is (not found, or the sheet already has a rate)") . "\n";
}

echo "\nActive products now: " . $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn() . "\n";
echo "Done.\n";
