<?php

declare(strict_types=1);

// Command-line only: this file must never run from a web request.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * DATAPOINT Invoicing - Admin Password Reset Utility
 * 
 * Usage from cPanel Terminal, SSH, or Cron:
 *   php php/scripts/reset_admin_password.php <new_password>
 * 
 * Example:
 *   php php/scripts/reset_admin_password.php MyNewPassword123!
 */

require_once __DIR__ . '/../bootstrap.php';

echo "========================================================\n";
echo "  DATAPOINT Invoicing - Admin Password Reset Utility     \n";
echo "========================================================\n\n";

$db = database_connection();

$newPassword = $argv[1] ?? null;

if ($newPassword === null || trim($newPassword) === '') {
    echo "Usage: php reset_admin_password.php <new_password>\n";
    $newPassword = bin2hex(random_bytes(8));
    echo "No password provided. A random one was generated and is shown below.\n";
}

$newPassword = trim($newPassword);
$hash = getPasswordHash($newPassword);

$stmt = $db->prepare('SELECT id, username, email FROM users WHERE role = "admin" ORDER BY id ASC LIMIT 1');
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    // If no admin user exists, create one
    $username = trim((string) env_value('ADMIN_USERNAME', 'admin'));
    $email = trim((string) env_value('ADMIN_EMAIL', 'admin@dptech.local'));
    $ins = $db->prepare('INSERT INTO users (username, email, hashed_password, full_name, role, is_active, created_at, updated_at) VALUES (:u, :e, :h, "Administrator", "admin", 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
    $ins->execute(['u' => $username, 'e' => $email, 'h' => $hash]);
    echo "[✓] Created new Admin account:\n";
    echo "    Username: {$username}\n";
    echo "    Email:    {$email}\n";
    echo "    Password: {$newPassword}\n";
} else {
    $up = $db->prepare('UPDATE users SET hashed_password = :h, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
    $up->execute(['h' => $hash, 'id' => $admin['id']]);
    echo "[✓] Successfully updated password for Admin (User ID: {$admin['id']}):\n";
    echo "    Username: {$admin['username']}\n";
    echo "    Email:    {$admin['email']}\n";
    echo "    Password: {$newPassword}\n";
}

echo "\nYou can now log in at: http://your-domain.com/login\n";

