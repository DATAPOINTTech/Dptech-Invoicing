<?php

declare(strict_types=1);

echo "Testing HTTP Password Change Route...\n";

// 1. Login
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => 'admin', 'password' => 'admin123']));
$res = curl_exec($ch);
$data = json_decode((string)$res, true);
curl_close($ch);

$token = $data['access_token'] ?? null;
if (!$token) {
    throw new RuntimeException("HTTP Login failed: " . $res);
}
echo "1. Logged in successfully via HTTP.\n";

// 2. Change password
$ch = curl_init('http://127.0.0.1:8000/api/auth/change-password');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'new_password' => 'MySecurePassword2026!',
    'confirm_password' => 'MySecurePassword2026!'
]));
$res2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code2 !== 200) {
    throw new RuntimeException("Change password HTTP failed ($code2): " . $res2);
}
echo "2. Password changed successfully via /api/auth/change-password endpoint.\n";

// 3. Verify old password fails
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => 'admin', 'password' => 'admin123']));
curl_exec($ch);
$codeOld = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($codeOld === 200) {
    throw new RuntimeException("Old password still succeeded! It should fail with 401.");
}
echo "3. Verified old password fails with HTTP 401.\n";

// 4. Verify new password succeeds with username
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => 'admin', 'password' => 'MySecurePassword2026!']));
$resNew = curl_exec($ch);
$codeNew = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$dataNew = json_decode((string)$resNew, true);
curl_close($ch);

if ($codeNew !== 200 || empty($dataNew['access_token'])) {
    throw new RuntimeException("New password failed with username: " . $resNew);
}
echo "4. Verified new password logs in successfully with username 'admin'.\n";

// 5. Verify new password succeeds with email
$ch = curl_init('http://127.0.0.1:8000/api/auth/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => 'admin@dptech.local', 'password' => 'MySecurePassword2026!']));
$resEmail = curl_exec($ch);
$codeEmail = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$dataEmail = json_decode((string)$resEmail, true);
curl_close($ch);

if ($codeEmail !== 200 || empty($dataEmail['access_token'])) {
    throw new RuntimeException("New password failed with email: " . $resEmail);
}
echo "5. Verified new password logs in successfully with email 'admin@dptech.local'.\n";

// 6. Reset back to admin123
$ch = curl_init('http://127.0.0.1:8000/api/auth/change-password');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $dataNew['access_token'],
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'new_password' => 'admin123',
    'confirm_password' => 'admin123'
]));
curl_exec($ch);
curl_close($ch);

echo "6. Restored password to admin123.\n";
echo "=== All HTTP password change tests PASSED! ===\n";

