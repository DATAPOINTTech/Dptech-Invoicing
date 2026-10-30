<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/auth.php';

$db = database_connection();

echo "=== Testing Projects Bound to Clients and Checkable Estimates/Invoices ===\n\n";

// 1. Ensure a test client exists
$clientStmt = $db->query("SELECT * FROM clients WHERE is_active = 1 LIMIT 1");
$client = $clientStmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    $db->prepare("INSERT INTO clients (name, company, email, phone, is_active, created_at, updated_at) VALUES ('Test Client Corp', 'Test Corp Ltd', 'test@corp.com', '03001234567', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute();
    $clientId = (int) $db->lastInsertId();
    $client = ['id' => $clientId, 'name' => 'Test Client Corp'];
} else {
    $clientId = (int) $client['id'];
}
echo "Using Client: [ID: {$clientId}] {$client['name']}\n";

// 2. Create a test estimate for this client
$estNo = 'EST-TEST-' . time();
$db->prepare("
    INSERT INTO estimates (
        estimate_no, client_id, title, estimate_date, valid_until,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, created_at, updated_at
    ) VALUES (
        :no, :cid, 'Network Installation Estimate', '2026-10-30', '2026-11-15',
        'draft', 50000, 0, 0, 18, 9000, 59000, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
")->execute(['no' => $estNo, 'cid' => $clientId]);
$estId = (int) $db->lastInsertId();
echo "Created Test Estimate: [ID: {$estId}] {$estNo} (PKR 59,000)\n";

// 3. Create a test invoice for this client
$invNo = 'INV-TEST-' . time();
$db->prepare("
    INSERT INTO invoices (
        invoice_no, client_id, title, invoice_date, due_date,
        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
        total_amount, amount_paid, balance_due, created_at, updated_at
    ) VALUES (
        :no, :cid, 'Network Implementation Phase 1', '2026-10-30', '2026-11-30',
        'sent', 60000, 0, 0, 18, 10800, 70800, 20000, 50800, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
")->execute(['no' => $invNo, 'cid' => $clientId]);
$invId = (int) $db->lastInsertId();
echo "Created Test Invoice: [ID: {$invId}] {$invNo} (PKR 70,800, Paid: 20,000, Due: 50,800)\n";

// 4. Test client-items query directly
echo "\n--- Step 4: Testing Client Items Retrieval ---";
$itemsEstStmt = $db->prepare('
    SELECT id, estimate_no, title, status, total_amount, estimate_date, project_id
    FROM estimates
    WHERE client_id = :cid
    ORDER BY id DESC
');
$itemsEstStmt->execute(['cid' => $clientId]);
$estimatesList = $itemsEstStmt->fetchAll(PDO::FETCH_ASSOC);

$itemsInvStmt = $db->prepare('
    SELECT id, invoice_no, title, status, total_amount, amount_paid, balance_due, invoice_date, project_id
    FROM invoices
    WHERE client_id = :cid
    ORDER BY id DESC
');
$itemsInvStmt->execute(['cid' => $clientId]);
$invoicesList = $itemsInvStmt->fetchAll(PDO::FETCH_ASSOC);

$foundEst = false;
foreach ($estimatesList as $e) {
    if ((int)$e['id'] === $estId) {
        $foundEst = true;
        break;
    }
}
$foundInv = false;
foreach ($invoicesList as $i) {
    if ((int)$i['id'] === $invId) {
        $foundInv = true;
        break;
    }
}
assert($foundEst, "Estimate should be listed for client");
assert($foundInv, "Invoice should be listed for client");
echo " -> PASS: Both items returned for client.\n";

// 5. Test Project Creation with Linked Items
echo "\n--- Step 5: Creating Project with Checked Items ---";
$projName = 'Enterprise Network Rollout ' . time();
$db->prepare("
    INSERT INTO projects (
        client_id, name, description, status, budget, start_date, end_date, created_at, updated_at
    ) VALUES (
        :cid, :name, 'Full turnkey campus rollout', 'in_progress', 150000, '2026-10-30', '2026-12-31', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )
")->execute(['cid' => $clientId, 'name' => $projName]);
$projId = (int) $db->lastInsertId();

// Link checked estimate and invoice to this project
$db->prepare('UPDATE estimates SET project_id = :pid, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND client_id = :cid')
   ->execute(['pid' => $projId, 'id' => $estId, 'cid' => $clientId]);
$db->prepare('UPDATE invoices SET project_id = :pid, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND client_id = :cid')
   ->execute(['pid' => $projId, 'id' => $invId, 'cid' => $clientId]);

// Verify DB columns updated
$checkEst = (int) $db->query("SELECT project_id FROM estimates WHERE id = {$estId}")->fetchColumn();
$checkInv = (int) $db->query("SELECT project_id FROM invoices WHERE id = {$invId}")->fetchColumn();
assert($checkEst === $projId, "Estimate project_id must equal project ID");
assert($checkInv === $projId, "Invoice project_id must equal project ID");
echo " -> PASS: Project created and items linked successfully.\n";

// 6. Test Project Financial Metrics & Linked Collections
echo "\n--- Step 6: Verifying Project Details & Financial Totals ---";
$pStmt = $db->prepare('
    SELECT p.*, c.name AS client_name, c.company AS client_company
    FROM projects p
    LEFT JOIN clients c ON c.id = p.client_id
    WHERE p.id = :id
');
$pStmt->execute(['id' => $projId]);
$project = $pStmt->fetch(PDO::FETCH_ASSOC);

$linkedEstStmt = $db->prepare('SELECT * FROM estimates WHERE project_id = :pid ORDER BY id DESC');
$linkedEstStmt->execute(['pid' => $projId]);
$linkedEsts = $linkedEstStmt->fetchAll(PDO::FETCH_ASSOC);

$linkedInvStmt = $db->prepare('SELECT * FROM invoices WHERE project_id = :pid ORDER BY id DESC');
$linkedInvStmt->execute(['pid' => $projId]);
$linkedInvs = $linkedInvStmt->fetchAll(PDO::FETCH_ASSOC);

$totEst = 0.0;
foreach ($linkedEsts as $e) { $totEst += (float) ($e['total_amount'] ?? 0); }

$totInv = 0.0;
$totRec = 0.0;
$totDue = 0.0;
foreach ($linkedInvs as $inv) {
    if (($inv['status'] ?? '') !== 'cancelled') {
        $t = (float) ($inv['total_amount'] ?? 0);
        $p = (float) ($inv['amount_paid'] ?? 0);
        $d = (float) ($inv['balance_due'] ?? max(0, $t - $p));
        $totInv += $t;
        $totRec += $p;
        $totDue += $d;
    }
}

echo "Project Financials:\n";
echo " - Client: {$project['client_name']}\n";
echo " - Total Estimated: PKR " . number_format($totEst, 2) . "\n";
echo " - Total Invoiced: PKR " . number_format($totInv, 2) . "\n";
echo " - Total Received: PKR " . number_format($totRec, 2) . "\n";
echo " - Total Due: PKR " . number_format($totDue, 2) . "\n";

assert(count($linkedEsts) === 1, "Should have 1 linked estimate");
assert(count($linkedInvs) === 1, "Should have 1 linked invoice");
assert(abs($totEst - 59000.0) < 0.01, "Total estimated must be 59,000");
assert(abs($totInv - 70800.0) < 0.01, "Total invoiced must be 70,800");
assert(abs($totRec - 20000.0) < 0.01, "Total received must be 20,000");
assert(abs($totDue - 50800.0) < 0.01, "Total due must be 50,800");
echo " -> PASS: All financial metrics correctly calculated.\n";

// 7. Test Unlinking on Project Edit (Deselecting an item)
echo "\n--- Step 7: Testing Deselect / Unlink of Items ---";
// Deselect invoice $invId (keep only $estId)
$db->prepare('UPDATE invoices SET project_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE project_id = :pid AND id = :inv_id')
   ->execute(['pid' => $projId, 'inv_id' => $invId]);

$afterInvProj = $db->query("SELECT project_id FROM invoices WHERE id = {$invId}")->fetchColumn();
assert($afterInvProj === null || $afterInvProj === false || $afterInvProj === 0, "Invoice should now be unlinked");
$afterEstProj = (int) $db->query("SELECT project_id FROM estimates WHERE id = {$estId}")->fetchColumn();
assert($afterEstProj === $projId, "Estimate should remain linked");
echo " -> PASS: Item successfully unlinked.\n";

// 8. Test Clean Unlink on Project Deletion
echo "\n--- Step 8: Testing Unlink on Project Deletion ---";
$db->prepare('UPDATE estimates SET project_id = NULL WHERE project_id = :id')->execute(['id' => $projId]);
$db->prepare('UPDATE invoices SET project_id = NULL WHERE project_id = :id')->execute(['id' => $projId]);
$db->prepare('DELETE FROM projects WHERE id = :id')->execute(['id' => $projId]);

$deletedEstProj = $db->query("SELECT project_id FROM estimates WHERE id = {$estId}")->fetchColumn();
assert($deletedEstProj === null || $deletedEstProj === false || $deletedEstProj === 0, "Estimate project_id should be NULL after project deletion");
echo " -> PASS: Project deleted and linked items preserved standalone.\n";

// Cleanup test items
$db->prepare("DELETE FROM estimates WHERE id = {$estId}")->execute();
$db->prepare("DELETE FROM invoices WHERE id = {$invId}")->execute();
echo "\n=== ALL PROJECT-CLIENT-ITEMS TESTS PASSED! ===\n";
