<?php

declare(strict_types=1);

function handle_reports_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/reports') {
        require_authenticated_user($db);

        $fd = $_GET['from_date'] ?? date('Y-01-01');
        $td = $_GET['to_date'] ?? date('Y-m-d');

        // Invoices in range
        $invStmt = $db->prepare("
            SELECT i.*, c.name AS client_name
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.invoice_date >= :fd AND i.invoice_date <= :td AND i.status != 'cancelled'
            ORDER BY i.invoice_date ASC
        ");
        $invStmt->execute(['fd' => $fd, 'td' => $td]);
        $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        // Revenue by month
        $monthly = [];
        $statusCounts = [];
        $clientRevenue = [];
        $totalGst = 0.0;
        $totalWht = 0.0;
        $totalFed = 0.0;
        $totalRevenue = 0.0;
        $totalPaid = 0.0;
        $totalOutstanding = 0.0;

        foreach ($invoices as $inv) {
            $monthKey = substr((string) $inv['invoice_date'], 0, 7);
            if (!isset($monthly[$monthKey])) {
                $monthly[$monthKey] = [
                    'month' => date('M Y', strtotime($inv['invoice_date'])),
                    'revenue' => 0.0,
                    'tax' => 0.0,
                    'invoices' => 0,
                ];
            }
            $tot = (float) ($inv['total_amount'] ?? 0);
            $tax = (float) ($inv['tax_amount'] ?? 0);
            $paid = (float) ($inv['amount_paid'] ?? 0);
            $bal = (float) ($inv['balance_due'] ?? 0);

            $monthly[$monthKey]['revenue'] += $tot;
            $monthly[$monthKey]['tax'] += $tax;
            $monthly[$monthKey]['invoices']++;

            $st = (string) ($inv['status'] ?? 'draft');
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;

            $cname = !empty($inv['client_name']) ? $inv['client_name'] : 'Unknown';
            $clientRevenue[$cname] = ($clientRevenue[$cname] ?? 0.0) + $tot;

            $totalGst += $tax;
            $totalWht += (float) ($inv['withholding_tax_amount'] ?? 0);
            $totalFed += (float) ($inv['fed_amount'] ?? 0);
            $totalRevenue += $tot;
            $totalPaid += $paid;
            $totalOutstanding += $bal;
        }

        ksort($monthly);
        $revenueByMonth = array_values($monthly);

        arsort($clientRevenue);
        $topClients = [];
        foreach (array_slice($clientRevenue, 0, 10, true) as $name => $rev) {
            $topClients[] = ['name' => $name, 'revenue' => round($rev, 2)];
        }

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
        $unpaidStmt = $db->query("SELECT due_date, balance_due FROM invoices WHERE status IN ('sent','partially_paid')");
        $todayTs = strtotime(date('Y-m-d'));
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
            'kpis' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_paid' => round($totalPaid, 2),
                'total_outstanding' => round($totalOutstanding, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_profit' => round($totalRevenue - $totalExpenses, 2),
                'invoice_count' => count($invoices),
            ],
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
