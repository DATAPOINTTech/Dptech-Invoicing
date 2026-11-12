<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/services/fbr_service.php';

function handle_invoices_routes(string $method, string $path, PDO $db): bool
{
    // FBR Global Configuration Endpoints
    if ($method === 'GET' && $path === '/api/fbr/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'view');
        $companyId = !empty($_GET['company_id']) ? (int)$_GET['company_id'] : null;
        json_response(fbr_get_config($db, $companyId));
    }

    if ($method === 'POST' && $path === '/api/fbr/settings') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'edit');
        $data = request_json();
        fbr_save_settings($db, $data);
        json_response(['success' => true, 'message' => 'FBR settings updated successfully', 'settings' => fbr_get_config($db)]);
    }

    if ($method === 'GET' && $path === '/api/invoices') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'invoices', 'view');
        $status = $_GET['status'] ?? null;
        $sql = 'SELECT i.*, c.name AS client_name, p.name AS project_name, p.description AS project_description FROM invoices i LEFT JOIN clients c ON c.id = i.client_id LEFT JOIN projects p ON p.id = i.project_id';
        $params = [];
        if ($status !== null && $status !== '') {
            if ($status === 'fbr_posted') {
                $sql .= " WHERE i.fbr_status = 'posted'";
            } elseif ($status === 'fbr_unposted') {
                $sql .= " WHERE (i.fbr_status IS NULL OR i.fbr_status != 'posted')";
            } else {
                $sql .= ' WHERE i.status = :st';
                $params['st'] = $status;
            }
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

        $taxRate = (float) ($body['tax_rate'] ?? 18.0);
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

        $terms = !empty($body['terms_conditions']) ? trim((string)$body['terms_conditions']) : null;
        if ($terms === null || $terms === '') {
            $terms = !empty($company['terms']) ? trim((string)$company['terms']) : "1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Goods once sold are subject to manufacturer warranty.";
        }

        $stmt = $db->prepare('
            INSERT INTO invoices (
                invoice_no, client_id, estimate_id, project_id, title, invoice_date, due_date,
                status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
                total_amount, amount_paid, balance_due, payment_terms, notes, terms_conditions,
                company_id, company_name, company_logo, company_phone, company_email, company_website, company_whatsapp,
                company_address, company_ntn, company_strn,
                created_by, created_at, updated_at
            ) VALUES (
                :no, :cid, :eid, :pid, :title, :idate, :ddate,
                :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
                :wht_r, :wht_a, :fed_r, :fed_a,
                :total, 0, :total, :pterms, :notes, :terms,
                :coid, :coname, :cologo, :cophone, :coemail, :cowebsite, :cowhatsapp,
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
            'terms' => $terms,
            'coid' => $company['id'] ?? null,
            'coname' => $company['name'] ?? env_value('COMPANY_NAME', 'DATAPOINT Technologies'),
            'cologo' => $company['logo_url'] ?? '/static/img/logo.png',
            'cophone' => $company['phone'] ?? env_value('COMPANY_PHONE', '+92 22 3669326'),
            'coemail' => $company['email'] ?? env_value('COMPANY_EMAIL', 'sales@datapoinittechnology.com'),
            'cowebsite' => $company['website'] ?? env_value('COMPANY_WEBSITE', 'https://datapointtechnology.com'),
            'cowhatsapp' => $company['whatsapp'] ?? ($company['mobile'] ?? env_value('COMPANY_WHATSAPP', '+923712566540')),
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
                'ta' => 0.0,
                'tp' => round(((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0)), 2),
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
        $pdfUser = require_authenticated_user($db);
        require_permission_for($db, $pdfUser, 'invoices', 'view');
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

            if (!empty($inv['fbr_invoice_no'])) {
                $qrTarget = fbr_get_invoice_qr_target($db, $inv);
                $inv['fbr_verify_url'] = $qrTarget;
                $inv['fbr_qr_target'] = $qrTarget;
                $inv['fbr_qr_data_uri'] = PurePhpQr::toDataUri($qrTarget, 180);
            }

            if (empty($inv['company_email']) || stripos($inv['company_email'], 'example.com') !== false || $inv['company_email'] === 'info@datapointtechnology.com') {
                $inv['company_email'] = 'sales@datapoinittechnology.com';
            }
            if (empty($inv['company_website'])) {
                $inv['company_website'] = 'https://datapointtechnology.com';
            }
            if (empty($inv['company_phone']) || $inv['company_phone'] === '+92-316-7788990' || $inv['company_phone'] === '+92 21 34567890') {
                $inv['company_phone'] = '+92 22 3669326';
            }
            if (empty($inv['company_whatsapp'])) {
                $inv['company_whatsapp'] = '+923712566540';
            }

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

        // Adjust, update or delete a recorded payment on an invoice
        if (preg_match('#^/payments/([1-9][0-9]*)(/.*)?$#', $sub, $pm)) {
            $paymentId = (int) $pm[1];
            $action = $pm[2] ?? '';

            // GET single payment record
            if ($action === '' && $method === 'GET') {
                require_permission_for($db, $user, 'invoices', 'view');
                $pStmt = $db->prepare('SELECT * FROM payments WHERE id = :pid AND invoice_id = :iid');
                $pStmt->execute(['pid' => $paymentId, 'iid' => $invoiceId]);
                $payment = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    json_response(['detail' => 'Payment record not found for this invoice'], 404);
                }
                json_response($payment);
            }

            // Adjust / Update payment record
            if (($action === '' && ($method === 'PUT' || $method === 'POST')) || ($action === '/adjust' && $method === 'POST')) {
                require_permission_for($db, $user, 'invoices', 'edit');

                $pStmt = $db->prepare('SELECT * FROM payments WHERE id = :pid AND invoice_id = :iid');
                $pStmt->execute(['pid' => $paymentId, 'iid' => $invoiceId]);
                $payment = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    json_response(['detail' => 'Payment record not found for this invoice'], 404);
                }

                $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
                $stmt->execute(['id' => $invoiceId]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) {
                    json_response(['detail' => 'Invoice not found'], 404);
                }

                $data = request_data();
                $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $payment['amount'];
                if ($amount <= 0.0) {
                    json_response(['detail' => 'Amount must be greater than zero.'], 422);
                }

                $payDate = !empty($data['payment_date']) ? (string) $data['payment_date'] : $payment['payment_date'];
                $payMethod = !empty($data['payment_method']) ? (string) $data['payment_method'] : ($payment['payment_method'] ?: 'Cash');
                $refNo = array_key_exists('reference_no', $data) ? ($data['reference_no'] !== null && trim((string)$data['reference_no']) !== '' ? (string)$data['reference_no'] : null) : $payment['reference_no'];
                $notes = array_key_exists('notes', $data) ? ($data['notes'] !== null && trim((string)$data['notes']) !== '' ? (string)$data['notes'] : null) : $payment['notes'];

                $updPay = $db->prepare('
                    UPDATE payments
                    SET amount = :amt, payment_date = :pdate, payment_method = :pmethod, reference_no = :ref, notes = :notes
                    WHERE id = :pid AND invoice_id = :iid
                ');
                $updPay->execute([
                    'amt' => $amount,
                    'pdate' => $payDate,
                    'pmethod' => $payMethod,
                    'ref' => $refNo,
                    'notes' => $notes,
                    'pid' => $paymentId,
                    'iid' => $invoiceId,
                ]);

                // Recalculate invoice totals
                $sumStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id');
                $sumStmt->execute(['id' => $invoiceId]);
                $newPaid = (float) $sumStmt->fetchColumn();

                $totalAmount = (float) $inv['total_amount'];
                $newBalance = max(0.0, $totalAmount - $newPaid);
                if ($newPaid <= 0.0) {
                    if (!empty($inv['sent_at'])) {
                        $newStatus = 'sent';
                    } elseif (!empty($inv['approved_at']) || ($inv['status'] === 'approved')) {
                        $newStatus = 'approved';
                    } elseif ($inv['status'] === 'draft') {
                        $newStatus = 'draft';
                    } else {
                        $newStatus = 'approved';
                    }
                } elseif ($newBalance <= 0.0) {
                    $newStatus = 'paid';
                } else {
                    $newStatus = 'partially_paid';
                }

                $upd = $db->prepare('UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $upd->execute(['paid' => $newPaid, 'bal' => $newBalance, 'st' => $newStatus, 'id' => $invoiceId]);

                json_response([
                    'message' => 'Payment adjusted successfully',
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status' => $newStatus,
                ]);
            }

            // Delete / Void payment record
            if (($action === '' && $method === 'DELETE') || ($action === '/delete' && ($method === 'POST' || $method === 'DELETE'))) {
                require_permission_for($db, $user, 'invoices', 'edit');

                $pStmt = $db->prepare('SELECT * FROM payments WHERE id = :pid AND invoice_id = :iid');
                $pStmt->execute(['pid' => $paymentId, 'iid' => $invoiceId]);
                $payment = $pStmt->fetch(PDO::FETCH_ASSOC);
                if (!$payment) {
                    json_response(['detail' => 'Payment record not found for this invoice'], 404);
                }

                $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
                $stmt->execute(['id' => $invoiceId]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inv) {
                    json_response(['detail' => 'Invoice not found'], 404);
                }

                $delStmt = $db->prepare('DELETE FROM payments WHERE id = :pid AND invoice_id = :iid');
                $delStmt->execute(['pid' => $paymentId, 'iid' => $invoiceId]);

                // Recalculate invoice totals
                $sumStmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = :id');
                $sumStmt->execute(['id' => $invoiceId]);
                $newPaid = (float) $sumStmt->fetchColumn();

                $totalAmount = (float) $inv['total_amount'];
                $newBalance = max(0.0, $totalAmount - $newPaid);
                if ($newPaid <= 0.0) {
                    if (!empty($inv['sent_at'])) {
                        $newStatus = 'sent';
                    } elseif (!empty($inv['approved_at']) || ($inv['status'] === 'approved')) {
                        $newStatus = 'approved';
                    } elseif ($inv['status'] === 'draft') {
                        $newStatus = 'draft';
                    } else {
                        $newStatus = 'approved';
                    }
                } elseif ($newBalance <= 0.0) {
                    $newStatus = 'paid';
                } else {
                    $newStatus = 'partially_paid';
                }

                $upd = $db->prepare('UPDATE invoices SET amount_paid = :paid, balance_due = :bal, status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $upd->execute(['paid' => $newPaid, 'bal' => $newBalance, 'st' => $newStatus, 'id' => $invoiceId]);

                json_response([
                    'message' => 'Payment deleted and invoice balance readjusted successfully',
                    'payment_id' => $paymentId,
                    'amount_paid' => $newPaid,
                    'balance_due' => $newBalance,
                    'status' => $newStatus,
                ]);
            }
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
            $terms = array_key_exists('terms_conditions', $body) && trim((string)$body['terms_conditions']) !== ''
                ? trim((string)$body['terms_conditions'])
                : (!empty($curr['terms_conditions']) ? $curr['terms_conditions'] : null);
            if ($terms === null || $terms === '') {
                $coId = (int)($curr['company_id'] ?? 0);
                if ($coId > 0) {
                    $coStmt = $db->prepare('SELECT terms FROM companies WHERE id = :cid');
                    $coStmt->execute(['cid' => $coId]);
                    $terms = $coStmt->fetchColumn() ?: null;
                }
                if (empty($terms)) {
                    $coStmt = $db->query('SELECT terms FROM companies WHERE is_default = 1 LIMIT 1');
                    $terms = $coStmt ? $coStmt->fetchColumn() : null;
                }
                if (empty($terms)) {
                    $terms = "1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Goods once sold are subject to manufacturer warranty.";
                }
            }
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
                        'ta' => 0.0,
                        'tp' => round(((float) ($item['quantity'] ?? 0)) * ((float) ($item['unit_price'] ?? 0)), 2),
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
                        project_id = :pid,
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
                    'pid' => array_key_exists('project_id', $body) ? (!empty($body['project_id']) ? (int)$body['project_id'] : null) : ($curr['project_id'] ?? null),
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

            if (!empty($inv['fbr_invoice_no'])) {
                $inv['fbr_qr_code'] = fbr_get_invoice_qr_target($db, $inv);
            }

            if (empty($inv['terms_conditions'])) {
                if (!empty($inv['company_id'])) {
                    $coStmt = $db->prepare('SELECT terms FROM companies WHERE id = :cid');
                    $coStmt->execute(['cid' => (int) $inv['company_id']]);
                    $inv['terms_conditions'] = $coStmt->fetchColumn() ?: null;
                }
                if (empty($inv['terms_conditions'])) {
                    $coStmt = $db->query('SELECT terms FROM companies WHERE is_default = 1 LIMIT 1');
                    $inv['terms_conditions'] = $coStmt ? $coStmt->fetchColumn() : null;
                }
                if (empty($inv['terms_conditions'])) {
                    $inv['terms_conditions'] = "1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Goods once sold are subject to manufacturer warranty.";
                }
            }

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

            if (!empty($inv['fbr_invoice_no'])) {
                $inv['fbr_qr_code'] = fbr_get_invoice_qr_target($db, $inv);
            }
            if (empty($inv['terms_conditions'])) {
                if (!empty($inv['company_id'])) {
                    $coStmt = $db->prepare('SELECT terms FROM companies WHERE id = :cid');
                    $coStmt->execute(['cid' => (int) $inv['company_id']]);
                    $inv['terms_conditions'] = $coStmt->fetchColumn() ?: null;
                }
                if (empty($inv['terms_conditions'])) {
                    $coStmt = $db->query('SELECT terms FROM companies WHERE is_default = 1 LIMIT 1');
                    $inv['terms_conditions'] = $coStmt ? $coStmt->fetchColumn() : null;
                }
                if (empty($inv['terms_conditions'])) {
                    $inv['terms_conditions'] = "1. Payment: Net 30 days\n2. Prices include standard GST as per Pakistan tax law\n3. Validity: 15 days from date\n4. Goods once sold are subject to manufacturer warranty.";
                }
            }
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

        // FBR Workflow: 1. Manual Approval of Invoice
        if ($sub === '/approve' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $uid = isset($user->id) ? (int)$user->id : (isset($user['id']) ? (int)$user['id'] : null);
            $res = approve_invoice($db, $invoiceId, $uid);
            if (!$res['success']) {
                json_response(['detail' => $res['error']], 422);
            }
            json_response($res);
        }

        // Convert Invoice to Estimate
        if ($sub === '/convert' && $method === 'POST') {
            require_permission_for($db, $user, 'estimates', 'create');

            $stmt = $db->prepare('SELECT * FROM invoices WHERE id = :id');
            $stmt->execute(['id' => $invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                json_response(['detail' => 'Invoice not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id');
            $itStmt->execute(['id' => $invoiceId]);
            $invItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $taxRate = (float) ($inv['tax_rate'] ?? 18.0);
            $discPct = (float) ($inv['discount_percent'] ?? 0.0);
            $subtotal = (float) ($inv['subtotal'] ?? 0.0);
            $discAmt = (float) ($inv['discount_amount'] ?? 0.0);
            $taxAmt = (float) ($inv['tax_amount'] ?? 0.0);
            $totalAmount = (float) ($inv['total_amount'] ?? 0.0);

            // If totals are 0 or not calculated but items exist, recalculate
            if (($totalAmount <= 0.0 || $subtotal <= 0.0) && !empty($invItems)) {
                $calcLines = [];
                foreach ($invItems as $ii) {
                    $qty = (float) ($ii['quantity'] ?? 0);
                    $price = (float) ($ii['unit_price'] ?? 0);
                    $calcLines[] = calculate_item_tax($price, $qty, $taxRate, false);
                }
                $totals = calculate_invoice_tax($calcLines, $discPct, $taxRate, false, false);
                $subtotal = $totals['subtotal'];
                $discAmt = $totals['discount_amount'];
                $taxAmt = $totals['tax_amount'];
                $totalAmount = $totals['total_amount'];
            }

            $nextEstId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
            $estNo = sprintf('EST-%s-%05d', date('Ym'), $nextEstId);
            $today = date('Y-m-d');
            $validUntil = !empty($inv['due_date']) ? (string) $inv['due_date'] : date('Y-m-d', strtotime('+15 days'));

            $insEst = $db->prepare('
                INSERT INTO estimates (
                    estimate_no, client_id, project_id, title, estimate_date, valid_until,
                    status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                    total_amount, terms_conditions, notes, created_by,
                    company_id, company_name, company_logo, company_phone, company_email, company_website, company_whatsapp,
                    company_address, company_ntn, company_strn,
                    batch_id, markup_percent, created_at, updated_at
                ) VALUES (
                    :no, :cid, :pid, :title, :edate, :vdate,
                    :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
                    :total, :terms, :notes, :uid,
                    :coid, :coname, :cologo, :cophone, :coemail, :cowebsite, :cowhatsapp,
                    :coaddr, :conntn, :costrn,
                    NULL, 0.0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ');
            $insEst->execute([
                'no' => $estNo,
                'cid' => $inv['client_id'],
                'pid' => $inv['project_id'] ?? null,
                'title' => $inv['title'] ?? null,
                'edate' => $today,
                'vdate' => $validUntil,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'disc_pct' => $discPct,
                'disc_amt' => $discAmt,
                'tr' => $taxRate,
                'ta' => $taxAmt,
                'total' => $totalAmount,
                'terms' => $inv['terms_conditions'] ?? null,
                'notes' => $inv['notes'] ?? null,
                'uid' => $user->id,
                'coid' => $inv['company_id'] ?? null,
                'coname' => $inv['company_name'] ?? null,
                'cologo' => $inv['company_logo'] ?? null,
                'cophone' => $inv['company_phone'] ?? null,
                'coemail' => $inv['company_email'] ?? null,
                'cowebsite' => $inv['company_website'] ?? null,
                'cowhatsapp' => $inv['company_whatsapp'] ?? null,
                'coaddr' => $inv['company_address'] ?? null,
                'conntn' => $inv['company_ntn'] ?? null,
                'costrn' => $inv['company_strn'] ?? null,
            ]);
            $estimateId = (int) $db->lastInsertId();

            $insItem = $db->prepare('
                INSERT INTO estimate_items (
                    estimate_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                ) VALUES (
                    :eid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
                )
            ');
            foreach ($invItems as $ii) {
                $qty = (float) ($ii['quantity'] ?? 0);
                $price = (float) ($ii['unit_price'] ?? 0);
                $itemTotal = round($qty * $price, 2);

                $insItem->execute([
                    'eid' => $estimateId,
                    'prid' => $ii['product_id'] ?? null,
                    'desc' => $ii['description'] ?? 'Item',
                    'mm' => $ii['model_make'] ?? null,
                    'qty' => $qty,
                    'unit' => $ii['unit'] ?? 'pcs',
                    'price' => $price,
                    'tr' => $taxRate,
                    'ta' => 0.0,
                    'tp' => $itemTotal,
                ]);
            }

            // Link estimate to invoice if invoice has no estimate_id yet
            if (empty($inv['estimate_id'])) {
                try {
                    $db->prepare('UPDATE invoices SET estimate_id = :eid, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
                        'eid' => $estimateId,
                        'id' => $invoiceId,
                    ]);
                } catch (\Throwable $e) {}
            }

            json_response([
                'message' => 'Estimate created successfully from invoice',
                'estimate_id' => $estimateId,
                'estimate_no' => $estNo,
                'total_amount' => $totalAmount,
                'subtotal' => $subtotal,
            ], 201);
        }

        // FBR Workflow: 2. Pre-Validation with FBR Sandbox / Portal
        if ($sub === '/fbr-validate' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'view');
            $res = validate_invoice_with_fbr($db, $invoiceId);
            if (!$res['success']) {
                json_response([
                    'detail' => $res['error'] ?? 'FBR validation failed',
                    'data' => $res
                ], 422);
            }
            json_response($res);
        }

        // FBR Workflow: 3. Manual Posting to FBR Pakistan Portal
        if ($sub === '/fbr-post' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'edit');
            $body = request_json();
            $force = !empty($body['force']);
            $uid = isset($user->id) ? (int)$user->id : (isset($user['id']) ? (int)$user['id'] : null);
            $res = post_invoice_to_fbr($db, $invoiceId, $uid, $force);
            if (!$res['success']) {
                json_response([
                    'detail' => $res['error'] ?? 'FBR submission failed',
                    'data' => $res
                ], 422);
            }
            json_response($res);
        }

        // FBR Workflow: 3. Realtime FBR Status & QR Data
        if ($sub === '/fbr-status' && $method === 'GET') {
            require_permission_for($db, $user, 'invoices', 'view');
            $stmt = $db->prepare('
                SELECT id, invoice_no, status, fbr_status, fbr_invoice_no, fbr_pos_id, fbr_code, fbr_response, fbr_qr_code, fbr_posted_at, approved_at
                FROM invoices
                WHERE id = :id
            ');
            $stmt->execute(['id' => $invoiceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['detail' => 'Invoice not found'], 404);
            }
            if (!empty($row['fbr_invoice_no'])) {
                $qrTarget = fbr_get_invoice_qr_target($db, $row);
                $row['fbr_verify_url'] = $qrTarget;
                $row['fbr_qr_target'] = $qrTarget;
                $row['qr_svg'] = PurePhpQr::toSvg($qrTarget, 180);
                $row['qr_data_uri'] = PurePhpQr::toDataUri($qrTarget, 180);
            }
            json_response($row);
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
