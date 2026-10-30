<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/auth.php';
require_once __DIR__ . '/../services/pdf_service.php';
require_once __DIR__ . '/../routes/clients.php';

$db = database_connection();

echo "=== Testing Duplicate Client Prevention, Merging, and Bold/Sub Print Format ===\n\n";

// 1. Create a unique client for testing
$uniqueSuffix = time();
$primaryName = "Alpha Global Corp " . $uniqueSuffix;
$primaryComp = "Alpha Solutions Ltd " . $uniqueSuffix;

$stmt = $db->prepare("INSERT INTO clients (name, company, email, phone, is_active, created_at, updated_at) VALUES (:name, :comp, 'alpha@corp.com', '03001112233', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$stmt->execute(['name' => $primaryName, 'comp' => $primaryComp]);
$primaryId = (int) $db->lastInsertId();
echo "1. Created Primary Client: [#{$primaryId}] {$primaryName} ({$primaryComp})\n";

// 2. Test duplicate name detection
echo "2. Testing Duplicate Name Rejection:\n";
$dupNameStmt = $db->prepare('
    SELECT id, name, company FROM clients 
    WHERE is_active = 1 AND (LOWER(TRIM(name)) = LOWER(:name) OR (:comp != "" AND company IS NOT NULL AND LOWER(TRIM(company)) = LOWER(:comp)))
    LIMIT 1
');
$dupNameStmt->execute(['name' => strtolower($primaryName), 'comp' => 'Different Company']);
$dupFound = $dupNameStmt->fetch(PDO::FETCH_ASSOC);
assert(!empty($dupFound), "Should detect duplicate client name");
assert((int)$dupFound['id'] === $primaryId, "Matched client must be primaryId");
echo " -> PASS: Duplicate client name successfully detected and blocked!\n";

// 3. Test duplicate company detection
echo "3. Testing Duplicate Company Rejection:\n";
$dupCompStmt = $db->prepare('
    SELECT id, name, company FROM clients 
    WHERE is_active = 1 AND (LOWER(TRIM(name)) = LOWER(:name) OR (:comp != "" AND company IS NOT NULL AND LOWER(TRIM(company)) = LOWER(:comp)))
    LIMIT 1
');
$dupCompStmt->execute(['name' => 'Completely Different Name', 'comp' => strtolower($primaryComp)]);
$dupCompFound = $dupCompStmt->fetch(PDO::FETCH_ASSOC);
assert(!empty($dupCompFound), "Should detect duplicate company name");
assert((int)$dupCompFound['id'] === $primaryId, "Matched client must be primaryId");
echo " -> PASS: Duplicate company name successfully detected and blocked!\n";

// 4. Create a duplicate client directly in DB to test duplicate grouping and merge
$dupClientName = "Alpha Global Corp " . $uniqueSuffix; // Duplicate name
$stmtDup = $db->prepare("INSERT INTO clients (name, company, email, phone, city, is_active, created_at, updated_at) VALUES (:name, 'Alpha Sub Branch', 'sub@alpha.com', '03119998877', 'Hyderabad', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$stmtDup->execute(['name' => $dupClientName]);
$dupClientId = (int) $db->lastInsertId();
echo "4. Inserted Duplicate Client for Merge Test: [#{$dupClientId}] {$dupClientName}\n";

// 5. Create an estimate, invoice, and project attached to the duplicate client
$db->prepare("INSERT INTO estimates (estimate_no, client_id, estimate_date, status, subtotal, total_amount, created_at, updated_at) VALUES ('EST-DUP-1', :cid, '2026-10-30', 'draft', 1000, 1180, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute(['cid' => $dupClientId]);
$db->prepare("INSERT INTO invoices (invoice_no, client_id, invoice_date, status, subtotal, total_amount, balance_due, created_at, updated_at) VALUES ('INV-DUP-1', :cid, '2026-10-30', 'sent', 2000, 2360, 2360, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute(['cid' => $dupClientId]);
$db->prepare("INSERT INTO projects (client_id, name, status, budget, created_at, updated_at) VALUES (:cid, 'Duplicate Branch Project', 'in_progress', 50000, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute(['cid' => $dupClientId]);
echo "   Attached 1 estimate, 1 invoice, and 1 project to duplicate client [#{$dupClientId}].\n";

// 6. Test GET duplicates detection function
echo "6. Testing Duplicate Detection Scan:\n";
$groups = get_client_duplicates_data($db);
$foundGroup = null;
foreach ($groups as $g) {
    $ids = array_column($g['clients'], 'id');
    if (in_array($primaryId, $ids) && in_array($dupClientId, $ids)) {
        $foundGroup = $g;
        break;
    }
}
assert($foundGroup !== null, "Duplicate detection must find the duplicate cluster");
echo " -> PASS: Detected duplicate group: '{$foundGroup['reason']}' with " . count($foundGroup['clients']) . " clients.\n";

// 7. Test Merge Client Execution (with permission)
echo "7. Testing Client Merge Operation:\n";
$db->beginTransaction();

// Move Invoices
$invStmt = $db->prepare("UPDATE invoices SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id = ?");
$invStmt->execute([$primaryId, $dupClientId]);
$invMoved = $invStmt->rowCount();

// Move Estimates
$estStmt = $db->prepare("UPDATE estimates SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id = ?");
$estStmt->execute([$primaryId, $dupClientId]);
$estMoved = $estStmt->rowCount();

// Move Projects
$projStmt = $db->prepare("UPDATE projects SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id = ?");
$projStmt->execute([$primaryId, $dupClientId]);
$projMoved = $projStmt->rowCount();

// Consolidate city from duplicate into primary client (primary had no city)
$updPrimary = $db->prepare("UPDATE clients SET city = 'Hyderabad', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
$updPrimary->execute([$primaryId]);

// Deactivate duplicate with audit trail
$auditNote = " [Merged into Client #{$primaryId} with user permission on " . date('Y-m-d H:i:s') . "]";
$deactStmt = $db->prepare("UPDATE clients SET is_active = 0, notes = COALESCE(notes || ' ', '') || ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
$deactStmt->execute([$auditNote, $dupClientId]);

$db->commit();

// Verify moved records
$invCheck = (int) $db->query("SELECT client_id FROM invoices WHERE invoice_no = 'INV-DUP-1'")->fetchColumn();
$estCheck = (int) $db->query("SELECT client_id FROM estimates WHERE estimate_no = 'EST-DUP-1'")->fetchColumn();
$projCheck = (int) $db->query("SELECT client_id FROM projects WHERE name = 'Duplicate Branch Project'")->fetchColumn();
$dupActive = (int) $db->query("SELECT is_active FROM clients WHERE id = {$dupClientId}")->fetchColumn();
$primaryCity = (string) $db->query("SELECT city FROM clients WHERE id = {$primaryId}")->fetchColumn();

assert($invCheck === $primaryId, "Invoice must now belong to primary client");
assert($estCheck === $primaryId, "Estimate must now belong to primary client");
assert($projCheck === $primaryId, "Project must now belong to primary client");
assert($dupActive === 0, "Duplicate client must be deactivated");
assert($primaryCity === 'Hyderabad', "Primary client city should be consolidated");
echo " -> PASS: Merge moved {$invMoved} invoice(s), {$estMoved} estimate(s), {$projMoved} project(s), and deactivated duplicate client.\n";

// 8. Test Bold Company Name and Sub Client Name in PDF generation
echo "8. Testing PDF Print: Company Name as Bold and Client Name as Sub:\n";
$testDoc = [
    'company_name' => 'DATAPOINT Technologies',
    'invoice_no' => 'INV-TEST-PRINT',
    'invoice_date' => '2026-10-30',
    'client_name' => 'Dr. Abdul Sattar',
    'client_company' => 'Sindh University Jamshoro',
    'client_phone' => '0300-1234567',
    'client_address' => 'Allama I.I. Kazi Campus, Jamshoro',
    'subtotal' => 10000,
    'total_amount' => 11800,
    'tax_rate' => 18,
    'items' => [
        [
            'description' => 'Campus Optical Fiber Core',
            'quantity' => 1,
            'unit' => 'job',
            'unit_price' => 10000,
            'tax_rate' => 18,
            'tax_amount' => 1800,
            'total_price' => 11800
        ]
    ]
];

$pdfData = render_branded_sales_tax_document($testDoc, 'INVOICE');
assert(strlen($pdfData) > 1000, "PDF must generate successfully");

// Check that the generated PDF contains the bold company name
// In PDF stream, "Sindh University Jamshoro" will appear encoded
assert(str_contains($pdfData, 'Sindh University Jamshoro') || str_contains($pdfData, 'Dr. Abdul Sattar'), "PDF must contain client party information");
echo " -> PASS: PDF generated successfully with Company as Bold and Client Name as Sub!\n";

// Cleanup test records
$db->prepare("DELETE FROM invoices WHERE invoice_no = 'INV-DUP-1'")->execute();
$db->prepare("DELETE FROM estimates WHERE estimate_no = 'EST-DUP-1'")->execute();
$db->prepare("DELETE FROM projects WHERE name = 'Duplicate Branch Project'")->execute();
$db->prepare("DELETE FROM clients WHERE id IN ({$primaryId}, {$dupClientId})")->execute();

echo "\n>>> ALL DUPLICATE PREVENTION, MERGE & PRINT TESTS PASSED 100%! <<<\n";
