<?php

declare(strict_types=1);

function handle_estimates_routes(string $method, string $path, PDO $db): bool
{
    if ($method === 'GET' && $path === '/api/estimates') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'estimates', 'view');
        $status = $_GET['status'] ?? null;
        $batch = $_GET['batch_id'] ?? null;
        $companyId = $_GET['company_id'] ?? null;
        $sql = 'SELECT e.*, c.name AS client_name FROM estimates e LEFT JOIN clients c ON c.id = e.client_id WHERE 1=1';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' AND e.status = :st';
            $params['st'] = $status;
        }
        if ($batch !== null && $batch !== '') {
            $sql .= ' AND e.batch_id = :bid';
            $params['bid'] = $batch;
        }
        if ($companyId !== null && $companyId !== '') {
            $sql .= ' AND e.company_id = :cid';
            $params['cid'] = (int) $companyId;
        }
        $sql .= ' ORDER BY e.estimate_date DESC, e.id DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/estimates') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'estimates', 'create');
        $body = request_json();

        $clientId = (int) ($body['client_id'] ?? 0);
        $items = $body['items'] ?? [];
        if ($clientId < 1 || !is_array($items) || $items === []) {
            json_response(['detail' => 'client_id and items are required'], 422);
        }

        $taxRate = (float) ($body['tax_rate'] ?? 17.0);
        $discountPercent = (float) ($body['discount_percent'] ?? 0.0);
        $date = (string) ($body['estimate_date'] ?? date('Y-m-d'));
        $validUntil = !empty($body['valid_until']) ? (string) $body['valid_until'] : date('Y-m-d', strtotime('+15 days'));
        $title = $body['title'] ?? null;
        $terms = $body['terms_conditions'] ?? null;
        $notes = $body['notes'] ?? null;
        $projectId = !empty($body['project_id']) ? (int) $body['project_id'] : null;

        // Check multi-company flag (defaults to true if not explicitly set to false)
        $isMultiCompany = !array_key_exists('multi_company', $body) || (bool) $body['multi_company'];

        // Retrieve configured companies
        $stmtCo = $db->query('SELECT * FROM companies ORDER BY sort_order ASC, id ASC');
        $companies = $stmtCo->fetchAll(PDO::FETCH_ASSOC);

        if (count($companies) < 3) {
            ensure_multicompany_schema($db);
            $stmtCo = $db->query('SELECT * FROM companies ORDER BY sort_order ASC, id ASC');
            $companies = $stmtCo->fetchAll(PDO::FETCH_ASSOC);
        }

        // If multi-company, we build 3 estimates for the 3 companies (0%, +2%, +3%)
        if ($isMultiCompany && count($companies) >= 3) {
            $batchId = 'GRP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $targetCompanies = array_slice($companies, 0, 3);

            // Assign standard markups if not specified: Co 1 = 0%, Co 2 = 2%, Co 3 = 3%
            $markups = [0.0, 2.0, 3.0];
            $createdEstimates = [];
            $primaryEstimateId = null;
            $primaryEstimateNo = null;

            foreach ($targetCompanies as $idx => $co) {
                $markupPct = isset($co['markup_percent']) ? (float) $co['markup_percent'] : $markups[$idx];
                $multiplier = 1.0 + ($markupPct / 100.0);

                // Calculate item lines with markup applied to unit_price
                $calculated = [];
                foreach ($items as $origItem) {
                    $basePrice = (float) ($origItem['unit_price'] ?? 0);
                    $markedPrice = round($basePrice * $multiplier, 2);
                    $qty = (float) ($origItem['quantity'] ?? 0);

                    $line = calculate_item_tax($markedPrice, $qty, $taxRate, false);
                    $line['item'] = array_merge($origItem, ['unit_price' => $markedPrice]);
                    $calculated[] = $line;
                }

                $totals = calculate_invoice_tax($calculated, $discountPercent, $taxRate, false, false);
                $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
                $estimateNo = sprintf('EST-%s-%05d', date('Ym'), $nextId);

                $stmt = $db->prepare('
                    INSERT INTO estimates (
                        estimate_no, client_id, project_id, title, estimate_date, valid_until,
                        status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                        total_amount, terms_conditions, notes, created_by,
                        company_id, company_name, company_logo, company_phone, company_email, company_address, company_ntn, company_strn,
                        batch_id, markup_percent, created_at, updated_at
                    ) VALUES (
                        :no, :cid, :pid, :title, :edate, :vdate,
                        :status, :subtotal, :disc_pct, :disc_amt, :tax_rate, :tax_amt,
                        :total, :terms, :notes, :uid,
                        :coid, :coname, :cologo, :cophone, :coemail, :coaddr, :conntn, :costrn,
                        :bid, :markup, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    )
                ');
                $stmt->execute([
                    'no' => $estimateNo,
                    'cid' => $clientId,
                    'pid' => $projectId,
                    'title' => $title,
                    'edate' => $date,
                    'vdate' => $validUntil,
                    'status' => 'draft',
                    'subtotal' => $totals['subtotal'],
                    'disc_pct' => $totals['discount_percent'],
                    'disc_amt' => $totals['discount_amount'],
                    'tax_rate' => $totals['tax_rate'],
                    'tax_amt' => $totals['tax_amount'],
                    'total' => $totals['total_amount'],
                    'terms' => $terms ?: ($co['terms'] ?? null),
                    'notes' => $notes,
                    'uid' => $user->id,
                    'coid' => $co['id'],
                    'coname' => $co['name'],
                    'cologo' => $co['logo_url'] ?? '/static/img/logo.png',
                    'cophone' => $co['phone'] ?? null,
                    'coemail' => $co['email'] ?? null,
                    'coaddr' => $co['address'] ?? null,
                    'conntn' => $co['ntn'] ?? null,
                    'costrn' => $co['strn'] ?? null,
                    'bid' => $batchId,
                    'markup' => $markupPct,
                ]);
                $estId = (int) $db->lastInsertId();

                if ($primaryEstimateId === null) {
                    $primaryEstimateId = $estId;
                    $primaryEstimateNo = $estimateNo;
                }

                $itemStmt = $db->prepare('
                    INSERT INTO estimate_items (
                        estimate_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                    ) VALUES (
                        :eid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
                    )
                ');
                foreach ($calculated as $line) {
                    $it = $line['item'];
                    $itemStmt->execute([
                        'eid' => $estId,
                        'prid' => !empty($it['product_id']) ? (int) $it['product_id'] : null,
                        'desc' => trim((string) ($it['description'] ?? 'Item')),
                        'qty' => (float) ($it['quantity'] ?? 0),
                        'unit' => (string) ($it['unit'] ?? 'pcs'),
                        'price' => (float) ($it['unit_price'] ?? 0),
                        'tr' => $taxRate,
                        'ta' => $line['tax_amount'],
                        'tp' => $line['total'],
                    ]);
                }

                $createdEstimates[] = [
                    'id' => $estId,
                    'estimate_no' => $estimateNo,
                    'company_name' => $co['name'],
                    'company_id' => $co['id'],
                    'markup_percent' => $markupPct,
                    'subtotal' => $totals['subtotal'],
                    'total_amount' => $totals['total_amount'],
                ];
            }

            json_response([
                'id' => $primaryEstimateId,
                'estimate_no' => $primaryEstimateNo,
                'batch_id' => $batchId,
                'message' => '3 Multi-Company Estimates created successfully',
                'estimates' => $createdEstimates,
            ], 201);
        }

        // Single Estimate fallback
        $co = $companies[0] ?? [
            'id' => null,
            'name' => env_value('COMPANY_NAME', 'DATAPOINT Technologies'),
            'logo_url' => '/static/img/logo.png',
            'phone' => env_value('COMPANY_PHONE', ''),
            'email' => env_value('COMPANY_EMAIL', ''),
            'address' => env_value('COMPANY_ADDRESS', ''),
            'ntn' => null,
            'strn' => null,
        ];
        if (!empty($body['company_id'])) {
            foreach ($companies as $c) {
                if ($c['id'] == $body['company_id']) {
                    $co = $c;
                    break;
                }
            }
        }

        $markupPct = (float) ($co['markup_percent'] ?? 0.0);
        $calculated = [];
        foreach ($items as $item) {
            $basePrice = (float) ($item['unit_price'] ?? 0);
            $markedPrice = ($markupPct > 0) ? round($basePrice * (1 + ($markupPct / 100.0)), 2) : $basePrice;
            $qty = (float) ($item['quantity'] ?? 0);
            $line = calculate_item_tax(
                $markedPrice,
                $qty,
                $taxRate,
                false
            );
            $line['item'] = array_merge($item, ['unit_price' => $markedPrice]);
            $calculated[] = $line;
        }

        $totals = calculate_invoice_tax($calculated, $discountPercent, $taxRate, false, false);
        $nextId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM estimates')->fetchColumn();
        $estimateNo = sprintf('EST-%s-%05d', date('Ym'), $nextId);

        $stmt = $db->prepare('
            INSERT INTO estimates (
                estimate_no, client_id, project_id, title, estimate_date, valid_until,
                status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                total_amount, terms_conditions, notes, created_by,
                company_id, company_name, company_logo, company_phone, company_email, company_address, company_ntn, company_strn,
                batch_id, markup_percent, created_at, updated_at
            ) VALUES (
                :no, :cid, :pid, :title, :edate, :vdate,
                :status, :subtotal, :disc_pct, :disc_amt, :tax_rate, :tax_amt,
                :total, :terms, :notes, :uid,
                :coid, :coname, :cologo, :cophone, :coemail, :coaddr, :conntn, :costrn,
                NULL, :markup, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'no' => $estimateNo,
            'cid' => $clientId,
            'pid' => $projectId,
            'title' => $title,
            'edate' => $date,
            'vdate' => $validUntil,
            'status' => 'draft',
            'subtotal' => $totals['subtotal'],
            'disc_pct' => $totals['discount_percent'],
            'disc_amt' => $totals['discount_amount'],
            'tax_rate' => $totals['tax_rate'],
            'tax_amt' => $totals['tax_amount'],
            'total' => $totals['total_amount'],
            'terms' => $terms ?: ($co['terms'] ?? null),
            'notes' => $notes,
            'uid' => $user->id,
            'coid' => $co['id'],
            'coname' => $co['name'],
            'cologo' => $co['logo_url'] ?? '/static/img/logo.png',
            'cophone' => $co['phone'] ?? null,
            'coemail' => $co['email'] ?? null,
            'coaddr' => $co['address'] ?? null,
            'conntn' => $co['ntn'] ?? null,
            'costrn' => $co['strn'] ?? null,
            'markup' => $markupPct,
        ]);
        $estimateId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare('
            INSERT INTO estimate_items (
                estimate_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
            ) VALUES (
                :eid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
            )
        ');
        foreach ($calculated as $line) {
            $item = $line['item'];
            $itemStmt->execute([
                'eid' => $estimateId,
                'prid' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                'desc' => trim((string) ($item['description'] ?? 'Item')),
                'qty' => (float) ($item['quantity'] ?? 0),
                'unit' => (string) ($item['unit'] ?? 'pcs'),
                'price' => (float) ($item['unit_price'] ?? 0),
                'tr' => $taxRate,
                'ta' => $line['tax_amount'],
                'tp' => $line['total'],
            ]);
        }

        json_response([
            'id' => $estimateId,
            'estimate_no' => $estimateNo,
            'total_amount' => $totals['total_amount'],
            'message' => 'Estimate created',
        ], 201);
    }

    if (preg_match('#^/api/estimates/([1-9][0-9]*)(/.*)?$#', $path, $m)) {
        $estimateId = (int) $m[1];
        $sub = $m[2] ?? '';
        $user = require_authenticated_user($db);

        if ($sub === '' && $method === 'GET') {
            require_permission_for($db, $user, 'estimates', 'view');
            $stmt = $db->prepare('
                SELECT e.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone, c.address AS client_address
                FROM estimates e
                LEFT JOIN clients c ON c.id = e.client_id
                WHERE e.id = :id
            ');
            $stmt->execute(['id' => $estimateId]);
            $est = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$est) {
                json_response(['detail' => 'Estimate not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
            $itStmt->execute(['id' => $estimateId]);
            $est['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            // If part of a multi-company batch, attach sibling estimates
            if (!empty($est['batch_id'])) {
                $sibStmt = $db->prepare('
                    SELECT id, estimate_no, company_name, company_logo, markup_percent, total_amount, status
                    FROM estimates
                    WHERE batch_id = :bid
                    ORDER BY markup_percent ASC, id ASC
                ');
                $sibStmt->execute(['bid' => $est['batch_id']]);
                $est['sibling_estimates'] = $sibStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $est['sibling_estimates'] = [];
            }

            json_response($est);
        }

        if ($sub === '/approve' && $method === 'POST') {
            require_permission_for($db, $user, 'estimates', 'edit');
            $stmt = $db->prepare('UPDATE estimates SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['st' => 'approved', 'id' => $estimateId]);
            json_response(['message' => 'Estimate approved']);
        }

        if ($sub === '/reject' && $method === 'POST') {
            require_permission_for($db, $user, 'estimates', 'edit');
            $stmt = $db->prepare('UPDATE estimates SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['st' => 'rejected', 'id' => $estimateId]);
            json_response(['message' => 'Estimate rejected']);
        }

        if ($sub === '/convert' && $method === 'POST') {
            require_permission_for($db, $user, 'invoices', 'create');

            $stmt = $db->prepare('SELECT * FROM estimates WHERE id = :id');
            $stmt->execute(['id' => $estimateId]);
            $est = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$est) {
                json_response(['detail' => 'Estimate not found'], 404);
            }
            if ($est['status'] !== 'approved') {
                json_response(['detail' => "Cannot convert estimate with status '{$est['status']}'. Must be approved first."], 400);
            }

            $nextInvId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
            $invNo = sprintf('INV-%s-%05d', date('Ym'), $nextInvId);
            $today = date('Y-m-d');

            $insInv = $db->prepare('
                INSERT INTO invoices (
                    invoice_no, client_id, estimate_id, project_id, invoice_date, due_date,
                    status, subtotal, discount_percent, discount_amount, tax_rate, tax_amount,
                    withholding_tax_rate, withholding_tax_amount, fed_rate, fed_amount,
                    total_amount, amount_paid, balance_due, terms_conditions, notes, created_by, created_at, updated_at
                ) VALUES (
                    :no, :cid, :eid, :pid, :idate, :ddate,
                    :status, :subtotal, :disc_pct, :disc_amt, :tr, :ta,
                    0, 0, 0, 0,
                    :total, 0, :total, :terms, :notes, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ');
            $insInv->execute([
                'no' => $invNo,
                'cid' => $est['client_id'],
                'eid' => $estimateId,
                'pid' => $est['project_id'],
                'idate' => $today,
                'ddate' => $today,
                'status' => 'draft',
                'subtotal' => $est['subtotal'],
                'disc_pct' => $est['discount_percent'],
                'disc_amt' => $est['discount_amount'],
                'tr' => $est['tax_rate'],
                'ta' => $est['tax_amount'],
                'total' => $est['total_amount'],
                'terms' => $est['terms_conditions'],
                'notes' => $est['notes'],
                'uid' => $user->id,
            ]);
            $invoiceId = (int) $db->lastInsertId();

            $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
            $itStmt->execute(['id' => $estimateId]);
            $estItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $insItem = $db->prepare('
                INSERT INTO invoice_items (
                    invoice_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                ) VALUES (
                    :iid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
                )
            ');
            foreach ($estItems as $ei) {
                $insItem->execute([
                    'iid' => $invoiceId,
                    'prid' => $ei['product_id'],
                    'desc' => $ei['description'],
                    'qty' => $ei['quantity'],
                    'unit' => $ei['unit'],
                    'price' => $ei['unit_price'],
                    'tr' => $ei['tax_rate'],
                    'ta' => $ei['tax_amount'],
                    'tp' => $ei['total_price'],
                ]);

                if ($ei['product_id']) {
                    try {
                        update_stock($db, (int) $ei['product_id'], (float) $ei['quantity'], MovementType::SALE_OUT, 'invoice', $invoiceId, "Invoice {$invNo}", $user->id);
                    } catch (Exception $e) {
                        // Stock log
                    }
                }
            }

            $updEst = $db->prepare('UPDATE estimates SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $updEst->execute(['st' => 'converted', 'id' => $estimateId]);

            json_response(['message' => 'Invoice created', 'invoice_id' => $invoiceId, 'invoice_no' => $invNo]);
        }

        if ($sub === '/pdf' && $method === 'GET') {
            $stmt = $db->prepare('
                SELECT e.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address
                FROM estimates e
                LEFT JOIN clients c ON c.id = e.client_id
                WHERE e.id = :id
            ');
            $stmt->execute(['id' => $estimateId]);
            $est = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$est) {
                json_response(['detail' => 'Estimate not found'], 404);
            }

            $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
            $itStmt->execute(['id' => $estimateId]);
            $est['items'] = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $pdf = generate_estimate_pdf($est);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $est['estimate_no'] . '.pdf"');
            echo $pdf;
            exit;
        }

        if ($sub === '' && $method === 'PUT') {
            require_permission_for($db, $user, 'estimates', 'edit');
            $body = request_json();

            $stmtCurr = $db->prepare('SELECT * FROM estimates WHERE id = :id');
            $stmtCurr->execute(['id' => $estimateId]);
            $curr = $stmtCurr->fetch(PDO::FETCH_ASSOC);
            if (!$curr) {
                json_response(['detail' => 'Estimate not found'], 404);
            }

            $clientId = array_key_exists('client_id', $body) && !empty($body['client_id']) ? (int) $body['client_id'] : (int) $curr['client_id'];
            $title = array_key_exists('title', $body) ? $body['title'] : $curr['title'];
            $date = array_key_exists('estimate_date', $body) && !empty($body['estimate_date']) ? (string) $body['estimate_date'] : $curr['estimate_date'];
            $validUntil = array_key_exists('valid_until', $body) ? (string) $body['valid_until'] : $curr['valid_until'];
            $terms = array_key_exists('terms_conditions', $body) ? $body['terms_conditions'] : $curr['terms_conditions'];
            $notes = array_key_exists('notes', $body) ? $body['notes'] : $curr['notes'];
            $discountPercent = array_key_exists('discount_percent', $body) ? (float) $body['discount_percent'] : (float) $curr['discount_percent'];
            $taxRate = array_key_exists('tax_rate', $body) ? (float) $body['tax_rate'] : (float) $curr['tax_rate'];

            $items = $body['items'] ?? null;

            if (is_array($items) && $items !== []) {
                // Calculate item totals and taxes for current estimate
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

                $totals = calculate_invoice_tax($calculated, $discountPercent, $taxRate, false, false);

                // Delete old items and insert updated items
                $delStmt = $db->prepare('DELETE FROM estimate_items WHERE estimate_id = :eid');
                $delStmt->execute(['eid' => $estimateId]);

                $insItem = $db->prepare('
                    INSERT INTO estimate_items (
                        estimate_id, product_id, description, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                    ) VALUES (
                        :eid, :prid, :desc, :qty, :unit, :price, :tr, :ta, :tp
                    )
                ');

                foreach ($calculated as $line) {
                    $item = $line['item'];
                    $insItem->execute([
                        'eid' => $estimateId,
                        'prid' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                        'desc' => $item['description'],
                        'qty' => (float) ($item['quantity'] ?? 0),
                        'unit' => (string) ($item['unit'] ?? 'pcs'),
                        'price' => (float) ($item['unit_price'] ?? 0),
                        'tr' => $taxRate,
                        'ta' => $line['tax_amount'],
                        'tp' => $line['total'],
                    ]);
                }

                // Update current estimate header and totals
                $updCurr = $db->prepare('
                    UPDATE estimates SET
                        client_id = :cid,
                        title = :title,
                        estimate_date = :edate,
                        valid_until = :vdate,
                        discount_percent = :disc_pct,
                        discount_amount = :disc_amt,
                        tax_rate = :tax_rate,
                        tax_amount = :tax_amt,
                        subtotal = :subtotal,
                        total_amount = :total,
                        terms_conditions = :terms,
                        notes = :notes,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $updCurr->execute([
                    'cid' => $clientId,
                    'title' => $title,
                    'edate' => $date,
                    'vdate' => $validUntil,
                    'disc_pct' => $totals['discount_percent'],
                    'disc_amt' => $totals['discount_amount'],
                    'tax_rate' => $totals['tax_rate'],
                    'tax_amt' => $totals['tax_amount'],
                    'subtotal' => $totals['subtotal'],
                    'total' => $totals['total_amount'],
                    'terms' => $terms,
                    'notes' => $notes,
                    'id' => $estimateId,
                ]);

                // Synchronize multi-company batch siblings if part of a set
                if (!empty($curr['batch_id'])) {
                    $batchId = $curr['batch_id'];
                    $stmtSib = $db->prepare('SELECT * FROM estimates WHERE batch_id = :bid AND id != :id');
                    $stmtSib->execute(['bid' => $batchId, 'id' => $estimateId]);
                    $siblings = $stmtSib->fetchAll(PDO::FETCH_ASSOC);

                    $currMarkup = (float) ($curr['markup_percent'] ?? 0.0);
                    $currDivisor = 1.0 + ($currMarkup / 100.0);
                    if ($currDivisor <= 0.0001) {
                        $currDivisor = 1.0;
                    }

                    foreach ($siblings as $sib) {
                        $sibId = (int) $sib['id'];
                        $sibMarkup = (float) ($sib['markup_percent'] ?? 0.0);
                        $sibMultiplier = 1.0 + ($sibMarkup / 100.0);

                        $sibCalculated = [];
                        foreach ($calculated as $line) {
                            $origIt = $line['item'];
                            $basePrice = $origIt['unit_price'] / $currDivisor;
                            $markedPrice = round($basePrice * $sibMultiplier, 2);
                            $qty = (float) ($origIt['quantity'] ?? 0);

                            $sLine = calculate_item_tax($markedPrice, $qty, $taxRate, false);
                            $sLine['item'] = array_merge($origIt, ['unit_price' => $markedPrice]);
                            $sibCalculated[] = $sLine;
                        }

                        $sibTotals = calculate_invoice_tax($sibCalculated, $discountPercent, $taxRate, false, false);

                        $delStmt->execute(['eid' => $sibId]);
                        foreach ($sibCalculated as $sLine) {
                            $sIt = $sLine['item'];
                            $insItem->execute([
                                'eid' => $sibId,
                                'prid' => !empty($sIt['product_id']) ? (int) $sIt['product_id'] : null,
                                'desc' => $sIt['description'],
                                'qty' => (float) ($sIt['quantity'] ?? 0),
                                'unit' => (string) ($sIt['unit'] ?? 'pcs'),
                                'price' => (float) ($sIt['unit_price'] ?? 0),
                                'tr' => $taxRate,
                                'ta' => $sLine['tax_amount'],
                                'tp' => $sLine['total'],
                            ]);
                        }

                        $updCurr->execute([
                            'cid' => $clientId,
                            'title' => $title,
                            'edate' => $date,
                            'vdate' => $validUntil,
                            'disc_pct' => $sibTotals['discount_percent'],
                            'disc_amt' => $sibTotals['discount_amount'],
                            'tax_rate' => $sibTotals['tax_rate'],
                            'tax_amt' => $sibTotals['tax_amount'],
                            'subtotal' => $sibTotals['subtotal'],
                            'total' => $sibTotals['total_amount'],
                            'terms' => $terms,
                            'notes' => $notes,
                            'id' => $sibId,
                        ]);
                    }
                }
            } else {
                // Scalar update only
                $allowed = ['title', 'estimate_date', 'valid_until', 'discount_percent', 'tax_rate', 'terms_conditions', 'notes', 'client_id'];
                $updates = [];
                $values = ['id' => $estimateId];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        $updates[] = "{$f} = :{$f}";
                        $values[$f] = $body[$f];
                    }
                }
                if ($updates !== []) {
                    $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                    $stmt = $db->prepare('UPDATE estimates SET ' . implode(', ', $updates) . ' WHERE id = :id');
                    $stmt->execute($values);
                }
            }

            json_response(['message' => 'Estimate updated successfully']);
        }

        if ($sub === '' && $method === 'DELETE') {
            require_permission_for($db, $user, 'estimates', 'delete');
            $stmt = $db->prepare('DELETE FROM estimate_items WHERE estimate_id = :id');
            $stmt->execute(['id' => $estimateId]);
            $stmt = $db->prepare('DELETE FROM estimates WHERE id = :id');
            $stmt->execute(['id' => $estimateId]);
            json_response(['message' => 'Estimate deleted']);
        }
    }

    return false;
}
