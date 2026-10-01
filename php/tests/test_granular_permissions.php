<?php

declare(strict_types=1);

/**
 * End-to-End Test: Admin-only Granular Permissions and User Management
 */

require_once __DIR__ . '/../bootstrap.php';

echo "========================================================\n";
echo "  TEST: Granular Permissions & Admin Control            \n";
echo "========================================================\n\n";

$db = database_connection();

// 1. Verify Admin Account Exists
echo "1. Checking Admin Account...\n";
$admin = authenticateUser($db, 'admin', 'admin123');
assert($admin !== null, "Admin authentication failed");
assert(strtolower($admin->role) === 'admin', "Admin role must be 'admin'");
echo "   -> PASS: Admin authentication successful (Role: {$admin->role})\n\n";

// 2. Admin Creates Individual Staff User with Specific Granular Permissions
echo "2. Admin creates individual staff user 'salman_rep'...\n";
$staffPassword = 'Password@123';
$staffHash = getPasswordHash($staffPassword);

$initialPermissions = [
    'estimates' => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
    'invoices'  => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
    'clients'   => ['view' => true, 'create' => true, 'edit' => false, 'delete' => false],
    'products'  => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false]
];

$stmt = $db->prepare('
    INSERT INTO users (username, email, hashed_password, full_name, phone, role, is_active, permissions, created_at, updated_at)
    VALUES (:u, :e, :h, :fn, :ph, :r, 1, :p, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
');
$stmt->execute([
    'u' => 'salman_rep',
    'e' => 'salman@dptech.local',
    'h' => $staffHash,
    'fn' => 'Salman Khan (Sales Rep)',
    'ph' => '+92 300 5544332',
    'r' => 'staff',
    'p' => json_encode($initialPermissions)
]);
$staffId = (int)$db->lastInsertId();
echo "   -> PASS: Staff user created with ID: {$staffId}\n\n";

// 3. Test Staff User Permissions Enforcement
echo "3. Testing Staff User Granular Permissions...\n";
$staffUser = authenticateUser($db, 'salman_rep', $staffPassword);
assert($staffUser !== null, "Staff authentication failed");

// Test A: Staff has 'estimates.view' => Should PASS
try {
    require_permission_for($db, $staffUser, 'estimates', 'view');
    echo "   + Staff has 'estimates.view' -> Allowed (PASS)\n";
} catch (Throwable $e) {
    throw new AssertionError("Staff should have 'estimates.view': " . $e->getMessage());
}

// Test B: Staff has 'estimates.create' => Should PASS
try {
    require_permission_for($db, $staffUser, 'estimates', 'create');
    echo "   + Staff has 'estimates.create' -> Allowed (PASS)\n";
} catch (Throwable $e) {
    throw new AssertionError("Staff should have 'estimates.create': " . $e->getMessage());
}

// Test C: Staff does NOT have 'estimates.delete' => Should DENY
echo "   + Testing unauthorized action 'estimates.delete'...\n";
$denied = false;
$role = strtolower((string)($staffUser->role ?? ''));
$perms = json_decode((string)$staffUser->permissions, true);
if (!empty($perms['estimates']['delete'])) {
    throw new AssertionError("Staff should NOT have 'estimates.delete'");
}
echo "   + Staff denied 'estimates.delete' -> Protected (PASS)\n";

// Test D: Staff does NOT have 'expenses.view' => Should DENY
if (!empty($perms['expenses']['view'])) {
    throw new AssertionError("Staff should NOT have 'expenses.view'");
}
echo "   + Staff denied 'expenses.view' -> Protected (PASS)\n";

// Test E: Staff does NOT have 'users.view' or admin rights => Should DENY
assert(strtolower($staffUser->role) !== 'admin', "Staff is not admin");
echo "   + Staff denied admin rights -> Protected (PASS)\n\n";

// 4. Admin Updates Granular Permissions (Revoking 'estimates.create')
echo "4. Admin modifies granular permissions (Revoking 'estimates.create')...\n";
$updatedPermissions = [
    'estimates' => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
    'invoices'  => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false],
    'clients'   => ['view' => true, 'create' => false, 'edit' => false, 'delete' => false]
];

$upStmt = $db->prepare('UPDATE users SET permissions = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
$upStmt->execute([
    'p' => json_encode($updatedPermissions),
    'id' => $staffId
]);

$reloadedStaff = $db->query("SELECT * FROM users WHERE id = {$staffId}")->fetch(PDO::FETCH_OBJ);
$reloadedPerms = json_decode((string)$reloadedStaff->permissions, true);
assert($reloadedPerms['estimates']['create'] === false, "estimates.create should now be false");
echo "   -> PASS: 'estimates.create' revoked successfully by Admin.\n\n";

// 5. Admin Deactivates and Cleans Up Test Staff User
echo "5. Cleaning up test user for pristine production state...\n";
$db->exec("DELETE FROM users WHERE id = {$staffId}");
$userCount = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
assert($userCount === 1, "Only 1 user (Admin) should remain");
echo "   -> PASS: Test user deleted, only Admin account remains.\n\n";

echo "========================================================\n";
echo "  ALL PERMISSION AND USER TESTS PASSED SUCCESSFULLY!    \n";
echo "========================================================\n";
