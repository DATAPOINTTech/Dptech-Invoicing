<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/estimates.php';
require_once __DIR__ . '/../routes/invoices.php';

echo "=================================================================\n";
echo "  TEST: Search by Estimation Name & Invoice # on Estimates/Invs  \n";
echo "=================================================================\n\n";

$db = database_connection();

// 1. Create a client for testing
$db->exec("INSERT INTO clients (name, email, phone, is_active) VALUES ('Apex CCTV Systems Ltd', 'info@apexcctv.test', '03001234567', 1)");
$clientId = (int) $db->lastInsertId();

// 2. Create an estimate with a unique Title (Estimation Name)
$uniqueEstTitle = 'Mega CCTV Surveillance Project Phase 1';
$estStmt = $db->prepare("INSERT INTO estimates (estimate_no, client_id, company_id, title, status, total_amount, subtotal, estimate_date) VALUES ('EST-SEARCH-001', :cid, 1, :title, 'converted', 85000, 85000, '2026-11-12')");
$estStmt->execute(['cid' => $clientId, 'title' => $uniqueEstTitle]);
$estimateId = (int) $db->lastInsertId();

// 3. Create an invoice linked to this estimate with a unique Invoice Number
$uniqueInvNo = 'INV-SEARCH-8899';
$invStmt = $db->prepare("INSERT INTO invoices (invoice_no, client_id, estimate_id, title, status, total_amount, balance_due, invoice_date) VALUES (:no, :cid, :eid, :title, 'unpaid', 85000, 85000, '2026-11-12')");
$invStmt->execute(['no' => $uniqueInvNo, 'cid' => $clientId, 'eid' => $estimateId, 'title' => $uniqueEstTitle]);
$invoiceId = (int) $db->lastInsertId();

echo "[1] Seeded Test Records:\n";
echo "  - Estimate: ID {$estimateId}, No 'EST-SEARCH-001', Title '{$uniqueEstTitle}'\n";
echo "  - Invoice:  ID {$invoiceId}, No '{$uniqueInvNo}', Linked to Estimate ID {$estimateId}\n\n";

// 4. Test Estimates Search by Estimation Name (Title)
echo "[2] Testing Estimate Search by Estimation Name...\n";
$qName = 'Surveillance Project';
$stmt = $db->prepare("
    SELECT e.*, c.name AS client_name, co.name AS company_name, p.name AS project_name, p.description AS project_description,
           (SELECT inv.invoice_no FROM invoices inv WHERE inv.estimate_id = e.id ORDER BY inv.id DESC LIMIT 1) AS linked_invoice_no,
           (SELECT inv.id FROM invoices inv WHERE inv.estimate_id = e.id ORDER BY inv.id DESC LIMIT 1) AS linked_invoice_id
    FROM estimates e 
    LEFT JOIN clients c ON c.id = e.client_id 
    LEFT JOIN companies co ON co.id = e.company_id 
    LEFT JOIN projects p ON p.id = e.project_id 
    WHERE 1=1 AND (e.estimate_no LIKE :q OR e.title LIKE :q OR c.name LIKE :q OR p.name LIKE :q OR p.description LIKE :q OR co.name LIKE :q OR EXISTS (SELECT 1 FROM invoices inv WHERE inv.estimate_id = e.id AND inv.invoice_no LIKE :q))
");
$stmt->execute(['q' => '%' . $qName . '%']);
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);

assert(count($res) >= 1, "Should find at least 1 estimate matching title");
$matched = array_values(array_filter($res, fn($r) => $r['id'] == $estimateId));
assert(count($matched) === 1, "Target estimate must be in search results");
assert($matched[0]['title'] === $uniqueEstTitle, "Matched title should match");
assert($matched[0]['linked_invoice_no'] === $uniqueInvNo, "Linked invoice number must be present");
echo "✓ Successfully found Estimate by Title '{$qName}' (Linked Invoice: {$matched[0]['linked_invoice_no']})\n\n";

// 5. Test Estimates Search by Invoice Number
echo "[3] Testing Estimate Search by Invoice Number...\n";
$stmt->execute(['q' => '%8899%']);
$resInv = $stmt->fetchAll(PDO::FETCH_ASSOC);

