<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

echo "=== Running Password Change & cPanel Compatibility Tests ===\n\n";

$db = database_connection();

// Reset admin to admin123 initially
$initialHash = getPasswordHash('admin123');
$db->prepare('UPDATE users SET hashed_password = :h WHERE username = "admin" OR id = 1')->execute(['h' => $initialHash]);

// 1. Initial login with admin123
echo "[1] Testing initial login with admin123...\n";
$u = authenticateUser($db, 'admin', 'admin123');
if (!$u) throw new RuntimeException("Initial admin login failed");
echo "✓ Login succeeded with username 'admin'.\n";

$uEmail = authenticateUser($db, 'admin@dptech.local', 'admin123');
if (!$uEmail) throw new RuntimeException("Initial login with email failed");
echo "✓ Login succeeded with email 'admin@dptech.local'.\n\n";

// 2. Change password to new password
echo "[2] Testing password change to 'SuperNewPass2026!'...\n";
$newHash = getPasswordHash('SuperNewPass2026!');
$db->prepare('UPDATE users SET hashed_password = :h, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
    'h' => $newHash,
    'id' => $u->id
]);

// 3. Verify old password no longer works
echo "[3] Verifying old password 'admin123' fails...\n";
$oldLogin = authenticateUser($db, 'admin', 'admin123');
if ($oldLogin !== null) {
    throw new RuntimeException("Old password 'admin123' should NOT have authenticated!");
}
echo "✓ Old password correctly rejected.\n\n";

// 4. Verify new password works
echo "[4] Verifying new password 'SuperNewPass2026!' succeeds...\n";
$newLogin = authenticateUser($db, 'admin', 'SuperNewPass2026!');
if (!$newLogin) throw new RuntimeException("New password login failed with username");
$newLoginEmail = authenticateUser($db, 'admin@dptech.local', 'SuperNewPass2026!');
if (!$newLoginEmail) throw new RuntimeException("New password login failed with email");
echo "✓ New password authenticated successfully with both username and email.\n\n";

// 5. Test cPanel phpMyAdmin Plain Text Password Simulation
echo "[5] Testing cPanel phpMyAdmin plain-text password support...\n";
$db->prepare('UPDATE users SET hashed_password = "cpanel_plain_pass" WHERE id = :id')->execute(['id' => $u->id]);
$plainLogin = authenticateUser($db, 'admin', 'cpanel_plain_pass');
if (!$plainLogin) throw new RuntimeException("cPanel plain text password failed to authenticate");

// Check if it was automatically converted to bcrypt
$stmt = $db->prepare('SELECT hashed_password FROM users WHERE id = :id');
$stmt->execute(['id' => $u->id]);
$upgradedHash = $stmt->fetchColumn();
if (!str_starts_with($upgradedHash, '$2y$')) {
    throw new RuntimeException("Plain text password was not auto-upgraded to bcrypt! Got: " . $upgradedHash);
}
echo "✓ cPanel plain-text password authenticated and auto-upgraded to bcrypt!\n\n";

// 6. Test cPanel phpMyAdmin MD5 Hash Simulation
echo "[6] Testing cPanel phpMyAdmin MD5 dropdown hash support...\n";
$md5Pass = md5('cpanel_md5_pass');
$db->prepare('UPDATE users SET hashed_password = :h WHERE id = :id')->execute(['h' => $md5Pass, 'id' => $u->id]);
$md5Login = authenticateUser($db, 'admin', 'cpanel_md5_pass');
if (!$md5Login) throw new RuntimeException("cPanel MD5 password failed to authenticate");

$stmt->execute(['id' => $u->id]);
$upgradedMd5Hash = $stmt->fetchColumn();
if (!str_starts_with($upgradedMd5Hash, '$2y$')) {
    throw new RuntimeException("MD5 password was not auto-upgraded to bcrypt! Got: " . $upgradedMd5Hash);
}
echo "✓ cPanel MD5 password authenticated and auto-upgraded to bcrypt!\n\n";

// 7. Test Emergency Reset File (reset_password.txt)
echo "[7] Testing cPanel File Manager emergency reset file (reset_password.txt)...\n";
$resetFile = PHP_APP_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reset_password.txt';
file_put_contents($resetFile, "emergency_pass_999\n");

check_emergency_password_reset($db);

if (is_file($resetFile)) {
    throw new RuntimeException("reset_password.txt was not deleted after processing!");
}

$emergencyLogin = authenticateUser($db, 'admin', 'emergency_pass_999');
if (!$emergencyLogin) throw new RuntimeException("Emergency reset password failed to authenticate");
echo "✓ Emergency reset file processed, removed, and new password authenticated!\n\n";

// 8. Revert admin to admin123 for standard usage
$finalHash = getPasswordHash('admin123');
$db->prepare('UPDATE users SET hashed_password = :h WHERE username = "admin" OR id = 1')->execute(['h' => $finalHash]);

echo "=== All 8 Password & cPanel Compatibility Tests Passed Successfully! ===\n";

