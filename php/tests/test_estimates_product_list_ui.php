<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/auth.php';

// 1. Verify /api/products returns products with token
$token = createAccessToken(['sub' => 'admin', 'role' => 'admin'], app_secret_key());

$ch = curl_init('http://127.0.0.1:8000/api/products');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'X-Authorization: Bearer ' . $token,
    ],
    CURLOPT_RETURNTRANSFER => true,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "1. /api/products HTTP Status: {$code}\n";
$prods = json_decode($res, true);
if ($code === 200 && is_array($prods) && count($prods) > 0) {
    echo " -> [PASS] Returned " . count($prods) . " active products successfully!\n";
} else {
    echo " -> [FAIL] Expected active products, got code {$code}\n";
    exit(1);
}

// 2. Test fallback with ?token=
$ch = curl_init('http://127.0.0.1:8000/api/products?token=' . urlencode($token));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "2. /api/products?token=... HTTP Status: {$code}\n";
$prods2 = json_decode($res, true);
if ($code === 200 && is_array($prods2) && count($prods2) > 0) {
    echo " -> [PASS] Fallback with query token returned " . count($prods2) . " active products!\n";
} else {
    echo " -> [FAIL] Query token fallback failed!\n";
    exit(1);
}

// 3. Test /estimates/new page load
$ch = curl_init('http://127.0.0.1:8000/estimates/new');
curl_setopt_array($ch, [
    CURLOPT_COOKIE => 'token=' . urlencode($token),
    CURLOPT_RETURNTRANSFER => true,
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "3. /estimates/new HTTP Status: {$code}\n";
$pos = strpos($html, 'items-container');
echo "Snippet around items-container:\n" . substr($html, $pos, 600) . "\n";
if ($code === 200 && str_contains($html, 'Browse Product List (+10%)')) {
    echo " -> [PASS] /estimates/new contains 'Browse Product List (+10%)' button!\n";
} else {
    echo " -> [FAIL] /estimates/new did not contain expected product list button!\n";
    exit(1);
}

if (str_contains($html, 'Product List &amp; Live Rates Catalog') || str_contains($html, 'Product List / Catalog (+10%)')) {
    echo " -> [PASS] /estimates/new contains updated Product List modal tabs and headers!\n";
} else {
    echo " -> [FAIL] /estimates/new did not contain updated Product List modal!\n";
    exit(1);
}

echo "\n>>> ALL PRODUCT LIST & ESTIMATE UI VERIFICATIONS PASSED 100%! <<<\n";
