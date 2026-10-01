<?php

declare(strict_types=1);

function handle_purchases_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/purchases') {
        require_authenticated_user($db);
        $status = $_GET['status'] ?? null;
        $sql = 'SELECT p.*, s.name AS supplier_display_name FROM purchase_invoices p LEFT JOIN suppliers s ON s.id = p.supplier_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE p.status = :st';
            $params['st'] = $status;
        }
        $sql .= ' ORDER BY p.invoice_date DESC, p.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/purchases') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'purchases', 'create');
        $body = request_json();

        $items = $body['items'] ?? [];
        if (!is_array($items) || $items === []) {
            json_response(['detail' => 'At least one item is required.'], 422);
        }

        $supplierName = trim((string) ($body['supplier_name'] ?? ''));
        $supplierId = !empty($body['supplier_id']) ? (int) $body['supplier_id'] : null;
        if ($supplierId && $supplierName === '') {
            $st = $db->prepare('SELECT name FROM suppliers WHERE id = :id');
            $st->execute(['id' => $supplierId]);
            $supplierName = (string) ($st->fetchColumn() ?: 'Unknown Supplier');
        }
        if ($supplierName === '') {
            $supplierName = 'Cash Supplier';
        }

        $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM purchase_invoices')->fetchColumn();
        $invoiceNo = !empty($body['invoice_no']) ? (string) $body['invoice_no'] : sprintf('PUR-%s-%05d', date('Ym'), $nextId);
        $date = (string) ($body['invoice_date'] ?? date('Y-m-d'));
        $receivedDate = !empty($body['received_date']) ? (string) $body['received_date'] : $date;

        $subtotal = 0.0;
        $totalTax = 0.0;
        $processedItems = [];
        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 0);
            $price = (float) ($item['unit_price'] ?? 0);
            $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 17.0;
            $lineSubtotal = $qty * $price;
            $lineTax = $lineSubtotal * ($taxRate / 100);
            $lineTotal = $lineSubtotal + $lineTax;

            $subtotal += $lineSubtotal;
            $totalTax += $lineTax;
            $processedItems[] = [
                'product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'product_name' => trim((string) ($item['product_name'] ?? $item['description'] ?? 'Item')),
                'description' => trim((string) ($item['description'] ?? '')),
                'quantity' => $qty,
                'unit' => (string) ($item['unit'] ?? 'pcs'),
                'unit_price' => $price,
                'tax_rate' => $taxRate,
                'tax_amount' => round($lineTax, 2),
                'total_price' => round($lineTotal, 2),
            ];
        }

        $totalAmount = $subtotal + $totalTax;
        $taxRateAvg = $subtotal > 0 ? round(($totalTax / $subtotal) * 100, 2) : 17.0;

        $stmt = $db->prepare('
            INSERT INTO purchase_invoices (
                invoice_no, supplier_id, supplier_name, supplier_ntn, supplier_address,
                invoice_date, received_date, status, subtotal, tax_amount, tax_rate,
                total_amount, amount_paid, balance_due, notes, created_by, created_at, updated_at
            ) VALUES (
                :no, :sid, :sname, :sntn, :saddr,
                :idate, :rdate, :status, :subtotal, :tax_amt, :tax_rate,
                :total, 0, :total, :notes, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'no' => $invoiceNo,
            'sid' => $supplierId,
            'sname' => $supplierName,
            'sntn' => $body['supplier_ntn'] ?? null,
            'saddr' => $body['supplier_address'] ?? null,
            'idate' => $date,
            'rdate' => $receivedDate,
            'status' => 'received',
            'subtotal' => round($subtotal, 2),
            'tax_amt' => round($totalTax, 2),
            'tax_rate' => $taxRateAvg,
            'total' => round($totalAmount, 2),
            'notes' => $body['notes'] ?? null,
            'uid' => $user->id,
        ]);
        $purchaseId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare('
            INSERT INTO purchase_items (
                purchase_id, product_id, product_name, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
            ) VALUES (
                :pid, :prid, :pname, :desc, :qty, :unit, :price, :tr, :ta, :tp
            )
        ');
        foreach ($processedItems as $pi) {
            $itemStmt->execute([
                'pid' => $purchaseId,
                'prid' => $pi['product_id'],
                'pname' => $pi['product_name'],
                'desc' => $pi['description'],
                'qty' => $pi['quantity'],
                'unit' => $pi['unit'],
                'price' => $pi['unit_price'],
                'tr' => $pi['tax_rate'],
                'ta' => $pi['tax_amount'],
                'tp' => $pi['total_price'],
            ]);

            // Add stock if product exists
            if ($pi['product_id']) {
                try {
                    update_stock($db, $pi['product_id'], $pi['quantity'], MovementType::PURCHASE_IN, 'purchase', $purchaseId, "Purchase {$invoiceNo}", $user->id);
                } catch (Exception $e) {
                    // Ignore stock error if product id doesn't match
                }
            }
        }

        json_response(['message' => 'Purchase created', 'id' => $purchaseId, 'invoice_no' => $invoiceNo], 201);
    }

    if (preg_match('#^/api/purchases/([1-9][0-9]*)(/.*)?$#', $path, $m)) {
        $purchaseId = (int) $m[1];
        $sub = $m[2] ?? '';
        $user = require_authenticated_user($db);

        if ($sub === '' && $method === 'GET') {
            $stmt = $db->prepare('SELECT p.*, s.name AS supplier_display_name FROM purchase_invoices p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = :id');
            $stmt->execute(['id' => $purchaseId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$purchase) {
                json_response(['detail' => 'Purchase not found'], 404);
            }

            $itemStmt = $db->prepare('SELECT * FROM purchase_items WHERE purchase_id = :id');
            $itemStmt->execute(['id' => $purchaseId]);
            $purchase['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $payStmt = $db->prepare('SELECT * FROM purchase_payments WHERE purchase_id = :id ORDER BY payment_date DESC, id DESC');
            $payStmt->execute(['id' => $purchaseId]);
            $purchase['payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            json_response($purchase);
        }

        if ($sub === '/pay' && $method === 'POST') {
            require_permission_for($db, $user, 'purchases', 'edit');
            $body = request_json();
            $amount = (float) ($body['amount'] ?? 0);
            if ($amount <= 0) {
                json_response(['detail' => 'Amount must be greater than zero.'], 422);
            }

            $stmt = $db->prepare('SELECT * FROM purchase_invoices WHERE id = :id');
            $stmt->execute(['id' => $purchaseId]);
            $pur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pur) {
                json_response(['detail' => 'Purchase not found'], 404);
            }

            $payDate = (string) ($body['payment_date'] ?? date('Y-m-d'));
            $methodName = (string) ($body['payment_method'] ?? 'Bank Transfer');
            $refNo = (string) ($body['reference_no'] ?? '');
            $notes = (string) ($body['notes'] ?? '');

            $ins = $db->prepare('INSERT INTO purchase_payments (purchase_id, amount, payment_date, payment_method, reference_no, notes, created_by, created_at) VALUES (:pid, :amt, :pdate, :pmeth, :ref, :notes, :uid, CURRENT_TIMESTAMP)');
            $ins->execute([
                'pid' => $purchaseId,
                'amt' => $amount,
                'pdate' => $payDate,
                'pmeth' => $methodName,
                'ref' => $refNo,
                'notes' => $notes,
                'uid' => $user->id,
            ]);

            $newPaid = (float) $pur['amount_paid'] + $amount;
            $newBalance = max(0.0, (float) $pur['total_amount'] - $newPaid);
            $newStatus = $newBalance <= 0 ? 'paid' : 'partially_paid';

            $upd = $db->prepare('UPDATE purchase_invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute(['paid' => $newPaid, 'bal' => $newBalance, 'st' => $newStatus, 'id' => $purchaseId]);

            json_response(['message' => 'Payment recorded', 'amount_paid' => $newPaid, 'balance_due' => $newBalance, 'status' => $newStatus]);
        }

        if ($sub === '/payments' && $method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM purchase_payments WHERE purchase_id = :id ORDER BY payment_date DESC, id DESC');
            $stmt->execute(['id' => $purchaseId]);
            json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($sub === '/cancel' && $method === 'POST') {
            require_permission_for($db, $user, 'purchases', 'delete');
            $stmt = $db->prepare('UPDATE purchase_invoices SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['st' => 'cancelled', 'id' => $purchaseId]);
            json_response(['message' => 'Purchase cancelled']);
        }

        if ($sub === '' && $method === 'DELETE') {
            require_permission_for($db, $user, 'purchases', 'delete');
            $stmt = $db->prepare('DELETE FROM purchase_payments WHERE purchase_id = :id');
            $stmt->execute(['id' => $purchaseId]);
            $stmt = $db->prepare('DELETE FROM purchase_items WHERE purchase_id = :id');
            $stmt->execute(['id' => $purchaseId]);
            $stmt = $db->prepare('DELETE FROM purchase_invoices WHERE id = :id');
            $stmt->execute(['id' => $purchaseId]);
            json_response(['message' => 'Purchase deleted']);
        }
    }

    return false;
}
