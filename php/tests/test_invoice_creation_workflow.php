<?php

require_once __DIR__ . '/../bootstrap.php';

$baseUrl = 'http://127.0.0.1:8000';

function postJson($url, $data, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function putJson($url, $data, $token = null) {
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

function getJson($url, $token = null) {
    $ch = curl_init($url);
    $headers = [];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

echo "1. Logging in as admin...\n";
$auth = postJson($baseUrl . '/api/auth/login', [
    'username' => 'admin',
    'password' => 'admin123'
]);

if ($auth['status'] !== 200 || empty($auth['data']['access_token'])) {
    echo "FAILED: Login failed: " . json_encode($auth) . "\n";
    exit(1);
}
$token = $auth['data']['access_token'];
echo "OK: Authenticated.\n";

echo "2. Fetching or creating client...\n";
$clientsRes = getJson($baseUrl . '/api/clients', $token);
$clientId = null;
if (!empty($clientsRes['data']) && count($clientsRes['data']) > 0) {
    $clientId = $clientsRes['data'][0]['id'];
} else {
    $newClient = postJson($baseUrl . '/api/clients', [
        'name' => 'Automated Test Client',
        'company' => 'Auto Corp',
        'email' => 'client@test.com'
    ], $token);
    $clientId = $newClient['data']['id'] ?? null;
}

if (!$clientId) {
    echo "FAILED: Could not find or create client.\n";
    exit(1);
}
echo "OK: Client ID is $clientId.\n";

echo "3. Creating new invoice with multiple items...\n";
$createRes = postJson($baseUrl . '/api/invoices', [
    'client_id' => $clientId,
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'title' => 'NETWORKING GEAR & CAMERAS - FLOOR 1',
    'discount_percent' => 5,
    'tax_rate' => 18,
    'apply_wht' => true,
    'apply_fed' => false,
    'payment_terms' => 'Net 30 Days',
    'notes' => 'Invoice created via automated test.',
    'items' => [
        [
            'description' => 'Hikvision IP Camera 4MP Bullet',
            'model_make' => 'DS-2CD2043G2-I',
            'quantity' => 4,
            'unit' => 'pcs',
            'unit_price' => 12500,
            'tax_rate' => 18
        ],
        [
            'description' => 'Cisco 24-Port Gigabit PoE+ Managed Switch',
            'model_make' => 'CBS350-24P-4G',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 145000,
            'tax_rate' => 18
        ],
        [
            'description' => 'Cat6 UTP Ethernet Cable 305m Roll',
            'model_make' => 'Schneider Actassi',
            'quantity' => 2,
            'unit' => 'roll',
            'unit_price' => 22000,
            'tax_rate' => 18
        ]
    ]
], $token);

if ($createRes['status'] !== 201) {
    echo "FAILED: Invoice creation returned status {$createRes['status']}: {$createRes['raw']}\n";
    exit(1);
}

$invoiceId = $createRes['data']['id'];
$invoiceNo = $createRes['data']['invoice_no'];
echo "OK: Created invoice #{$invoiceId} ({$invoiceNo}).\n";

echo "4. Fetching created invoice details...\n";
$getInv = getJson($baseUrl . '/api/invoices/' . $invoiceId, $token);
if ($getInv['status'] !== 200) {
    echo "FAILED: GET invoice failed: {$getInv['raw']}\n";
    exit(1);
}

$invData = $getInv['data'];
if (count($invData['items']) !== 3) {
    echo "FAILED: Expected 3 items, got " . count($invData['items']) . "\n";
    exit(1);
}

echo "OK: All 3 items saved with proper fields:\n";
foreach ($invData['items'] as $item) {
    echo "  - {$item['description']} | Model: {$item['model_make']} | Qty: {$item['quantity']} {$item['unit']} @ PKR {$item['unit_price']}\n";
}

echo "5. Updating invoice by adding a 4th item via PUT...\n";
$updatedItems = $invData['items'];
$updatedItems[] = [
    'description' => 'Professional Installation & Termination Service',
    'model_make' => 'Labor / On-site',
    'quantity' => 1,
    'unit' => 'job',
    'unit_price' => 25000,
    'tax_rate' => 18
];

$putRes = putJson($baseUrl . '/api/invoices/' . $invoiceId, [
    'title' => 'NETWORKING GEAR & CAMERAS - FLOOR 1 (UPDATED)',
    'items' => $updatedItems
], $token);

if ($putRes['status'] !== 200) {
    echo "FAILED: PUT invoice failed: {$putRes['raw']}\n";
    exit(1);
}

$getUpdated = getJson($baseUrl . '/api/invoices/' . $invoiceId, $token);
if (count($getUpdated['data']['items']) !== 4) {
    echo "FAILED: Expected 4 items after PUT, got " . count($getUpdated['data']['items']) . "\n";
    exit(1);
}
echo "OK: Successfully updated invoice with 4 items.\n";

echo "6. Testing PDF generation...\n";
$pdfRes = getJson($baseUrl . '/api/invoices/' . $invoiceId . '/pdf', $token);
if ($pdfRes['status'] !== 200 || substr($pdfRes['raw'], 0, 4) !== '%PDF') {
    echo "FAILED: PDF generation failed or returned invalid header.\n";
    exit(1);
}
echo "OK: PDF generated successfully (" . strlen($pdfRes['raw']) . " bytes).\n";

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
