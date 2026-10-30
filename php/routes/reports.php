<?php

declare(strict_types=1);

function handle_reports_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/reports') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'reports', 'view');

        $fd = $_GET['from_date'] ?? date('Y-01-01');
        $td = $_GET['to_date'] ?? date('Y-m-d');
        $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : (!empty($_GET['party_id']) ? (int)$_GET['party_id'] : null);
        $statusFilter = !empty($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : null;

        // Invoices query with client / party details
        $sql = "
            SELECT i.*, c.name AS client_name, c.company AS client_company,
                   c.phone AS client_phone, c.email AS client_email,
                   c.ntn AS client_ntn, c.strn AS client_strn, c.city AS client_city
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.invoice_date >= :fd AND i.invoice_date <= :td AND i.status != 'cancelled'
        ";
        $params = ['fd' => $fd, 'td' => $td];
        if ($clientId) {
            $sql .= " AND i.client_id = :cid";
            $params['cid'] = $clientId;
        }
        $sql .= " ORDER BY i.invoice_date DESC, i.id DESC";

        $invStmt = $db->prepare($sql);
        $invStmt->execute($params);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        // Revenue by month
        $monthly = [];
        $statusCounts = [];
        $clientRevenue = [];
        $partyReports = [];
        $paidInvoices = [];
        $dueInvoices = [];
        $todayTs = strtotime(date('Y-m-d'));

        $totalGst = 0.0;
        $totalWht = 0.0;
        $totalFed = 0.0;
        $totalRevenue = 0.0;
        $totalPaid = 0.0;
        $totalOutstanding = 0.0;

        foreach ($invoices as $inv) {
            $tot = (float) ($inv['total_amount'] ?? 0);
            $tax = (float) ($inv['tax_amount'] ?? 0);
            $paid = (float) ($inv['amount_paid'] ?? 0);
            $bal = (float) ($inv['balance_due'] ?? max(0, $tot - $paid));
            $st = (string) ($inv['status'] ?? 'draft');

            $monthKey = substr((string) $inv['invoice_date'], 0, 7);
            if (!isset($monthly[$monthKey])) {
                $monthly[$monthKey] = [
                    'month' => date('M Y', strtotime($inv['invoice_date'])),
                    'revenue' => 0.0,
                    'tax' => 0.0,
                    'invoices' => 0,
                ];
            }
            $monthly[$monthKey]['revenue'] += $tot;
            $monthly[$monthKey]['tax'] += $tax;
            $monthly[$monthKey]['invoices']++;

            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;

            $cname = !empty($inv['client_name']) ? (string)$inv['client_name'] : 'Walk-in / Cash Customer';
            $clientRevenue[$cname] = ($clientRevenue[$cname] ?? 0.0) + $tot;

            $totalGst += $tax;
            $totalWht += (float) ($inv['withholding_tax_amount'] ?? 0);
            $totalFed += (float) ($inv['fed_amount'] ?? 0);
            $totalRevenue += $tot;
            $totalPaid += $paid;
            $totalOutstanding += $bal;

            // Separation: Paid vs Due
            $isPaid = ($st === 'paid' || ($bal <= 0.001 && $tot > 0));
            $isDue = (!$isPaid && $bal > 0.001);
            $dueTs = !empty($inv['due_date']) ? strtotime((string)$inv['due_date']) : null;
            $isOverdue = ($isDue && $dueTs !== null && $dueTs < $todayTs);

            $cid = !empty($inv['client_id']) ? (int)$inv['client_id'] : 0;
            $partyKey = $cid > 0 ? (string)$cid : ('unregistered_' . $cname);

            // Party-wise summary ledger
            if (!isset($partyReports[$partyKey])) {
                $partyReports[$partyKey] = [
                    'client_id' => $cid > 0 ? $cid : null,
                    'name' => $cname,
                    'company' => (string)($inv['client_company'] ?? ''),
                    'phone' => (string)($inv['client_phone'] ?? ''),
                    'email' => (string)($inv['client_email'] ?? ''),
                    'ntn' => (string)($inv['client_ntn'] ?? ''),
                    'city' => (string)($inv['client_city'] ?? ''),
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
                'client_id' => $cid,
                'client_name' => $cname,
                'client_company' => (string)($inv['client_company'] ?? ''),
                'invoice_date' => (string)($inv['invoice_date'] ?? ''),
                'due_date' => (string)($inv['due_date'] ?? ''),
                'total_amount' => round($tot, 2),
                'amount_paid' => round($paid, 2),
                'balance_due' => round($bal, 2),
                'status' => $st,
                'is_paid' => $isPaid,
                'is_due' => $isDue,
                'is_overdue' => $isOverdue,
                'fbr_status' => (string)($inv['fbr_status'] ?? 'unposted'),
                'fbr_invoice_no' => (string)($inv['fbr_invoice_no'] ?? ''),
            ];

            if ($isPaid) {
                $partyReports[$partyKey]['paid_count']++;
                $paidInvoices[] = $invItem;
            } else {
                $partyReports[$partyKey]['due_count']++;
                $dueInvoices[] = $invItem;
            }
        }

        ksort($monthly);
        $revenueByMonth = array_values($monthly);

        arsort($clientRevenue);
        $topClients = [];
        foreach (array_slice($clientRevenue, 0, 10, true) as $name => $rev) {
            $topClients[] = ['name' => $name, 'revenue' => round($rev, 2)];
        }

        // Sort Party Reports: highest outstanding balance first, then highest billed
        $sortedParties = array_values($partyReports);
        usort($sortedParties, function($a, $b) {
            if (abs($b['total_due'] - $a['total_due']) > 0.01) {
                return $b['total_due'] <=> $a['total_due'];
            }
            return $b['total_billed'] <=> $a['total_billed'];
        });

        foreach ($sortedParties as &$p) {
            $p['total_billed'] = round($p['total_billed'], 2);
            $p['total_received'] = round($p['total_received'], 2);
            $p['total_due'] = round($p['total_due'], 2);
        }
        unset($p);

        // Fetch all active clients for filter dropdown
        $clientsList = $db->query('SELECT id, name, company FROM clients WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

        // Expenses in range
        $expStmt = $db->prepare('SELECT * FROM expenses WHERE expense_date >= :fd AND expense_date <= :td');
        $expStmt->execute(['fd' => $fd, 'td' => $td]);
        $expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);

        $expByCat = [];
        $totalExpenses = 0.0;
        foreach ($expenses as $e) {
            $cat = !empty($e['category']) ? $e['category'] : 'other';
            $amt = (float) ($e['total_amount'] ?? 0);
            $expByCat[$cat] = ($expByCat[$cat] ?? 0.0) + $amt;
            $totalExpenses += $amt;
        }
        arsort($expByCat);
        $expensesByCategory = [];
        foreach ($expByCat as $cat => $amt) {
            $expensesByCategory[] = ['category' => $cat, 'amount' => round($amt, 2)];
        }

        // Receivables aging
        $aging = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];
        $unpaidStmt = $db->query("SELECT due_date, balance_due FROM invoices WHERE status NOT IN ('cancelled','paid') AND balance_due > 0");
        foreach ($unpaidStmt->fetchAll(PDO::FETCH_ASSOC) as $unp) {
            $bal = (float) ($unp['balance_due'] ?? 0);
            if (empty($unp['due_date'])) {
                $aging['current'] += $bal;
                continue;
            }
            $dueTs = strtotime((string) $unp['due_date']);
            $diffDays = (int) (($todayTs - $dueTs) / 86400);
            if ($diffDays <= 0) {
                $aging['current'] += $bal;
            } elseif ($diffDays <= 30) {
                $aging['1_30'] += $bal;
            } elseif ($diffDays <= 60) {
                $aging['31_60'] += $bal;
            } elseif ($diffDays <= 90) {
                $aging['61_90'] += $bal;
            } else {
                $aging['over_90'] += $bal;
            }
        }
        foreach ($aging as $k => $v) {
            $aging[$k] = round($v, 2);
        }

        json_response([
            'period' => ['from' => $fd, 'to' => $td],
            'selected_client_id' => $clientId,
            'kpis' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_paid' => round($totalPaid, 2),
                'total_outstanding' => round($totalOutstanding, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_profit' => round($totalRevenue - $totalExpenses, 2),
                'invoice_count' => count($invoices),
                'paid_count' => count($paidInvoices),
                'due_count' => count($dueInvoices),
            ],
            'party_reports' => $sortedParties,
            'paid_invoices' => $paidInvoices,
            'due_invoices' => $dueInvoices,
            'clients_list' => $clientsList,
            'revenue_by_month' => $revenueByMonth,
            'invoice_status' => $statusCounts,
            'top_clients' => $topClients,
            'expenses_by_category' => $expensesByCategory,
            'tax_summary' => [
                'gst' => round($totalGst, 2),
                'wht' => round($totalWht, 2),
                'fed' => round($totalFed, 2),
            ],
            'receivables_aging' => $aging,
        ]);
    }

    return false;
}
