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
        $sql = 'SELECT e.*, c.name AS client_name, co.name AS company_name, p.name AS project_name, p.description AS project_description FROM estimates e LEFT JOIN clients c ON c.id = e.client_id LEFT JOIN companies co ON co.id = e.company_id LEFT JOIN projects p ON p.id = e.project_id WHERE 1=1';
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

        $taxRate = (float) ($body['tax_rate'] ?? 18.0);
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

        // Selected primary company ID (from user selection or default)
        $selectedPrimaryCompanyId = !empty($body['company_id']) ? (int) $body['company_id'] : 0;

        // If multi-company, we build 3 estimates for the 3 companies (Primary 0%, Supporting +2%, Supporting +3%)
        if ($isMultiCompany && count($companies) >= 3) {
            $batchId = 'GRP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // Reorder companies so the user-selected Primary Company is at index 0 (0% base markup)
            $primaryCompany = null;
            $otherCompanies = [];

            foreach ($companies as $co) {
                if ($primaryCompany === null && ($selectedPrimaryCompanyId > 0 ? ((int) $co['id'] === $selectedPrimaryCompanyId) : !empty($co['is_default']))) {
                    $primaryCompany = $co;
                } else {
                    $otherCompanies[] = $co;
                }
            }

            if ($primaryCompany === null) {
                $primaryCompany = array_shift($otherCompanies) ?? $companies[0];
            }

            // Target companies: Primary is at index 0, followed by the supporting companies
            $targetCompanies = array_merge([$primaryCompany], array_slice($otherCompanies, 0, 2));

            // Standard markups: Primary (Option 1) = 0%, Supporting (Option 2) = 2%, Supporting (Option 3) = 3%
            $markups = [0.0, 2.0, 3.0];
            $createdEstimates = [];
            $primaryEstimateId = null;
            $primaryEstimateNo = null;

            foreach ($targetCompanies as $idx => $co) {
                $markupPct = $markups[$idx];
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
                        company_id, company_name, company_logo, company_phone, company_email, company_website, company_whatsapp, company_address, company_ntn, company_strn,
                        batch_id, markup_percent, created_at, updated_at
                    ) VALUES (
                        :no, :cid, :pid, :title, :edate, :vdate,
                        :status, :subtotal, :disc_pct, :disc_amt, :tax_rate, :tax_amt,
                        :total, :terms, :notes, :uid,
                        :coid, :coname, :cologo, :cophone, :coemail, :cowebsite, :cowhatsapp, :coaddr, :conntn, :costrn,
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
                    'cophone' => $co['phone'] ?? '+92 22 3669326',
                    'coemail' => $co['email'] ?? 'sales@datapoinittechnology.com',
                    'cowebsite' => $co['website'] ?? 'https://datapointtechnology.com',
                    'cowhatsapp' => $co['whatsapp'] ?? ($co['mobile'] ?? '+923712566540'),
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
                        estimate_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                    ) VALUES (
                        :eid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
                    )
                ');
                foreach ($calculated as $line) {
                    $it = $line['item'];
                    $itemStmt->execute([
                        'eid' => $estId,
                        'prid' => !empty($it['product_id']) ? (int) $it['product_id'] : null,
                        'desc' => trim((string) ($it['description'] ?? 'Item')),
                        'mm' => !empty($it['model_make']) ? trim((string) $it['model_make']) : null,
                        'qty' => (float) ($it['quantity'] ?? 0),
                        'unit' => (string) ($it['unit'] ?? 'pcs'),
                        'price' => (float) ($it['unit_price'] ?? 0),
                        'tr' => $taxRate,
                        'ta' => 0.0,
                        'tp' => round(((float) ($it['quantity'] ?? 0)) * ((float) ($it['unit_price'] ?? 0)), 2),
                    ]);
                }

                $createdEstimates[] = [
                    'id' => $estId,
                    'estimate_no' => $estimateNo,
                    'company_name' => $co['name'],
                    'company_id' => $co['id'],
                    'is_primary' => ($idx === 0),
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
        $co = null;
        if ($selectedPrimaryCompanyId > 0) {
            foreach ($companies as $c) {
                if ((int) $c['id'] === $selectedPrimaryCompanyId) {
                    $co = $c;
                    break;
                }
            }
        }
        if ($co === null) {
            foreach ($companies as $c) {
                if (!empty($c['is_default'])) {
                    $co = $c;
                    break;
                }
            }
        }
        if ($co === null) {
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
        }

        $markupPct = 0.0;
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
                company_id, company_name, company_logo, company_phone, company_email, company_website, company_whatsapp, company_address, company_ntn, company_strn,
                batch_id, markup_percent, created_at, updated_at
            ) VALUES (
                :no, :cid, :pid, :title, :edate, :vdate,
                :status, :subtotal, :disc_pct, :disc_amt, :tax_rate, :tax_amt,
                :total, :terms, :notes, :uid,
                :coid, :coname, :cologo, :cophone, :coemail, :cowebsite, :cowhatsapp, :coaddr, :conntn, :costrn,
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
            'cophone' => $co['phone'] ?? '+92 22 3669326',
            'coemail' => $co['email'] ?? 'sales@datapoinittechnology.com',
            'cowebsite' => $co['website'] ?? 'https://datapointtechnology.com',
            'cowhatsapp' => $co['whatsapp'] ?? ($co['mobile'] ?? '+923712566540'),
            'coaddr' => $co['address'] ?? null,
            'conntn' => $co['ntn'] ?? null,
            'costrn' => $co['strn'] ?? null,
            'markup' => $markupPct,
        ]);
        $estimateId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare('
            INSERT INTO estimate_items (
                estimate_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
            ) VALUES (
                :eid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
            )
        ');
        foreach ($calculated as $line) {
            $item = $line['item'];
            $itemStmt->execute([
                'eid' => $estimateId,
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
        }

        json_response([
            'id' => $estimateId,
            'estimate_no' => $estimateNo,
            'company_id' => $co['id'],
            'company_name' => $co['name'],
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
                    SELECT id, estimate_no, company_id, company_name, company_logo, markup_percent, total_amount, status
                    FROM estimates
                    WHERE batch_id = :bid
                    ORDER BY markup_percent ASC, id ASC
                ');
                $sibStmt->execute(['bid' => $est['batch_id']]);
                $est['sibling_estimates'] = $sibStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $est['sibling_estimates'] = [];
            }

            if (empty($est['company_email']) || stripos($est['company_email'], 'example.com') !== false || $est['company_email'] === 'info@datapointtechnology.com') {
                $est['company_email'] = 'sales@datapoinittechnology.com';
            }
            if (empty($est['company_website'])) {
                $est['company_website'] = 'https://datapointtechnology.com';
            }
            if (empty($est['company_phone']) || $est['company_phone'] === '+92-316-7788990' || $est['company_phone'] === '+92 21 34567890') {
                $est['company_phone'] = '+92 22 3669326';
            }
            if (empty($est['company_whatsapp'])) {
                $est['company_whatsapp'] = '+923712566540';
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
            if ($est['status'] === 'converted') {
                json_response(['detail' => 'This estimate has already been converted to an invoice.'], 400);
            }
            if (in_array($est['status'], ['rejected', 'cancelled'], true)) {
                json_response(['detail' => "Cannot convert estimate with status '{$est['status']}'."], 400);
            }

            $itStmt = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id = :id');
            $itStmt->execute(['id' => $estimateId]);
            $estItems = $itStmt->fetchAll(PDO::FETCH_ASSOC);

            $taxRate = (float) ($est['tax_rate'] ?? 18.0);
            $discPct = (float) ($est['discount_percent'] ?? 0.0);
            $subtotal = (float) ($est['subtotal'] ?? 0.0);
            $discAmt = (float) ($est['discount_amount'] ?? 0.0);
            $taxAmt = (float) ($est['tax_amount'] ?? 0.0);
            $totalAmount = (float) ($est['total_amount'] ?? 0.0);

            // If totals are 0 or not calculated but items exist, recalculate accurately
            if (($totalAmount <= 0.0 || $subtotal <= 0.0) && !empty($estItems)) {
                $calcLines = [];
                foreach ($estItems as $ei) {
                    $qty = (float) ($ei['quantity'] ?? 0);
                    $price = (float) ($ei['unit_price'] ?? 0);
                    $calcLines[] = calculate_item_tax($price, $qty, $taxRate, false);
                }
                $totals = calculate_invoice_tax($calcLines, $discPct, $taxRate, false, false);
                $subtotal = $totals['subtotal'];
                $discAmt = $totals['discount_amount'];
                $taxAmt = $totals['tax_amount'];
                $totalAmount = $totals['total_amount'];
            }

            $nextInvId = (int) $db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
            $invNo = sprintf('INV-%s-%05d', date('Ym'), $nextInvId);
            $today = date('Y-m-d');
            $dueDate = !empty($est['valid_until']) ? (string) $est['valid_until'] : $today;

            $insInv = $db->prepare('
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
                    0, 0, 0, 0,
                    :total, 0, :total, :pterms, :notes, :terms,
                    :coid, :coname, :cologo, :cophone, :coemail,
                    :coaddr, :conntn, :costrn,
                    :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ');
            $insInv->execute([
                'no' => $invNo,
                'cid' => $est['client_id'],
                'eid' => $estimateId,
                'pid' => $est['project_id'],
                'title' => $est['title'] ?? null,
                'idate' => $today,
                'ddate' => $dueDate,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'disc_pct' => $discPct,
                'disc_amt' => $discAmt,
                'tr' => $taxRate,
                'ta' => $taxAmt,
                'total' => $totalAmount,
                'pterms' => $est['terms_conditions'] ?? null,
                'notes' => $est['notes'] ?? null,
                'terms' => $est['terms_conditions'] ?? null,
                'coid' => $est['company_id'] ?? null,
                'coname' => $est['company_name'] ?? null,
                'cologo' => $est['company_logo'] ?? null,
                'cophone' => $est['company_phone'] ?? null,
                'coemail' => $est['company_email'] ?? null,
                'coaddr' => $est['company_address'] ?? null,
                'conntn' => $est['company_ntn'] ?? null,
                'costrn' => $est['company_strn'] ?? null,
                'uid' => $user->id,
            ]);
            $invoiceId = (int) $db->lastInsertId();

            $insItem = $db->prepare('
                INSERT INTO invoice_items (
                    invoice_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                ) VALUES (
                    :iid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
                )
            ');
            foreach ($estItems as $ei) {
                $qty = (float) ($ei['quantity'] ?? 0);
                $price = (float) ($ei['unit_price'] ?? 0);
                $itemTotal = round($qty * $price, 2);

                $insItem->execute([
                    'iid' => $invoiceId,
                    'prid' => $ei['product_id'],
                    'desc' => $ei['description'],
                    'mm' => $ei['model_make'] ?? null,
                    'qty' => $qty,
                    'unit' => $ei['unit'] ?? 'pcs',
                    'price' => $price,
                    'tr' => $taxRate,
                    'ta' => 0.0,
                    'tp' => $itemTotal,
                ]);

                if (!empty($ei['product_id'])) {
                    try {
                        update_stock($db, (int) $ei['product_id'], $qty, MovementType::SALE_OUT, 'invoice', $invoiceId, "Invoice {$invNo}", $user->id);
                    } catch (Exception $e) {
                        // Stock log
                    }
                }
            }

            $updEst = $db->prepare('UPDATE estimates SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $updEst->execute(['st' => 'converted', 'id' => $estimateId]);

            json_response([
                'message' => 'Invoice created successfully',
                'invoice_id' => $invoiceId,
                'invoice_no' => $invNo,
                'total_amount' => $totalAmount,
                'subtotal' => $subtotal,
                'balance_due' => $totalAmount,
                'amount_paid' => 0.0,
            ]);
        }

        if ($sub === '/pdf' && $method === 'GET') {
            require_permission_for($db, $user, 'estimates', 'view');
            $stmt = $db->prepare('
                SELECT e.*, c.name AS client_name, c.company AS client_company, c.email AS client_email, c.phone AS client_phone, c.mobile AS client_mobile, c.address AS client_address, c.city AS client_city, c.ntn AS client_ntn, c.strn AS client_strn
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
                        estimate_id, product_id, description, model_make, quantity, unit, unit_price, tax_rate, tax_amount, total_price
                    ) VALUES (
                        :eid, :prid, :desc, :mm, :qty, :unit, :price, :tr, :ta, :tp
                    )
                ');

                foreach ($calculated as $line) {
                    $item = $line['item'];
                    $insItem->execute([
                        'eid' => $estimateId,
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

                // Resolve company details for update
                $targetCoId = array_key_exists('company_id', $body) && !empty($body['company_id']) ? (int) $body['company_id'] : (int) ($curr['company_id'] ?? 0);
                $coToSave = null;
                if ($targetCoId > 0) {
                    $stmtC = $db->prepare('SELECT * FROM companies WHERE id = :cid LIMIT 1');
                    $stmtC->execute(['cid' => $targetCoId]);
                    $coToSave = $stmtC->fetch(PDO::FETCH_ASSOC);
                }
                $coIdVal = $coToSave ? $coToSave['id'] : ($curr['company_id'] ?? null);
                $coNameVal = $coToSave ? $coToSave['name'] : ($curr['company_name'] ?? null);
                $coLogoVal = $coToSave ? ($coToSave['logo_url'] ?? '/static/img/logo.png') : ($curr['company_logo'] ?? null);
                $coPhoneVal = $coToSave ? ($coToSave['phone'] ?? null) : ($curr['company_phone'] ?? null);
                $coEmailVal = $coToSave ? ($coToSave['email'] ?? null) : ($curr['company_email'] ?? null);
                $coAddrVal = $coToSave ? ($coToSave['address'] ?? null) : ($curr['company_address'] ?? null);
                $coNtnVal = $coToSave ? ($coToSave['ntn'] ?? null) : ($curr['company_ntn'] ?? null);
                $coStrnVal = $coToSave ? ($coToSave['strn'] ?? null) : ($curr['company_strn'] ?? null);

                // Update current estimate header and totals
                $updCurr = $db->prepare('
                    UPDATE estimates SET
                        client_id = :cid,
                        project_id = :pid,
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
                        company_id = :coid,
                        company_name = :coname,
                        company_logo = :cologo,
                        company_phone = :cophone,
                        company_email = :coemail,
                        company_address = :coaddr,
                        company_ntn = :conntn,
                        company_strn = :costrn,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ');
                $updCurr->execute([
                    'cid' => $clientId,
                    'pid' => array_key_exists('project_id', $body) ? (!empty($body['project_id']) ? (int)$body['project_id'] : null) : ($curr['project_id'] ?? null),
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
                    'coid' => $coIdVal,
                    'coname' => $coNameVal,
                    'cologo' => $coLogoVal,
                    'cophone' => $coPhoneVal,
                    'coemail' => $coEmailVal,
                    'coaddr' => $coAddrVal,
                    'conntn' => $coNtnVal,
                    'costrn' => $coStrnVal,
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
                                'ta' => 0.0,
                                'tp' => round(((float) ($sIt['quantity'] ?? 0)) * ((float) ($sIt['unit_price'] ?? 0)), 2),
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
                $allowed = ['title', 'estimate_date', 'valid_until', 'discount_percent', 'tax_rate', 'terms_conditions', 'notes', 'client_id', 'project_id'];
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
