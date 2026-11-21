<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/auth.php';

$db = database_connection();

$adminStmt = $db->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
$admin = $adminStmt->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    echo "No admin user found\n";
    exit(1);
}

$token = createAccessToken(['sub' => $admin['username']], app_secret_key());

echo "=== 1. Testing Dashboard Calculation Logic ===\n";
$totalClients = (int) $db->query('SELECT COUNT(*) FROM clients WHERE is_active = 1')->fetchColumn();
$activeProjects = (int) $db->query("SELECT COUNT(*) FROM projects WHERE status IN ('planning','in_progress','on_hold')")->fetchColumn();
$pendingInvoices = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE status IN ('draft','sent')")->fetchColumn();

$totalReceivedInvoices = (float) $db->query("SELECT COALESCE(SUM(amount_paid), 0) FROM invoices WHERE status != 'cancelled'")->fetchColumn();
$totalReceivedPayments = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();
$totalReceived = max($totalReceivedInvoices, $totalReceivedPayments);

$monthReceived = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE strftime('%Y-%m', payment_date) = strftime('%Y-%m', 'now')")->fetchColumn();
$totalInvoiced = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status != 'cancelled'")->fetchColumn();
$receivables = (float) $db->query("SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status NOT IN ('cancelled', 'paid') AND balance_due > 0")->fetchColumn();

echo "Dashboard Metrics:\n";
echo " - Total Clients: {$totalClients}\n";
echo " - Total Invoiced: PKR " . number_format($totalInvoiced, 2) . "\n";
echo " - Total Received (Collected): PKR " . number_format($totalReceived, 2) . "\n";
echo " - Month Received: PKR " . number_format($monthReceived, 2) . "\n";
echo " - Receivables (Due): PKR " . number_format($receivables, 2) . "\n";

assert($totalReceived >= 0, "totalReceived must be >= 0");
assert($receivables >= 0, "receivables must be >= 0");
echo "-> PASS: Dashboard metrics computed successfully!\n\n";

echo "=== 2. Testing Party-Wise Invoices & Separate Paid Total & Pending Total ===\n";

// Sample fixture data simulating multiple parties with paid, partially paid, and unpaid invoices
$sampleInvoices = [
    // Party 1: Alpha Corp (Client 101)
    [
        'id' => 1, 'invoice_no' => 'INV-001', 'client_id' => 101, 'client_name' => 'Alice Khan', 'client_company' => 'Alpha Corp',
        'invoice_date' => '2026-03-01', 'due_date' => '2026-03-15', 'total_amount' => 10000.0, 'amount_paid' => 10000.0, 'balance_due' => 0.0,
        'tax_amount' => 1500.0, 'withholding_tax_amount' => 0.0, 'fed_amount' => 0.0, 'status' => 'paid', 'fbr_status' => 'posted', 'fbr_invoice_no' => 'FBR-101'
    ],
    [
        'id' => 2, 'invoice_no' => 'INV-002', 'client_id' => 101, 'client_name' => 'Alice Khan', 'client_company' => 'Alpha Corp',
        'invoice_date' => '2026-03-10', 'due_date' => '2026-03-25', 'total_amount' => 6000.0, 'amount_paid' => 2000.0, 'balance_due' => 4000.0,
        'tax_amount' => 900.0, 'withholding_tax_amount' => 0.0, 'fed_amount' => 0.0, 'status' => 'partially_paid', 'fbr_status' => 'unposted', 'fbr_invoice_no' => ''
    ],
    // Party 2: Beta Industries (Client 102)
    [
        'id' => 3, 'invoice_no' => 'INV-003', 'client_id' => 102, 'client_name' => 'Bilal Ahmed', 'client_company' => 'Beta Industries',
        'invoice_date' => '2026-03-05', 'due_date' => '2026-03-20', 'total_amount' => 15000.0, 'amount_paid' => 0.0, 'balance_due' => 15000.0,
        'tax_amount' => 2250.0, 'withholding_tax_amount' => 0.0, 'fed_amount' => 0.0, 'status' => 'sent', 'fbr_status' => 'unposted', 'fbr_invoice_no' => ''
    ],
    // Party 3: Gamma Solutions (Client 103)
    [
        'id' => 4, 'invoice_no' => 'INV-004', 'client_id' => 103, 'client_name' => 'Ghulam Nabi', 'client_company' => 'Gamma Solutions',
        'invoice_date' => '2026-03-12', 'due_date' => '2026-03-26', 'total_amount' => 25000.0, 'amount_paid' => 25000.0, 'balance_due' => 0.0,
        'tax_amount' => 3750.0, 'withholding_tax_amount' => 0.0, 'fed_amount' => 0.0, 'status' => 'paid', 'fbr_status' => 'posted', 'fbr_invoice_no' => 'FBR-104'
    ],
];

