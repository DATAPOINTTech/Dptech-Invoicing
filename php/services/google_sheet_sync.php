<?php

declare(strict_types=1);

/**
 * Service to handle Google Sheets sync for products and live rates.
 */

function normalize_google_sheet_csv_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    // If already direct CSV or export format
    if (str_contains($url, 'output=csv') || str_contains($url, 'format=csv')) {
        return $url;
    }

    // Published to web format: .../pubhtml -> .../pub?output=csv
    if (preg_match('#docs\.google\.com/spreadsheets/d/e/([a-zA-Z0-9_\-]+)/pubhtml#', $url, $m)) {
        return "https://docs.google.com/spreadsheets/d/e/{$m[1]}/pub?output=csv";
    }

    // Standard share / edit link: docs.google.com/spreadsheets/d/{DOC_ID}/...
    if (preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_\-]+)#', $url, $m)) {
        $docId = $m[1];
        $gidParam = '';
        if (preg_match('/[?&#]gid=([0-9]+)/', $url, $gm)) {
            $gidParam = '&gid=' . $gm[1];
        }
        return "https://docs.google.com/spreadsheets/d/{$docId}/export?format=csv" . $gidParam;
    }

    return $url;
}

/**
 * Only Google Sheets links are allowed: https, host docs.google.com.
 * This stops the importer being used to read local files (file://) or reach internal servers.
 */
function assert_google_sheet_url(string $url): void
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host !== 'docs.google.com' || isset($parts['user']) || isset($parts['port'])) {
        throw new InvalidArgumentException('Please use a Google Sheets link (https://docs.google.com/spreadsheets/d/...).');
    }
}

/**
 * Path of a CA certificate bundle to use for HTTPS, or null when the system already has one.
 * Some hosts and Windows PHP installs ship without CA certificates, which makes verified
 * HTTPS fail; the Mozilla bundle in services/cacert.pem is the fallback for those.
 */
function http_ca_bundle(): ?string
{
    foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $configured) {
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return null;
        }
    }
    foreach (['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'] as $systemBundle) {
        if (is_file($systemBundle)) {
            return null;
        }
    }
    $bundled = __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem';
    return is_file($bundled) ? $bundled : null;
}

function fetch_remote_csv(string $url, bool $allowAnyUrl = false): string
{
    $url = trim($url);
    if ($url === '') {
        throw new InvalidArgumentException('URL is empty');
    }
    // $allowAnyUrl is only for automated tests that read a local CSV file.
    if (!$allowAnyUrl) {
        assert_google_sheet_url($url);
    }

    // If curl is available
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (($caBundle = http_ca_bundle()) !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
        if (!$allowAnyUrl) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DATAPOINT-Invoicing/2.0');
        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($content !== false && $httpCode >= 200 && $httpCode < 400 && strlen((string) $content) > 0) {
            return (string) $content;
        }
        if ($err !== '') {
            throw new RuntimeException('Failed to download Google Sheet: ' . $err);
        }
        if ($httpCode >= 400) {
            throw new RuntimeException("Google Sheet returned HTTP error {$httpCode}. Please check that the sheet sharing is set to 'Anyone with the link can view' or 'Published to web as CSV'.");
        }
    }

    // Stream context fallback
    $opts = [
        'http' => [
            'method' => 'GET',
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 30,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) DATAPOINT-Invoicing/2.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    if (($caBundle = http_ca_bundle()) !== null) {
        $opts['ssl']['cafile'] = $caBundle;
    }
    $ctx = stream_context_create($opts);
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false || strlen($content) === 0) {
        throw new RuntimeException("Could not retrieve sheet data from {$url}. Please ensure the Google Sheet link is public ('Anyone with the link can view').");
    }

    return $content;
}

function clean_numeric_rate(mixed $val): float
{
    if (is_numeric($val)) {
        return (float) $val;
    }
    $str = (string) $val;
    $cleaned = preg_replace('/[^\d.]/', '', str_replace(',', '', $str));
    return is_numeric($cleaned) ? (float) $cleaned : 0.0;
}