assert(count($resInv) >= 1, "Should find estimate by linked invoice number");
$matchedInv = array_values(array_filter($resInv, fn($r) => $r['id'] == $estimateId));
assert(count($matchedInv) === 1, "Target estimate must be found when searching its invoice #");
echo "✓ Successfully found Estimate by Invoice # '8899' (Estimate No: {$matchedInv[0]['estimate_no']})\n\n";

// 6. Test Invoices Search by Invoice Number
echo "[4] Testing Invoice Search by Invoice Number...\n";
$stmtInv = $db->prepare("
    SELECT i.*, c.name AS client_name, p.name AS project_name, p.description AS project_description,
           e.estimate_no AS linked_estimate_no, e.title AS linked_estimate_title
    FROM invoices i 
    LEFT JOIN clients c ON c.id = i.client_id 
    LEFT JOIN projects p ON p.id = i.project_id 
    LEFT JOIN estimates e ON e.id = i.estimate_id 
    WHERE 1=1 AND (i.invoice_no LIKE :q OR i.title LIKE :q OR c.name LIKE :q OR p.name LIKE :q OR p.description LIKE :q OR e.estimate_no LIKE :q OR e.title LIKE :q)
");
$stmtInv->execute(['q' => '%SEARCH-8899%']);
$invRes = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

assert(count($invRes) >= 1, "Should find invoice by invoice number");
$matchedInvRow = array_values(array_filter($invRes, fn($r) => $r['id'] == $invoiceId));
assert(count($matchedInvRow) === 1, "Target invoice must be found");
assert($matchedInvRow[0]['invoice_no'] === $uniqueInvNo);
echo "✓ Successfully found Invoice by Invoice Number '{$uniqueInvNo}'\n\n";

// 7. Test Invoices Search by Estimation Name (Title)
echo "[5] Testing Invoice Search by Estimation Name / Title...\n";
$stmtInv->execute(['q' => '%' . $qName . '%']);
$invNameRes = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

assert(count($invNameRes) >= 1, "Should find invoice by estimation name / title");
$matchedInvByTitle = array_values(array_filter($invNameRes, fn($r) => $r['id'] == $invoiceId));
assert(count($matchedInvByTitle) === 1, "Target invoice must be found by estimation title");
assert($matchedInvByTitle[0]['linked_estimate_no'] === 'EST-SEARCH-001');
echo "✓ Successfully found Invoice by Estimation Title '{$qName}' (Linked Estimate: {$matchedInvByTitle[0]['linked_estimate_no']})\n\n";

// 8. Test UI templates
echo "[6] Verifying Estimates and Invoices UI templates...\n";
$estTpl = file_get_contents(__DIR__ . '/../templates/estimates/list.html');
assert(strpos($estTpl, 'id="table-search"') !== false, "Estimates template must have search input");
assert(strpos($estTpl, 'id="btn-clear-search"') !== false, "Estimates template must have clear search button");
assert(strpos($estTpl, 'linked_invoice_no') !== false, "Estimates template must filter & render linked_invoice_no");
assert(strpos($estTpl, 'Estimation Name / Title, Inv #, Est #, Client...') !== false, "Estimates template placeholder updated");

$invTpl = file_get_contents(__DIR__ . '/../templates/invoices/list.html');
assert(strpos($invTpl, 'id="invoice-search"') !== false, "Invoices template must have search input");
assert(strpos($invTpl, 'id="btn-clear-invoice-search"') !== false, "Invoices template must have clear search button");
assert(strpos($invTpl, 'linked_estimate_no') !== false, "Invoices template must filter & render linked_estimate_no");
assert(strpos($invTpl, 'linked_estimate_title') !== false, "Invoices template must filter by linked_estimate_title");
assert(strpos($invTpl, 'Search by Inv #, Estimation Name / Title, Est #, Client...') !== false, "Invoices template placeholder updated");
echo "✓ UI templates contain enhanced search inputs, clear buttons, and field matchers.\n\n";

// Clean up test records
$db->exec("DELETE FROM invoices WHERE id = {$invoiceId}");
$db->exec("DELETE FROM estimates WHERE id = {$estimateId}");
$db->exec("DELETE FROM clients WHERE id = {$clientId}");

echo "=================================================================\n";
echo "  ALL ESTIMATE & INVOICE SEARCH TESTS PASSED SUCCESSFULLY!       \n";
echo "=================================================================\n";
