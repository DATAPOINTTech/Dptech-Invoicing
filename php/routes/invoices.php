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

        $companyId = !empty($body['company_id']) ? (int) $body['company_id'] : null;
        $company = null;
        if ($companyId) {
            $stmtCo = $db->prepare('SELECT * FROM companies WHERE id = :id');
            $stmtCo->execute(['id' => $companyId]);
            $company = $stmtCo->fetch(PDO::FETCH_ASSOC);
        }
        if (!$company) {
            $stmtCo = $db->query('SELECT * FROM companies WHERE is_default = 1 LIMIT 1');
            $company = $stmtCo ? $stmtCo->fetch(PDO::FETCH_ASSOC) : null;
            if (!$company) {
                $stmtCo = $db->query('SELECT * FROM companies ORDER BY id ASC LIMIT 1');
                $company = $stmtCo ? $stmtCo->fetch(PDO::FETCH_ASSOC) : null;
            }
        }

        $stmt = $db->prepare('
            INSERT INTO invoices (
                invoice_no, client_id, estimate_id, project_id, title, invoice_date, due_date,
                status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
                total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
                company_id, company_name, company_logo, company_phone, company_email,
                company_address, company_ntn, company_strn,
                created_by, created_at, updated_at
            ) VALUES (
                :no, :cid, :eid, :pid, :title, :idate, :ddate,
                :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
                :wht_r, :wht_a, :fed_r, :fed_a,
                :total, 0, :total, :pterms, :notes, :terms,
                :coid, :coname, :cologo, :cophone, :coemail,
                :coaddr, :conntn, :costrn,
                :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'no' => $invoiceNo,
            'cid' => $clientId,
            'eid' => !empty($body['estimate_id']) ? (int) $body['estimate_id'] : null,
            'pid' => !empty($body['project_id']) ? (int) $body['project_id'] : null,
            'title' => $body['title'] ?? null,
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
            'coid' => $company['id'] ?? null,
            'coname' => $company['name'] ?? env_value('COMPANY_NAME', 'DATAPOINT Technologies'),
            'cologo' => $company['logo_url'] ?? '/static/img/logo.png',
            'cophone' => $company['phone'] ?? env_value('COMPANY_PHONE', '+923167788990'),
            'coemail' => $company['email'] ?? env_value('COMPANY_EMAIL', 'info@datapointtechnology.com'),
            'coaddr' => $company['address'] ?? env_value('COMPANY_ADDRESS', 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh'),
            'conntn' => $company['ntn'] ?? null,
            'costrn' => $company['strn'] ?? null,
            'uid' => $user->id,
        ]);
        $invoiceId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare('
            INSERT INTO invoice_items (
                invoice_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
            ) VALUES (
                :iid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
            )
        ');
        foreach ($calculated as $line) {
            $item = $line['item'];
            $itemStmt->execute([
                'iid' => $invoiceId,
                'prid' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'desc' => trim((string) ($item['description'] ?? 'Item')),
                'mm' => !empty($item['model_make']) ? trim((string) $item['model_make']) : null,
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

            // Backwards compatibility client object
            $inv['client'] = [
                'name' => $inv['client_name'] ?? '',
                'company' => $inv['client_company'] ?? '',
                'email' => $inv['client_email'] ?? '',
                'phone' => $inv['client_phone'] ?? '',
                'mobile' => $inv['client_mobile'] ?? '',
                'address' => $inv['client_address'] ?? '',
                'city' => $inv['client_city'] ?? '',
                'ntn' => $inv['client_ntn'] ?? '',
                'strn' => $inv['client_strn'] ?? '',
            ];

            // Payments history
            $payStmt = $db->prepare('SELECT * FROM payments WHERE invoice_id = :id ORDER BY payment_date DESC, id DESC');
            $payStmt->execute(['id' => $invoiceId]);
            $inv['payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);

            json_response($inv);
        }

        if (($sub === '/pay' || $sub === '/payments') && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $data = request_data();
            $amount = 0.0;
            if (isset($data['amount'])) {
                $amount = (float) $data['amount'];
            } elseif (isset($_POST['amount'])) {
                $amount = (float) $_POST['amount'];
            } elseif (isset($_GET['amount'])) {
                $amount = (float) $_GET['amount'];
            }

            if ($amount <= 0.0) {
                json_response(['detail' => 'Amount must be greater than zero.'], 422);
            }

            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $payDate = !empty($data['payment_date']) ? (string) $data['payment_date'] : (!empty($_POST['payment_date']) ? (string) $_POST['payment_date'] : date('Y-m-d'));
            $payMethod = !empty($data['payment_method']) ? (string) $data['payment_method'] : (!empty($_POST['payment_method']) ? (string) $_POST['payment_method'] : 'Cash');
            $refNo = !empty($data['reference_no']) ? (string) $data['reference_no'] : (!empty($_POST['reference_no']) ? (string) $_POST['reference_no'] : null);
            $notes = !empty($data['notes']) ? (string) $data['notes'] : (!empty($_POST['notes']) ? (string) $_POST['notes'] : null);

            // Record in payments table
            $insPay = $db->prepare('
                INSERT INTO payments (
                    invoice_id, amount, payment_date, payment_method, reference_no, notes, created_by, created_at
                ) VALUES (
                    :iid, :amt, :pdate, :pmethod, :ref, :notes, :uid, CURRENT_TIMESTAMP
                )
            ');
            $insPay->execute([
                'iid' => $invoiceId,
                'amt' => $amount,
                'pdate' => $payDate,
                'pmethod' => $payMethod,
                'ref' => $refNo,
                'notes' => $notes,
                'uid' => $user->id,
            ]);
            $paymentId = (int) $db->lastInsertId();

            // Re-aggregate total paid from payments table for consistency
            $sumStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id');
            $sumStmt->execute(['id' => $invoiceId]);
            $newPaid = (float) $sumStmt->fetchColumn();

            $totalAmount = (float) $inv['total_amount'];
            $newBalance = max(0.0, $totalAmount - $newPaid);
            $newStatus = ($newBalance <= 0.0) ? 'paid' : 'partially_paid';

            $upd = $db->prepare('UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $upd->execute(['paid' => $newPaid, 'bal' => $newBalance, 'st' => $newStatus, 'id' => $invoiceId]);

            json_response([
                'message' => 'Payment recorded successfully',
                'payment_id' => $paymentId,
                'amount_paid' => $newPaid,
                'balance_due' => $newBalance,
                'status' => $newStatus,
            ]);
        }

        if ($sub === '' && $method === 'PUT') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $body = request_json();

            $stmtCurr = $db->prepare('SELECT * FROM invoices WHERE id = :id');
            $stmtCurr->execute(['id' => $invoiceId]);
            $curr = $stmtCurr->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $clientId = array_key_exists('client_id', $body) && !empty($body['client_id']) ? (int) $body['client_id'] : (int) $curr['client_id'];
            $date = array_key_exists('invoice_date', $body) && !empty($body['invoice_date']) ? (string) $body['invoice_date'] : $curr['invoice_date'];
            $dueDate = array_key_exists('due_date', $body) ? (string) $body['due_date'] : $curr['due_date'];
            $terms = array_key_exists('terms_conditions', $body) ? $body['terms_conditions'] : $curr['terms_conditions'];
            $notes = array_key_exists('notes', $body) ? $body['notes'] : $curr['notes'];
            $paymentTerms = array_key_exists('payment_terms', $body) ? $body['payment_terms'] : $curr['payment_terms'];
            $discountPercent = array_key_exists('discount_percent', $body) ? (float) $body['discount_percent'] : (float) $curr['discount_percent'];
            $taxRate = array_key_exists('tax_rate', $body) ? (float) $body['tax_rate'] : (float) $curr['tax_rate'];
            $applyWht = array_key_exists('apply_wht', $body) ? (bool) $body['apply_wht'] : ((float) $curr['withholding_tax_rate'] > 0);
            $applyFed = array_key_exists('apply_fed', $body) ? (bool) $body['apply_fed'] : ((float) $curr['fed_rate'] > 0);

            $items = $body['items'] ?? null;
            if (is_array($items) && $items !== []) {
                $calculated = [];
                foreach ($items as $it) {
                    $desc = trim((string) ($it['description'] ?? 'Item'));
                    if ($desc === '') {
                        continue;
                    }
                    $qty = (float) ($it['quantity'] ?? 0);
                    $price = (float) ($it['unit_price'] ?? 0);
                    $line = calculate_item_tax($price, $qty, $taxRate, false);
                    $line['item'] = array_merge($it, ['unit_price' => $price, 'description' => $desc]);
                    $calculated[] = $line;
                }

                $totals = calculate_invoice_tax($calculated, $discountPercent, $taxRate, $applyWht, $applyFed);

                $delStmt = $db->prepare('DELETE FROM invoice_items WHERE invoice_id = :iid');
                $delStmt->execute(['iid' => $invoiceId]);

                $title = array_key_exists('title', $body) ? $body['title'] : ($curr['title'] ?? null);

                $insItem = $db->prepare('
                    INSERT INTO invoice_items (
                        invoice_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                    ) VALUES (
                        :iid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
                    )
                ');
                foreach ($calculated as $line) {
                    $item = $line['item'];
                    $insItem->execute([
                        'iid' => $invoiceId,
                        'prid' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                        'desc' => $item['description'],
                        'mm' => !empty($item['model_make']) ? trim((string) $item['model_make']) : null,
                        'qty' => (float) ($item['quantity'] ?? 0),
                        'unit' => (string) ($item['unit'] ?? 'pcs'),
                        'price' => (float) ($item['unit_price'] ?? 0),
                        'tr' => $taxRate,
                        'ta' => $line['tax_amount'],
                        'tp' => $line['total'],
                    ]);
                }

                $curPaid = (float) $curr['amount_paid'];
                $newBal = max(0.0, (float) $totals['total_amount'] - $curPaid);
                $st = $curr['status'];
                if ($st !== 'cancelled') {
                    $st = ($newBal <= 0.0) ? 'paid' : ($curPaid > 0 ? 'partially_paid' : 'draft');
                }

                $upd = $db->prepare('
                    UPDATE invoices SET
                        client_id = :cid,
                        title = :title,
                        invoice_date = :idate,
                        due_date = :ddate,
                        payment_terms = :pterms,
                        notes = :notes,
                        terms_conditions = :terms,
                        subtotal = :subtotal,
                        discount_percent = :disc_pct,
                        discount_amount = :disc_amt,
                        tax_rate = :tax_rate,
                        tax_amount = :tax_amt,
                        withholding_tax_rate = :wht_r,
                        withholding_tax_amount = :wht_a,
                        fed_rate = :fed_r,
                        fed_amount = :fed_a,
                        total_amount = :total,
                        balance_due = :bal,
                        status = :st,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $upd->execute([
                    'cid' => $clientId,
                    'title' => $title,
                    'idate' => $date,
                    'ddate' => $dueDate,
                    'pterms' => $paymentTerms,
                    'notes' => $notes,
                    'terms' => $terms,
                    'subtotal' => $totals['subtotal'],
                    'disc_pct' => $totals['discount_percent'],
                    'disc_amt' => $totals['discount_amount'],
                    'tax_rate' => $totals['tax_rate'],
                    'tax_amt' => $totals['tax_amount'],
                    'wht_r' => $totals['wht_rate'],
                    'wht_a' => $totals['wht_amount'],
                    'fed_r' => $totals['fed_rate'],
                    'fed_a' => $totals['fed_amount'],
                    'total' => $totals['total_amount'],
                    'bal' => $newBal,
                    'st' => $st,
                    'id' => $invoiceId,
                ]);

                json_response([
                    'message' => 'Invoice updated successfully',
                    'id' => $invoiceId,
                    'total_amount' => $totals['total_amount'],
                    'balance_due' => $newBal,
                ]);
            } else {
                $upd = $db->prepare('
                    UPDATE invoices SET
                        client_id = :cid,
                        invoice_date = :idate,
                        due_date = :ddate,
                        payment_terms = :pterms,
                        notes = :notes,
                        terms_conditions = :terms,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $upd->execute([
                    'cid' => $clientId,
                    'idate' => $date,
                    'ddate' => $dueDate,
                    'pterms' => $paymentTerms,
                    'notes' => $notes,
                    'terms' => $terms,
                    'id' => $invoiceId,
                ]);

                json_response(['message' => 'Invoice updated successfully', 'id' => $invoiceId]);
            }
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

            $sendEmail = (bool) ($body['email'] ?? true);
            $sendWhatsapp = (bool) ($body['whatsapp'] ?? false);
            $targetEmail = !empty($body['recipient_email']) ? (string) $body['recipient_email'] : ($inv['client_email'] ?? '');
            $targetPhone = !empty($body['recipient_phone']) ? (string) $body['recipient_phone'] : ($inv['client_mobile'] ?? $inv['client_phone'] ?? '');

            $pdfData = generate_invoice_pdf($inv);
            $results = [];

            if ($sendEmail && $targetEmail !== '') {
                try {
                    $cleanInvNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($inv['invoice_no'] ?? 'INV'));
                    $pdfFilename = "Invoice_{$cleanInvNo}.pdf";
                    $sent = send_email_pdf(
                        $targetEmail,
                        "Sales Tax Invoice #{$inv['invoice_no']}",
                        "Dear Customer,\n\nPlease find attached your Sales Tax Invoice #{$inv['invoice_no']}.\n\nThank you for your business!",
                        $pdfData,
                        $pdfFilename
                    );
                    $results['email'] = $sent ? 'sent' : 'failed';
                } catch (Throwable $e) {
                    $results['email'] = 'error: ' . $e->getMessage();
                }
            }

            if ($sendWhatsapp && $targetPhone !== '') {
                try {
                    $cleanInvNo = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($inv['invoice_no'] ?? 'INV'));
                    $pdfFilename = "Invoice_{$cleanInvNo}.pdf";
                    $clientName = $inv['client_name'] ?? 'Customer';
                    $msg = !empty($body['message']) 
                        ? (string)$body['message'] 
                        : "Dear *{$clientName}*,\n\nPlease find attached your official Sales Tax Invoice *#{$inv['invoice_no']}* of *PKR " . number_format((float) $inv['total_amount'], 2) . "* from DATAPOINT Technologies.\n\nThank you for your business!";
                    
                    $resp = send_whatsapp_message($targetPhone, $msg, $pdfData, $pdfFilename);
                    $results['whatsapp'] = $resp['success'] ? 'sent' : ('failed: ' . ($resp['error'] ?? 'unknown error'));
                } catch (Throwable $e) {
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