function detect_csv_column_mapping(array $headers): array
{
    $map = [
        'name' => null,
        'rate' => null,
        'sku' => null,
        'category' => null,
        'unit' => null,
        'description' => null,
    ];

    foreach ($headers as $idx => $rawHeader) {
        $h = strtolower(trim((string) $rawHeader));
        $hClean = preg_replace('/[^a-z0-9]/', '', $h);

        // Name
        if ($map['name'] === null && in_array($hClean, ['productname', 'product', 'itemname', 'item', 'name', 'title'], true)) {
            $map['name'] = $idx;
        }

        // Rate / Purchase Price
        if ($map['rate'] === null && in_array($hClean, ['currentrate', 'rate', 'purchaseprice', 'purchase', 'costprice', 'cost', 'buyingprice', 'currentprice', 'price', 'purchaserate', 'costrate'], true)) {
            $map['rate'] = $idx;
        }

        // SKU
        if ($map['sku'] === null && in_array($hClean, ['sku', 'code', 'itemcode', 'productcode', 'partnumber', 'partno', 'model'], true)) {
            $map['sku'] = $idx;
        }

        // Category
        if ($map['category'] === null && in_array($hClean, ['category', 'cat', 'group', 'productcategory'], true)) {
            $map['category'] = $idx;
        }

        // Unit
        if ($map['unit'] === null && in_array($hClean, ['unit', 'uom', 'measure', 'units'], true)) {
            $map['unit'] = $idx;
        }

        // Description
        if ($map['description'] === null && in_array($hClean, ['description', 'desc', 'details', 'specs', 'specification'], true)) {
            $map['description'] = $idx;
        }
    }

    // Fallbacks if not detected by strict names
    if ($map['name'] === null) {
        foreach ($headers as $idx => $rawHeader) {
            $h = strtolower((string) $rawHeader);
            if (str_contains($h, 'product') || str_contains($h, 'item') || str_contains($h, 'name')) {
                $map['name'] = $idx;
                break;
            }
        }
    }
    if ($map['rate'] === null) {
        foreach ($headers as $idx => $rawHeader) {
            $h = strtolower((string) $rawHeader);
            if (str_contains($h, 'rate') || str_contains($h, 'cost') || str_contains($h, 'price') || str_contains($h, 'pkr')) {
                $map['rate'] = $idx;
                break;
            }
        }
    }

    if ($map['name'] === null && isset($headers[0])) $map['name'] = 0;
    if ($map['rate'] === null && isset($headers[1])) $map['rate'] = 1;

    return $map;
}

function parse_product_sheet_csv(string $csvContent): array
{
    // Remove BOM if present
    if (str_starts_with($csvContent, "\xEF\xBB\xBF")) {
        $csvContent = substr($csvContent, 3);
    }

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $csvContent);
    rewind($stream);

    $rows = [];
    $headerMap = null;
    $rowIndex = 0;

    while (($row = fgetcsv($stream)) !== false) {
        $rowIndex++;
        // Skip completely empty rows
        if (empty(array_filter($row, fn($c) => trim((string)$c) !== ''))) {
            continue;
        }

        if ($headerMap === null) {
            $headerMap = detect_csv_column_mapping($row);
            continue;
        }

        $name = trim((string) ($row[$headerMap['name'] ?? 0] ?? ''));
        if ($name === '') {
            continue;
        }

        $rawRate = $row[$headerMap['rate'] ?? 1] ?? '0';
        $rate = clean_numeric_rate($rawRate);

        $sku = isset($headerMap['sku']) && isset($row[$headerMap['sku']]) ? trim((string)$row[$headerMap['sku']]) : '';
        $category = isset($headerMap['category']) && isset($row[$headerMap['category']]) ? trim((string)$row[$headerMap['category']]) : 'General';
        $unit = isset($headerMap['unit']) && isset($row[$headerMap['unit']]) ? trim((string)$row[$headerMap['unit']]) : 'pcs';
        $desc = isset($headerMap['description']) && isset($row[$headerMap['description']]) ? trim((string)$row[$headerMap['description']]) : '';

        $rows[] = [
            'name' => $name,
            'sku' => $sku !== '' ? $sku : null,
            'category' => $category !== '' ? $category : 'General',
            'unit' => $unit !== '' ? $unit : 'pcs',
            'cost_price' => $rate,
            'description' => $desc,
        ];
    }

    fclose($stream);
    return $rows;
}

