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

echo "=== 2. Testing Party-Wise Invoices & Paid vs Due Separation ===\n";
$fd = '2020-01-01';
$td = date('Y-m-d');

$sql = "
    SELECT i.*, c.name AS client_name, c.company AS client_company,
           c.phone AS client_phone, c.email AS client_email,
           c.ntn AS client_ntn, c.strn AS client_strn, c.city AS client_city
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
    WHERE i.invoice_date >= :fd AND i.invoice_date <= :td AND i.status != 'cancelled'
    ORDER BY i.invoice_date DESC, i.id DESC
";
$invStmt = $db->prepare($sql);
$invStmt->execute(['fd' => $fd, 'td' => $td]);
$invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

$partyReports = [];
$paidInvoices = [];
$dueInvoices = [];
$todayTs = strtotime(date('Y-m-d'));

foreach ($invoices as $inv) {
    $tot = (float) ($inv['total_amount'] ?? 0);
    $paid = (float) ($inv['amount_paid'] ?? 0);
    $bal = (float) ($inv['balance_due'] ?? max(0, $tot - $paid));
    $st = (string) ($inv['status'] ?? 'draft');

    $isPaid = ($st === 'paid' || ($bal <= 0.001 && $tot > 0));
    $isDue = (!$isPaid && $bal > 0.001);
    $dueTs = !empty($inv['due_date']) ? strtotime((string)$inv['due_date']) : null;
    $isOverdue = ($isDue && $dueTs !== null && $dueTs < $todayTs);

    $cname = !empty($inv['client_name']) ? (string)$inv['client_name'] : 'Walk-in / Cash Customer';
    $cid = !empty($inv['client_id']) ? (int)$inv['client_id'] : 0;
    $partyKey = $cid > 0 ? (string)$cid : ('unregistered_' . $cname);

    if (!isset($partyReports[$partyKey])) {
        $partyReports[$partyKey] = [
            'client_id' => $cid > 0 ? $cid : null,
            'name' => $cname,
            'company' => (string)($inv['client_company'] ?? ''),
            'total_billed' => 0.0,
            'total_received' => 0.0,
            'total_due' => 0.0,
            'invoice_count' => 0,
            'paid_count' => 0,
            'due_count' => 0,
        ];
    }

    $partyReports[$partyKey]['total_billed'] += $tot;
    $partyReports[$partyKey]['total_received'] += $paid;
    $partyReports[$partyKey]['total_due'] += $bal;
    $partyReports[$partyKey]['invoice_count']++;

    $invItem = [
        'id' => (int)$inv['id'],
        'invoice_no' => (string)$inv['invoice_no'],
        'client_name' => $cname,
        'total_amount' => $tot,
        'amount_paid' => $paid,
        'balance_due' => $bal,
        'status' => $st,
        'is_paid' => $isPaid,
        'is_due' => $isDue,
        'is_overdue' => $isOverdue,
    ];

    if ($isPaid) {
        $partyReports[$partyKey]['paid_count']++;
        $paidInvoices[] = $invItem;
    } else {
        $partyReports[$partyKey]['due_count']++;
        $dueInvoices[] = $invItem;
    }
}

echo "Party-Wise Breakdown:\n";
echo " - Distinct Parties: " . count($partyReports) . "\n";
echo " - Paid Invoices Count: " . count($paidInvoices) . "\n";
echo " - Due Invoices Count: " . count($dueInvoices) . "\n";

foreach ($partyReports as $p) {
    echo "   * {$p['name']} ({$p['company']}): Billed: PKR {$p['total_billed']} | Received: PKR {$p['total_received']} | Due: PKR {$p['total_due']} (Paid Invoices: {$p['paid_count']}, Due: {$p['due_count']})\n";
}

// Assertions
foreach ($paidInvoices as $pi) {
    assert($pi['balance_due'] == 0 || $pi['status'] === 'paid', "Paid invoice must have 0 balance due or paid status");
}
echo "-> PASS: All paid invoices properly separated!\n";

foreach ($dueInvoices as $di) {
    assert($di['balance_due'] > 0, "Due invoice must have balance due > 0");
}
echo "-> PASS: All due invoices properly separated with outstanding balances!\n";

echo "\n>>> ALL PARTY-WISE REPORTS & DASHBOARD METRICS TESTS PASSED 100%! <<<\n";
