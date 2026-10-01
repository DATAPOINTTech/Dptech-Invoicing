<?php

declare(strict_types=1);

function handle_dashboard_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/dashboard') {
        require_authenticated_user($db);

        $totalClients = (int) $db->query('SELECT COUNT(*) FROM clients WHERE is_active = 1')->fetchColumn();
        $activeProjects = (int) $db->query("SELECT COUNT(*) FROM projects WHERE status IN ('planning','in_progress','on_hold')")->fetchColumn();
        $pendingInvoices = (int) $db->query("SELECT COUNT(*) FROM invoices WHERE status IN ('draft','sent')")->fetchColumn();
        $receivables = (float) $db->query("SELECT COALESCE(SUM(balance_due), 0) FROM invoices WHERE status IN ('sent','partially_paid')")->fetchColumn();

        $recentInvoices = $db->query("
            SELECT i.id, i.invoice_no AS no, COALESCE(c.name, 'Unknown') AS client, i.status, i.total_amount AS total
            FROM invoices i
            LEFT JOIN clients c ON c.id = i.client_id
            ORDER BY i.created_at DESC, i.id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentEstimates = $db->query("
            SELECT e.id, e.estimate_no AS no, COALESCE(c.name, 'Unknown') AS client, e.status, e.total_amount AS total
            FROM estimates e
            LEFT JOIN clients c ON c.id = e.client_id
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recentClients = $db->query("
            SELECT id, name, company, phone, email
            FROM clients
            WHERE is_active = 1
            ORDER BY created_at DESC, id DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $lowStock = $db->query('
            SELECT p.id, p.name, COALESCE(i.quantity, 0) AS quantity, p.min_stock_level
            FROM products p
            JOIN inventory i ON i.product_id = p.id
            WHERE p.is_active = 1 AND i.quantity <= COALESCE(p.min_stock_level, 0)
            ORDER BY i.quantity ASC
            LIMIT 20
        ')->fetchAll(PDO::FETCH_ASSOC);

        $totalExpenses = (float) $db->query('SELECT COALESCE(SUM(total_amount), 0) FROM expenses')->fetchColumn();
        $expenseCount = (int) $db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();

        $monthExpenses = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM expenses WHERE strftime('%Y-%m', expense_date) = strftime('%Y-%m', 'now')")->fetchColumn();
        $lastMonthExpenses = (float) $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM expenses WHERE strftime('%Y-%m', expense_date) = strftime('%Y-%m', 'now', '-1 month')")->fetchColumn();

        $expensesByCat = $db->query('SELECT category, SUM(total_amount) AS amount FROM expenses GROUP BY category ORDER BY amount DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
        $totalExpCat = array_sum(array_column($expensesByCat, 'amount')) ?: 1.0;
        foreach ($expensesByCat as &$cat) {
            $cat['percentage'] = round(($cat['amount'] / $totalExpCat) * 100, 1);
        }

        $recentExpenses = $db->query('SELECT description, amount, expense_date FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);

        json_response([
            'total_clients' => $totalClients,
            'active_projects' => $activeProjects,
            'pending_invoices' => $pendingInvoices,
            'total_receivables' => round($receivables, 2),
            'low_stock_count' => count($lowStock),
            'low_stock_items' => $lowStock,
            'recent_invoices' => $recentInvoices,
            'recent_estimates' => $recentEstimates,
            'recent_clients' => $recentClients,
            'total_expenses' => round($totalExpenses, 2),
            'expense_count' => $expenseCount,
            'month_expenses' => round($monthExpenses, 2),
            'last_month_expenses' => round($lastMonthExpenses, 2),
            'expenses_by_category' => $expensesByCat,
            'recent_expenses' => $recentExpenses,
        ]);
    }

    return false;
}
