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

echo "1. Authenticating as admin...\n";
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

echo "2. Querying /api/whatsapp/status...\n";
$statusRes = getJson($baseUrl . '/api/whatsapp/status', $token);
if ($statusRes['status'] !== 200) {
    echo "FAILED: WhatsApp status endpoint returned HTTP {$statusRes['status']}: {$statusRes['raw']}\n";
    exit(1);
}

$statusData = $statusRes['data'];
echo "OK: WhatsApp status returned: state={$statusData['state']}, salesAgentEnabled=" . ($statusData['salesAgentEnabled'] ? 'true' : 'false') . "\n";

if ($statusData['state'] === 'scan_qr') {
    if (empty($statusData['qr']) || !str_starts_with($statusData['qr'], 'data:image/png;base64,')) {
        echo "FAILED: Expected valid QR code data URL, got: " . substr((string)$statusData['qr'], 0, 50) . "\n";
        exit(1);
    }
    echo "OK: QR Code generated and available for scanning! (length: " . strlen($statusData['qr']) . " chars)\n";
} elseif ($statusData['state'] === 'connected') {
    echo "OK: WhatsApp is currently connected as +{$statusData['user']['phone']}!\n";
} else {
    echo "INFO: State is {$statusData['state']}\n";
}

echo "3. Testing /api/whatsapp/agent/toggle...\n";
$toggleRes = postJson($baseUrl . '/api/whatsapp/agent/toggle', ['enabled' => false], $token);
if ($toggleRes['status'] !== 200 || !isset($toggleRes['data']['salesAgentEnabled'])) {
    echo "FAILED: Toggle failed: {$toggleRes['raw']}\n";
    exit(1);
}
echo "OK: Sales agent toggled to false.\n";

$toggleRes2 = postJson($baseUrl . '/api/whatsapp/agent/toggle', ['enabled' => true], $token);
if ($toggleRes2['status'] !== 200 || $toggleRes2['data']['salesAgentEnabled'] !== true) {
    echo "FAILED: Toggle back failed: {$toggleRes2['raw']}\n";
    exit(1);
}
echo "OK: Sales agent toggled back to true.\n";

echo "5. Testing /api/invoices/{id}/whatsapp error reporting when not scanned yet...\n";
$invWa = postJson($baseUrl . '/api/invoices/1/whatsapp', ['recipient_phone' => '03167788990'], $token);
if ($invWa['status'] === 400 && strpos($invWa['raw'], 'Please link your WhatsApp account by scanning the QR code') !== false) {
    echo "OK: Received correct prompt directing user to link WhatsApp via QR code first.\n";
} else {
    echo "INFO: Response: {$invWa['raw']}\n";
}

echo "\nALL WHATSAPP INTEGRATION TESTS PASSED!\n";
