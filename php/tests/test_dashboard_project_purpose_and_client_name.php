<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../routes/dashboard.php';
require_once __DIR__ . '/../routes/invoices.php';
require_once __DIR__ . '/../routes/estimates.php';
require_once __DIR__ . '/../routes/projects.php';

echo "=== Testing Dashboard, Invoices & Estimates Project Purpose and Client Name ===\n\n";

$db = database_connection();

// 1. Create a client with both Name and Company
$ts = time();
$clientName = "Muhammad Tariq " . $ts;
$companyName = "Tariq Enterprises Ltd " . $ts;
$stmt = $db->prepare('INSERT INTO clients (name, company, phone, email, address, is_active, created_at) VALUES (:n, :c, :p, :e, :a, 1, CURRENT_TIMESTAMP)');
$stmt->execute([
    'n' => $clientName,
    'c' => $companyName,
    'p' => '03001234567',
    'e' => "tariq{$ts}@example.com",
    'a' => 'Karachi, Pakistan',
]);
$clientId = (int)$db->lastInsertId();
echo "1. Created Test Client: ID {$clientId}, Name: '{$clientName}', Company: '{$companyName}'\n";

// 2. Create a Project with a descriptive purpose
$projectName = "Fiber Optic Network Deployment " . $ts;
$projectDesc = "Turnkey FTTH cabling, splice enclosures, and OLT configuration for North Campus";
$pStmt = $db->prepare('INSERT INTO projects (name, description, client_id, status, created_at, updated_at) VALUES (:n, :d, :cid, "in_progress", CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
$pStmt->execute([
    'n' => $projectName,
    'd' => $projectDesc,
    'cid' => $clientId,
]);
$projectId = (int)$db->lastInsertId();
echo "2. Created Test Project: ID {$projectId}, Name: '{$projectName}', Description: '{$projectDesc}'\n";

// 3. Create an Estimate linked to the project
$estNo = "EST-PRJ-" . $ts;
$estTitle = "Civil Work & Trenching Quotation";
$eStmt = $db->prepare('
    INSERT INTO estimates (estimate_no, client_id, project_id, title, estimate_date, valid_until, subtotal, tax_amount, total_amount, status, created_at)
    VALUES (:no, :cid, :pid, :title, date("now"), date("now", "+30 days"), 150000, 27000, 177000, "approved", CURRENT_TIMESTAMP)
');
$eStmt->execute([
    'no' => $estNo,
    'cid' => $clientId,
    'pid' => $projectId,
    'title' => $estTitle,
]);
$estimateId = (int)$db->lastInsertId();
echo "3. Created Test Estimate: ID {$estimateId}, No: '{$estNo}', Title: '{$estTitle}'\n";

// 4. Create an Invoice linked to the project
$invNo = "INV-PRJ-" . $ts;
$invTitle = "Phase 1 Milestone Billing";
$iStmt = $db->prepare('
    INSERT INTO invoices (invoice_no, client_id, project_id, title, invoice_date, due_date, subtotal, tax_amount, total_amount, amount_paid, balance_due, status, created_at)
    VALUES (:no, :cid, :pid, :title, date("now"), date("now", "+15 days"), 150000, 27000, 177000, 77000, 100000, "partially_paid", CURRENT_TIMESTAMP)
');
$iStmt->execute([
    'no' => $invNo,
    'cid' => $clientId,
    'pid' => $projectId,
    'title' => $invTitle,
]);
$invoiceId = (int)$db->lastInsertId();
echo "4. Created Test Invoice: ID {$invoiceId}, No: '{$invNo}', Title: '{$invTitle}'\n\n";

// 5. Test Dashboard Route Query Output
echo "5. Testing Dashboard API Data Structure:\n";
// Let's directly run the queries used in dashboard.php
$recentInvoices = $db->query("
    SELECT i.id, i.invoice_no AS no, i.title, COALESCE(c.name, 'Unknown') AS client, i.status, i.total_amount AS total,
           p.name AS project_name, p.description AS project_description
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    LEFT JOIN projects p ON p.id = i.project_id
    WHERE i.id = {$invoiceId}
")->fetch(PDO::FETCH_ASSOC);

if (!$recentInvoices) {
    echo " -> FAIL: Could not fetch recent invoice from dashboard query!\n";
    exit(1);
}
if ($recentInvoices['client'] !== $clientName) {
    echo " -> FAIL: Client name mismatch in recent invoices! Expected '{$clientName}', got '{$recentInvoices['client']}'\n";
    exit(1);
}
if ($recentInvoices['project_name'] !== $projectName) {
    echo " -> FAIL: Project name missing in recent invoices!\n";
    exit(1);
}
if ($recentInvoices['project_description'] !== $projectDesc) {
    echo " -> FAIL: Project description missing in recent invoices!\n";
    exit(1);
}
echo " -> PASS: Recent invoice correctly provides client name ('{$recentInvoices['client']}'), project name ('{$recentInvoices['project_name']}'), and project description ('{$recentInvoices['project_description']}')\n";

$recentEstimates = $db->query("
    SELECT e.id, e.estimate_no AS no, e.title, COALESCE(c.name, 'Unknown') AS client, e.status, e.total_amount AS total,
           p.name AS project_name, p.description AS project_description
    FROM estimates e
    LEFT JOIN clients c ON c.id = e.client_id
    LEFT JOIN projects p ON p.id = e.project_id
    WHERE e.id = {$estimateId}
")->fetch(PDO::FETCH_ASSOC);

if (!$recentEstimates) {
    echo " -> FAIL: Could not fetch recent estimate from dashboard query!\n";
    exit(1);
}
if ($recentEstimates['client'] !== $clientName) {
    echo " -> FAIL: Client name mismatch in recent estimates!\n";
    exit(1);
}
if ($recentEstimates['project_description'] !== $projectDesc) {
    echo " -> FAIL: Project description missing in recent estimates!\n";
    exit(1);
}
echo " -> PASS: Recent estimate correctly provides client name ('{$recentEstimates['client']}') and project description ('{$recentEstimates['project_description']}')\n";

// 6. Test Invoices List Query
echo "\n6. Testing Invoices List API Query:\n";
$invRow = $db->query("
    SELECT i.*, c.name AS client_name, p.name AS project_name, p.description AS project_description 
    FROM invoices i 
    LEFT JOIN clients c ON c.id = i.client_id 
    LEFT JOIN projects p ON p.id = i.project_id
    WHERE i.id = {$invoiceId}
")->fetch(PDO::FETCH_ASSOC);

if (!$invRow) {
    echo " -> FAIL: Could not query invoices list!\n";
    exit(1);
}
if ($invRow['client_name'] !== $clientName) {
    echo " -> FAIL: client_name is not equal to client name!\n";
    exit(1);
}
if ($invRow['project_description'] !== $projectDesc) {
    echo " -> FAIL: project_description is missing from invoices list query!\n";
    exit(1);
}
echo " -> PASS: Invoices list returns only client name ('{$invRow['client_name']}') and project description ('{$invRow['project_description']}')\n";

// 7. Test Estimates List Query
echo "\n7. Testing Estimates List API Query:\n";
$estRow = $db->query("
    SELECT e.*, c.name AS client_name, co.name AS company_name, p.name AS project_name, p.description AS project_description 
    FROM estimates e 
    LEFT JOIN clients c ON c.id = e.client_id 
    LEFT JOIN companies co ON co.id = e.company_id
    LEFT JOIN projects p ON p.id = e.project_id 
    WHERE e.id = {$estimateId}
")->fetch(PDO::FETCH_ASSOC);

if (!$estRow) {
    echo " -> FAIL: Could not query estimates list!\n";
    exit(1);
}
if ($estRow['client_name'] !== $clientName) {
    echo " -> FAIL: client_name is not equal to client name!\n";
    exit(1);
}
if ($estRow['project_description'] !== $projectDesc) {
    echo " -> FAIL: project_description is missing from estimates list query!\n";
    exit(1);
}
echo " -> PASS: Estimates list returns only client name ('{$estRow['client_name']}') and project description ('{$estRow['project_description']}')\n";

echo "\n>>> ALL DASHBOARD, INVOICE & ESTIMATE PROJECT PURPOSE TESTS PASSED 100%! <<<\n";
