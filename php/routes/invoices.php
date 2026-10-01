<?php

declare(strict_types=1);

function handle_invoices_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/invoices') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'view');
        $status = $_GET['status'] ?? null;
        $sql = 'SELECT i.*, c.name AS client_name FROM invoices i LEFT JOIN clients c ON c.id = i.client_id';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE i.status = :st';
            $params['st'] = $status;
        }
        $sql .= ' ORDER BY i.invoice_date DESC, i.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/invoices') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'create');
        $body = request_json();

        $clientId = (int) ($body['client_id'] ?? 0);
        $items = $body['items'] ?? [];
        if ($clientId < 1 || !is_array($items) || $items === []) {
            json_response(['detail' => 'client_id and items are required'], 422);
        }

        $taxRate = (float) ($body['tax_rate'] ?? 17.0);
        $discountPercent = (float) ($body['discount_percent'] ?? 0.0);
        $applyWht = (bool) ($body['apply_wht'] ?? false);
        $applyFed = (bool) ($body['apply_fed'] ?? false);

        $calculated = [];
        foreach ($items as $item) {
            $line = calculate_item_tax(
                (float) ($item['unit_price'] ?? 0),
                (float) ($item['quantity'] ?? 0),
                $taxRate,
                false
            );
            $line['item'] = $item;
            $calculated[] = $line;
        }

        $totals = calculate_invoice_tax($calculated, $discountPercent, $taxRate, $applyWht, $applyFed);
        $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
        $invoiceNo = sprintf('INV-%s-%05d', date('Ym'), $nextId);
        $date = (string) ($body['invoice_date'] ?? date('Y-m-d'));
        $dueDate = !empty($body['due_date']) ? (string) $body['due_date'] : $date;

        $stmt = $db->prepare('
            INSERT INTO invoices (
                invoice_no, client_id, estimate_id, project_id, invoice_date, due_date,
                status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
                total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
                created_by, created_at, updated_at
            ) VALUES (
                :no, :cid, :eid, :pid, :idate, :ddate,
                :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
                :wht_r, :wht_a, :fed_r, :fed_a,
                :total, 0, :total, :pterms, :notes, :terms,
                :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'no' => $invoiceNo,
            'cid' => $clientId,
            'eid' => !empty($body['estimate_id']) ? (int) $body['estimate_id'] : null,
            'pid' => !empty($body['project_id']) ? (int) $body['project_id'] : null,
            'idate' => $date,
            'ddate' => $dueDate,
            'status' => 'draft',
            'subtotal' => $totals['subtotal'],
            'disc_pct' => $totals['discount_percent'],
            'disc_amt' => $totals['discount_amount'],
            'tr' => $totals['tax_rate'],
            'ta' => $totals['tax_amount'],
            'wht_r' => $totals['wht_rate'],
            'wht_a' => $totals['wht_amount'],
            'fed_r' => $totals['fed_rate'],
            'fed_a' => $totals['fed_amount'],
            'total' => $totals['total_amount'],
            'pterms' => $body['payment_terms'] ?? null,
            'notes' => $body['notes'] ?? null,
            'terms' => $body['terms_conditions'] ?? null,
            'uid' => $user->id,
        ]);
        $invoiceId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare('
            INSERT INTO invoice_items (
                invoice_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
            ) VALUES (
                :iid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
            )
        ');
        foreach ($calculated as $line) {
            $item = $line['item'];
            $itemStmt->execute([
                'iid' => $invoiceId,
                'prid' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'desc' => trim((string) ($item['description'] ?? 'Item')),
                'qty' => (float) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unit'] ?? 'pcs'),
                'price' => (float) ($item['unit_price'] ?? 0),
                'tr' => $taxRate,
                'ta' => $line['tax_amount'],
                'tp' => $line['total'],
            ]);

            if (!empty($item['product_id'])) {
                try {
                    update_stock($db, (int) $item['product_id'], (float) ($item['quantity'] ?? 0), MovementType::SALE_OUT, 'invoice', $invoiceId, "Invoice {$invoiceNo}", $user->id);
                } catch (Exception $e) {
                    // Ignore or record stock log
                }
            }
        }

        json_response([
            'id' => $invoiceId,
            'invoice_no' => $invoiceNo,
            'total_amount' => $totals['total_amount'],
            'message' => 'Invoice created',
        ], 201);
    }

    if ($method === 'POST' && $path === '/api/invoices/pdf') {
        $payload = request_json();
        $pdf = generate_invoice_pdf($payload);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="invoice.pdf"');
        echo $pdf;
        exit;
    }

    if (preg_match('#^/api/invoices/([1-9][0-9]*)(/.*)?$#', $path, $m)) {
        $invoiceId = (int) $m[1];
        $sub = $m[2] ?? '';
        $user = require_authenticated_user($db);

        if ($sub === '' && $method === 'GET') {
            require_permission_for($db, $user, 'invoices', 'view');
            $stmt = $db->prepare('
                SELECT i.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone, c.mobile AS client_mobile, c.address AS client_address, c.city AS client_city, c.ntn AS client_ntn, c.strn AS client_strn
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                WHERE i.id = :id
            ');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
            $itStmt->execute(['id' => $invoiceId]);
            $inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);
            json_response($inv);
        }

        if ($sub === '/pay' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $body = request_json();
            $amount = (float) ($body['amount'] ?? 0);
            if ($amount <= 0) {
                json_response(['detail' => 'Amount must be greater than zero.'], 422);
            }

            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $newPaid = (float) $inv['amount_paid'] + $amount;
            $newBalance = max(0.0, (float) $inv['total_amount'] - $newPaid);
            $newStatus = $newBalance <= 0 ? 'paid' : 'partially_paid';

            $upd = $db->prepare('UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute(['paid' => $newPaid, 'bal' => $newBalance, 'st' => $newStatus, 'id' => $invoiceId]);

            json_response(['message' => 'Payment recorded', 'amount_paid' => $newPaid, 'balance_due' => $newBalance, 'status' => $newStatus]);
        }

        if ($sub === '/pdf' && $method === 'GET') {
            $stmt = $db->prepare('
                SELECT i.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                WHERE i.id = :id
            ');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
            $itStmt->execute(['id' => $invoiceId]);
            $inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $pdf = generate_invoice_pdf($inv);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $inv['invoice_no'] . '.pdf"');
            echo $pdf;
            exit;
        }

        if ($sub === '/send' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $body = request_json();
            $stmt = $db->prepare('SELECT i.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.mobile AS client_mobile FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = :id');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
            $itStmt->execute(['id' => $invoiceId]);
            $inv['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $sendEmail = (bool) ($body['email'] ?? true);
            $sendWhatsapp = (bool) ($body['whatsapp'] ?? false);
            $targetEmail = !empty($body['recipient_email']) ? (string) $body['recipient_email'] : ($inv['client_email'] ?? '');
            $targetPhone = !empty($body['recipient_phone']) ? (string) $body['recipient_phone'] : ($inv['client_mobile'] ?? $inv['client_phone'] ?? '');

            $pdfData = generate_invoice_pdf($inv);
            $results = [];

            if ($sendEmail && $targetEmail !== '') {
                try {
                    $sent = send_email_pdf($targetEmail, "Tax Invoice #{$inv['invoice_no']}", "Please find attached your invoice #{$inv['invoice_no']}.", $pdfData, "{$inv['invoice_no']}.pdf");
                    $results['email'] = $sent ? 'sent' : 'failed';
                } catch (Exception $e) {
                    $results['email'] = 'error: ' . $e->getMessage();
                }
            }

            if ($sendWhatsapp && $targetPhone !== '') {
                try {
                    $msg = "Dear Customer, your invoice #{$inv['invoice_no']} of PKR " . number_format((float) $inv['total_amount'], 2) . " has been issued. Thank you for your business!";
                    $resp = send_whatsapp_message($targetPhone, $msg);
                    $results['whatsapp'] = $resp['status'] ?? 'sent';
                } catch (Exception $e) {
                    $results['whatsapp'] = 'error: ' . $e->getMessage();
                }
            }

            $upd = $db->prepare("UPDATE invoices SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute(['id' => $invoiceId]);

            json_response(['message' => 'Invoice dispatch completed', 'results' => $results]);
        }

        if ($sub === '' && $method === 'DELETE') {
            require_permission_for($db, $user, 'invoices', 'delete');
            $stmt = $db->prepare('UPDATE invoices SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['st' => 'cancelled', 'id' => $invoiceId]);
            json_response(['message' => 'Invoice cancelled']);
        }

        if ($sub === '/delete' && $method === 'DELETE') {
            require_permission_for($db, $user, 'invoices', 'delete');
            $stmt = $db->prepare('DELETE FROM invoice_items WHERE invoice_id = :id');
            $stmt->execute(['id' => $invoiceId]);
            $stmt = $db->prepare('DELETE FROM invoices WHERE id = :id');
            $stmt->execute(['id' => $invoiceId]);
            json_response(['message' => 'Invoice deleted']);
        }
    }

    return false;
}
