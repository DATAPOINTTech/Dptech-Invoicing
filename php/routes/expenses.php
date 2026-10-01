<?php

declare(strict_types=1);

function handle_expenses_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/expenses') {
        require_authenticated_user($db);
        $category = $_GET['category'] ?? null;
        $sql = 'SELECT e.*, p.name AS project_name, u.full_name AS created_by_name FROM expenses e LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN users u ON u.id = e.created_by';
        $params = [];
        if ($category !== null && $category !== '') {
            $sql .= ' WHERE e.category = :cat';
            $params['cat'] = $category;
        }
        $sql .= ' ORDER BY e.expense_date DESC, e.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'GET' && $path === '/api/expenses/summary') {
        require_authenticated_user($db);
        $catStmt = $db->query('SELECT category, SUM(total_amount) AS total, COUNT(*) AS count FROM expenses GROUP BY category');
        $byCategory = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        $monthStmt = $db->query("SELECT strftime('%Y-%m', expense_date) AS month, SUM(total_amount) AS total FROM expenses GROUP BY month ORDER BY month DESC LIMIT 12");
        $byMonth = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalAll = (float) $db->query('SELECT COALESCE(SUM(total_amount), 0) FROM expenses')->fetchColumn();

        json_response([
            'by_category' => $byCategory,
            'by_month' => $byMonth,
            'total_expenses' => round($totalAll, 2),
        ]);
    }

    if ($method === 'POST' && $path === '/api/expenses') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'expenses', 'create');
        $body = request_json();

        $desc = trim((string) ($body['description'] ?? ''));
        $amount = (float) ($body['amount'] ?? 0);
        if ($desc === '' || $amount <= 0) {
            json_response(['detail' => 'description and positive amount are required.'], 422);
        }

        $taxAmount = (float) ($body['tax_amount'] ?? 0);
        $total = $amount + $taxAmount;
        $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM expenses')->fetchColumn();
        $expenseNo = !empty($body['expense_no']) ? (string) $body['expense_no'] : sprintf('EXP-%s-%05d', date('Ym'), $nextId);
        $date = (string) ($body['expense_date'] ?? date('Y-m-d'));

        $stmt = $db->prepare('
            INSERT INTO expenses (
                expense_no, category, description, amount, tax_amount, total_amount,
                expense_date, payment_method, vendor_name, receipt_ref, project_id, notes, created_by, created_at, updated_at
            ) VALUES (
                :no, :cat, :desc, :amt, :tax, :total,
                :edate, :pmeth, :vendor, :receipt, :pid, :notes, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'no' => $expenseNo,
            'cat' => $body['category'] ?? 'other',
            'desc' => $desc,
            'amt' => $amount,
            'tax' => $taxAmount,
            'total' => $total,
            'edate' => $date,
            'pmeth' => $body['payment_method'] ?? 'cash',
            'vendor' => $body['vendor_name'] ?? null,
            'receipt' => $body['receipt_ref'] ?? null,
            'pid' => !empty($body['project_id']) ? (int) $body['project_id'] : null,
            'notes' => $body['notes'] ?? null,
            'uid' => $user->id,
        ]);

        json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Expense created'], 201);
    }

    if (preg_match('#^/api/expenses/([1-9][0-9]*)$#', $path, $m)) {
        $expenseId = (int) $m[1];
        if ($method === 'GET') {
            require_authenticated_user($db);
            $stmt = $db->prepare('SELECT e.*, p.name AS project_name FROM expenses e LEFT JOIN projects p ON p.id = e.project_id WHERE e.id = :id');
            $stmt->execute(['id' => $expenseId]);
            $exp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exp) {
                json_response(['detail' => 'Expense not found'], 404);
            }
            json_response($exp);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'expenses', 'delete');
            $stmt = $db->prepare('DELETE FROM expenses WHERE id = :id');
            $stmt->execute(['id' => $expenseId]);
            json_response(['message' => 'Expense deleted']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'expenses', 'edit');
            $body = request_json();
            $allowed = ['category', 'description', 'amount', 'tax_amount', 'expense_date', 'payment_method', 'vendor_name', 'receipt_ref', 'project_id', 'notes'];
            $updates = [];
            $values = ['id' => $expenseId];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = $body[$f];
                }
            }
            if (isset($body['amount']) || isset($body['tax_amount'])) {
                $curStmt = $db->prepare('SELECT amount, tax_amount FROM expenses WHERE id = :id');
                $curStmt->execute(['id' => $expenseId]);
                $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                $amt = (float) ($body['amount'] ?? $cur['amount'] ?? 0);
                $tax = (float) ($body['tax_amount'] ?? $cur['tax_amount'] ?? 0);
                $updates[] = 'total_amount = :tot';
                $values['tot'] = $amt + $tax;
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE expenses SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($values);
            }
            json_response(['message' => 'Expense updated']);
        }
    }

    return false;
}