function sync_products_from_google_sheet(PDO $db, string $sheetUrl, float $profitPercent = 10.0, bool $allowAnyUrl = false): array
{
    $exportUrl = normalize_google_sheet_csv_url($sheetUrl);
    $csvContent = fetch_remote_csv($exportUrl, $allowAnyUrl);
    $parsedItems = parse_product_sheet_csv($csvContent);

    if (empty($parsedItems)) {
        throw new RuntimeException('No valid products found in the sheet. Please make sure the sheet contains Product Name and Rate columns.');
    }

    $updated = 0;
    $created = 0;
    $multiplier = 1.0 + ($profitPercent / 100.0);

    // Statements
    $stmtFindSku = $db->prepare('SELECT id, cost_price, unit_price FROM products WHERE sku = :sku AND sku IS NOT NULL AND sku != "" LIMIT 1');
    $stmtFindName = $db->prepare('SELECT id, cost_price, unit_price FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1');

    $updateSql = '
        UPDATE products SET
            %s
            category = CASE WHEN :cat != "" THEN :cat ELSE category END,
            unit = CASE WHEN :unit != "" THEN :unit ELSE unit END,
            description = CASE WHEN :desc != "" THEN :desc ELSE description END,
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ';
    $stmtUpdate = $db->prepare(sprintf($updateSql, 'cost_price = :cost, unit_price = :unit_price,'));
    // A blank/zero rate in the sheet must not wipe a price that was set in the app.
    $stmtUpdateKeepPrice = $db->prepare(sprintf($updateSql, ''));

    $stmtInsert = $db->prepare('
        INSERT INTO products (
            name, sku, category, unit, cost_price, unit_price, description, is_active, created_at, updated_at
        ) VALUES (
            :name, :sku, :cat, :unit, :cost, :unit_price, :desc, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
        )
    ');

    foreach ($parsedItems as $item) {
        $costPrice = (float) $item['cost_price'];
        $sellingPrice = round($costPrice * $multiplier, 2);

        $existingId = null;
        if (!empty($item['sku'])) {
            $stmtFindSku->execute(['sku' => $item['sku']]);
            $row = $stmtFindSku->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $existingId = (int) $row['id'];
            }
        }

        if ($existingId === null) {
            $stmtFindName->execute(['name' => $item['name']]);
            $row = $stmtFindName->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $existingId = (int) $row['id'];
            }
        }

        if ($existingId !== null) {
            $params = [
                'cat' => $item['category'] ?? '',
                'unit' => $item['unit'] ?? '',
                'desc' => $item['description'] ?? '',
                'id' => $existingId,
            ];
            if ($costPrice > 0) {
                $stmtUpdate->execute($params + ['cost' => $costPrice, 'unit_price' => $sellingPrice]);
            } else {
                $stmtUpdateKeepPrice->execute($params);
            }
            $updated++;
        } else {
            $stmtInsert->execute([
                'name' => $item['name'],
                'sku' => $item['sku'],
                'cat' => $item['category'],
                'unit' => $item['unit'],
                'cost' => $costPrice,
                'unit_price' => $sellingPrice,
                'desc' => $item['description'],
            ]);
            $newId = (int) $db->lastInsertId();
            get_or_create_inventory($db, $newId);
            $created++;
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    set_app_setting($db, 'google_sheet_url', $sheetUrl);
    set_app_setting($db, 'google_sheet_last_synced', $timestamp);
    set_app_setting($db, 'product_profit_percent', (string) $profitPercent);

    return [
        'success' => true,
        'message' => "Successfully synced {$created} new and {$updated} updated products from Google Sheet.",
        'total_rows' => count($parsedItems),
        'created_count' => $created,
        'updated_count' => $updated,
        'last_synced_at' => $timestamp,
        'profit_percent' => $profitPercent,
    ];
}

function export_products_to_csv(PDO $db): string
{
    $stmt = $db->query('SELECT name, sku, category, cost_price, unit_price, unit, description FROM products WHERE is_active = 1 ORDER BY category ASC, name ASC');
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = fopen('php://memory', 'r+');
    fputcsv($out, ['Product Name', 'SKU', 'Category', 'Current Rate (Purchase Price)', 'Selling Price (+10% Profit)', 'Unit', 'Description']);

    foreach ($products as $p) {
        fputcsv($out, [
            $p['name'],
            $p['sku'] ?? '',
            $p['category'] ?? 'General',
            $p['cost_price'],
            $p['unit_price'],
            $p['unit'] ?? 'pcs',
            $p['description'] ?? '',
        ]);
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