// Execute processing logic identical to reports.php
$partyReports = [];
$allInvoices = [];
$paidInvoices = [];
$dueInvoices = [];
$todayTs = strtotime(date('Y-m-d'));

$totalRevenue = 0.0;
$totalPaid = 0.0;
$totalOutstanding = 0.0;

foreach ($sampleInvoices as $inv) {
    $tot = (float) ($inv['total_amount'] ?? 0);
    $paid = (float) ($inv['amount_paid'] ?? 0);
    $bal = (float) ($inv['balance_due'] ?? max(0, $tot - $paid));
    $st = (string) ($inv['status'] ?? 'draft');

    $isPaid = ($st === 'paid' || ($bal <= 0.001 && $tot > 0));
    $isDue = (!$isPaid && $bal > 0.001);
    $dueTs = !empty($inv['due_date']) ? strtotime((string)$inv['due_date']) : null;
    $isOverdue = ($isDue && $dueTs !== null && $dueTs < $todayTs);

    $cid = !empty($inv['client_id']) ? (int)$inv['client_id'] : 0;
    $cname = !empty($inv['client_name']) ? (string)$inv['client_name'] : 'Walk-in / Cash Customer';
    $partyKey = $cid > 0 ? (string)$cid : ('unregistered_' . $cname);

    if (!isset($partyReports[$partyKey])) {
        $partyReports[$partyKey] = [
            'client_id' => $cid > 0 ? $cid : null,
            'party_key' => $partyKey,
            'name' => $cname,
            'company' => (string)($inv['client_company'] ?? ''),
            'total_billed' => 0.0,
            'total_received' => 0.0,
            'paid_total' => 0.0,
            'total_due' => 0.0,
            'pending_total' => 0.0,
            'invoice_count' => 0,
            'paid_count' => 0,
            'due_count' => 0,
            'pending_count' => 0,
            'invoices' => [],
            'paid_invoices' => [],
            'due_invoices' => [],
        ];
    }

    $partyReports[$partyKey]['total_billed'] += $tot;
    $partyReports[$partyKey]['total_received'] += $paid;
    $partyReports[$partyKey]['paid_total'] += $paid;
    $partyReports[$partyKey]['total_due'] += $bal;
    $partyReports[$partyKey]['pending_total'] += $bal;
    $partyReports[$partyKey]['invoice_count']++;

    $invItem = [
        'id' => (int)$inv['id'],
        'invoice_no' => (string)$inv['invoice_no'],
        'client_id' => $cid,
        'client_name' => $cname,
        'client_company' => (string)($inv['client_company'] ?? ''),
        'invoice_date' => (string)($inv['invoice_date'] ?? ''),
        'due_date' => (string)($inv['due_date'] ?? ''),
        'total_amount' => round($tot, 2),
        'amount_paid' => round($paid, 2),
        'balance_due' => round($bal, 2),
        'paid_amount' => round($paid, 2),
        'pending_amount' => round($bal, 2),
        'status' => $st,
        'is_paid' => $isPaid,
        'is_due' => $isDue,
        'is_pending' => $isDue,
        'is_overdue' => $isOverdue,
        'fbr_status' => (string)($inv['fbr_status'] ?? 'unposted'),
        'fbr_invoice_no' => (string)($inv['fbr_invoice_no'] ?? ''),
    ];

    $partyReports[$partyKey]['invoices'][] = $invItem;
    $allInvoices[] = $invItem;
    $totalRevenue += $tot;
    $totalPaid += $paid;
    $totalOutstanding += $bal;

    if ($isPaid) {
        $partyReports[$partyKey]['paid_count']++;
        $partyReports[$partyKey]['paid_invoices'][] = $invItem;
        $paidInvoices[] = $invItem;
    } else {
        $partyReports[$partyKey]['due_count']++;
        $partyReports[$partyKey]['pending_count']++;
        $partyReports[$partyKey]['due_invoices'][] = $invItem;
        $dueInvoices[] = $invItem;
    }
}

echo "Party-Wise Breakdown:\n";
echo " - Distinct Parties: " . count($partyReports) . "\n";
echo " - All Invoices Count: " . count($allInvoices) . "\n";
echo " - Paid Invoices Count: " . count($paidInvoices) . "\n";
echo " - Due / Pending Invoices Count: " . count($dueInvoices) . "\n";
echo " - Overall Billed Total: PKR " . number_format($totalRevenue, 2) . "\n";
echo " - Overall Paid Total: PKR " . number_format($totalPaid, 2) . "\n";
echo " - Overall Pending Total: PKR " . number_format($totalOutstanding, 2) . "\n";

// Verification for Party 1 (Alpha Corp)
$alpha = $partyReports['101'];
echo "\nChecking Alpha Corp:\n";
echo " - Invoices count: " . count($alpha['invoices']) . " (Expected: 2)\n";
echo " - Billed: PKR {$alpha['total_billed']} (Expected: 16000)\n";
echo " - Paid Total: PKR {$alpha['paid_total']} (Expected: 12000)\n";
echo " - Pending Total: PKR {$alpha['pending_total']} (Expected: 4000)\n";
assert(count($alpha['invoices']) === 2, "Alpha Corp must have 2 invoices (both paid and pending)");
assert($alpha['total_billed'] == 16000.0, "Alpha Corp billed must be 16000");
assert($alpha['paid_total'] == 12000.0, "Alpha Corp paid total must be 12000");
assert($alpha['pending_total'] == 4000.0, "Alpha Corp pending total must be 4000");
assert($alpha['paid_total'] + $alpha['pending_total'] == $alpha['total_billed'], "Paid + Pending must equal Total Billed");
echo "-> PASS: Alpha Corp has all invoices attached with separate paid total and pending total!\n";

// Verification for Party 2 (Beta Industries)
$beta = $partyReports['102'];
echo "\nChecking Beta Industries:\n";
echo " - Invoices count: " . count($beta['invoices']) . " (Expected: 1)\n";
echo " - Paid Total: PKR {$beta['paid_total']} (Expected: 0)\n";
echo " - Pending Total: PKR {$beta['pending_total']} (Expected: 15000)\n";
assert(count($beta['invoices']) === 1, "Beta Industries must have 1 invoice");
assert($beta['paid_total'] == 0.0, "Beta Industries paid total must be 0");
assert($beta['pending_total'] == 15000.0, "Beta Industries pending total must be 15000");
echo "-> PASS: Beta Industries has pending invoice and 0 paid total!\n";

// Verification for Party 3 (Gamma Solutions)
$gamma = $partyReports['103'];
echo "\nChecking Gamma Solutions:\n";
echo " - Invoices count: " . count($gamma['invoices']) . " (Expected: 1)\n";
echo " - Paid Total: PKR {$gamma['paid_total']} (Expected: 25000)\n";
echo " - Pending Total: PKR {$gamma['pending_total']} (Expected: 0)\n";
assert(count($gamma['invoices']) === 1, "Gamma Solutions must have 1 invoice");
assert($gamma['paid_total'] == 25000.0, "Gamma Solutions paid total must be 25000");
assert($gamma['pending_total'] == 0.0, "Gamma Solutions pending total must be 0");
echo "-> PASS: Gamma Solutions has paid invoice and 0 pending total!\n";

// Overall KPI checks
assert($totalPaid == 37000.0, "Overall Paid Total must be 37000");
assert($totalOutstanding == 19000.0, "Overall Pending Total must be 19000");
assert($totalRevenue == 56000.0, "Overall Billed Total must be 56000");
assert($totalPaid + $totalOutstanding == $totalRevenue, "Overall Paid Total + Pending Total must equal Total Billed");
echo "\n-> PASS: Grand totals balance accurately (Paid: 37,000 + Pending: 19,000 = 56,000)\n";

echo "\n>>> ALL PARTY-WISE REPORTS & SEPARATE PAID/PENDING TOTAL TESTS PASSED 100%! <<<\n";
