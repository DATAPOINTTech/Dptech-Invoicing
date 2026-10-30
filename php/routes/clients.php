<?php

declare(strict_types=1);

function get_client_duplicates_data(PDO $db): array
{
    $clients = $db->query('SELECT * FROM clients WHERE is_active = 1 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if (count($clients) < 2) {
        return [];
    }

    // Get item counts per client
    $invCounts = [];
    $stmtInv = $db->query("SELECT client_id, COUNT(*) as cnt FROM invoices WHERE status != 'cancelled' GROUP BY client_id");
    while ($r = $stmtInv->fetch(PDO::FETCH_ASSOC)) {
        $invCounts[(int)$r['client_id']] = (int)$r['cnt'];
    }

    $estCounts = [];
    $stmtEst = $db->query("SELECT client_id, COUNT(*) as cnt FROM estimates GROUP BY client_id");
    while ($r = $stmtEst->fetch(PDO::FETCH_ASSOC)) {
        $estCounts[(int)$r['client_id']] = (int)$r['cnt'];
    }

    $projCounts = [];
    $stmtProj = $db->query("SELECT client_id, COUNT(*) as cnt FROM projects GROUP BY client_id");
    while ($r = $stmtProj->fetch(PDO::FETCH_ASSOC)) {
        $projCounts[(int)$r['client_id']] = (int)$r['cnt'];
    }

    // Attach counts to each client
    foreach ($clients as &$c) {
        $cid = (int)$c['id'];
        $c['invoice_count'] = $invCounts[$cid] ?? 0;
        $c['estimate_count'] = $estCounts[$cid] ?? 0;
        $c['project_count'] = $projCounts[$cid] ?? 0;
        $c['total_activity'] = $c['invoice_count'] + $c['estimate_count'] + $c['project_count'];
    }
    unset($c);

    // Disjoint Set (Union-Find) clustering
    $parent = [];
    foreach ($clients as $c) {
        $parent[$c['id']] = $c['id'];
    }

    $find = function($i) use (&$parent, &$find) {
        if ($parent[$i] === $i) return $i;
        return $parent[$i] = $find($parent[$i]);
    };

    $union = function($i, $j) use (&$parent, &$find) {
        $rootI = $find($i);
        $rootJ = $find($j);
        if ($rootI !== $rootJ) {
            $parent[$rootI] = $rootJ;
        }
    };

    // Group by normalized name
    $nameMap = [];
    foreach ($clients as $c) {
        $normName = strtolower(trim((string)$c['name']));
        if ($normName !== '') {
            if (isset($nameMap[$normName])) {
                $union($c['id'], $nameMap[$normName]);
            } else {
                $nameMap[$normName] = $c['id'];
            }
        }
    }

    // Group by normalized company
    $compMap = [];
    foreach ($clients as $c) {
        $normComp = strtolower(trim((string)($c['company'] ?? '')));
        if ($normComp !== '') {
            if (isset($compMap[$normComp])) {
                $union($c['id'], $compMap[$normComp]);
            } else {
                $compMap[$normComp] = $c['id'];
            }
        }
    }

    // Group clusters
    $clusters = [];
    foreach ($clients as $c) {
        $root = $find($c['id']);
        $clusters[$root][] = $c;
    }

    // Filter to clusters with 2 or more clients
    $duplicateGroups = [];
    foreach ($clusters as $group) {
        if (count($group) > 1) {
            usort($group, function($a, $b) {
                if ($b['total_activity'] !== $a['total_activity']) {
                    return $b['total_activity'] <=> $a['total_activity'];
                }
                return $a['id'] <=> $b['id'];
            });

            $matchReasons = [];
            $names = array_unique(array_map(fn($x) => strtolower(trim((string)$x['name'])), $group));
            if (count($names) < count($group)) {
                $matchReasons[] = 'Duplicate Client Name: "' . $group[0]['name'] . '"';
            }
            $companies = array_filter(array_map(fn($x) => strtolower(trim((string)($x['company'] ?? ''))), $group));
            if (count(array_unique($companies)) < count($companies)) {
                $matchReasons[] = 'Duplicate Company Name: "' . ($group[0]['company'] ?: 'Company Match') . '"';
            }

            $duplicateGroups[] = [
                'reason' => implode(' & ', $matchReasons) ?: 'Duplicate Name or Company',
                'suggested_primary_id' => $group[0]['id'],
                'clients' => $group
            ];
        }
    }

    return $duplicateGroups;
}

function handle_clients_routes(string $method, string $path, PDO $db): bool
{
    // GET /api/clients/duplicates - Detect duplicate clients by name or company
    if ($method === 'GET' && $path === '/api/clients/duplicates') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'view');

        $groups = get_client_duplicates_data($db);
        json_response([
            'duplicate_groups' => $groups,
            'total_groups' => count($groups),
            'total_duplicate_clients' => array_sum(array_map(fn($g) => count($g['clients']), $groups))
        ]);
    }

    // POST /api/clients/merge - Merge duplicate clients with permission into a primary client
    if ($method === 'POST' && $path === '/api/clients/merge') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'edit');

        $body = request_json();
        $primaryId = (int) ($body['primary_id'] ?? 0);
        $dupIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($body['duplicate_ids'] ?? [])),
            fn($id) => $id > 0 && $id !== $primaryId
        )));

        if ($primaryId <= 0 || empty($dupIds)) {
            json_response(['detail' => 'primary_id and at least one duplicate_id are required'], 422);
        }

        if (empty($body['confirm_merge'])) {
            json_response(['detail' => 'Explicit user permission confirmation (confirm_merge: true) is required to execute client merge.'], 422);
        }

        $pStmt = $db->prepare('SELECT * FROM clients WHERE id = :id AND is_active = 1 LIMIT 1');
        $pStmt->execute(['id' => $primaryId]);
        $primaryClient = $pStmt->fetch(PDO::FETCH_ASSOC);
        if (!$primaryClient) {
            json_response(['detail' => 'Primary client not found or inactive'], 404);
        }

        $placeholders = implode(',', array_fill(0, count($dupIds), '?'));
        $dStmt = $db->prepare("SELECT * FROM clients WHERE id IN ({$placeholders})");
        $dStmt->execute($dupIds);
        $dupClients = $dStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dupClients)) {
            json_response(['detail' => 'No matching duplicate clients found to merge'], 404);
        }

        $db->beginTransaction();
        try {
            // 1. Reassign Invoices
            $invStmt = $db->prepare("UPDATE invoices SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id IN ({$placeholders})");
            $invStmt->execute(array_merge([$primaryId], $dupIds));
            $invoicesMoved = $invStmt->rowCount();

            // 2. Reassign Estimates
            $estStmt = $db->prepare("UPDATE estimates SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id IN ({$placeholders})");
            $estStmt->execute(array_merge([$primaryId], $dupIds));
            $estimatesMoved = $estStmt->rowCount();

            // 3. Reassign Projects
            $projStmt = $db->prepare("UPDATE projects SET client_id = ?, updated_at = CURRENT_TIMESTAMP WHERE client_id IN ({$placeholders})");
            $projStmt->execute(array_merge([$primaryId], $dupIds));
            $projectsMoved = $projStmt->rowCount();

            // 4. Reassign Agent Conversations
            try {
                $convStmt = $db->prepare("UPDATE agent_conversations SET client_id = ? WHERE client_id IN ({$placeholders})");
                $convStmt->execute(array_merge([$primaryId], $dupIds));
            } catch (\Throwable $e) {}

            // 5. Consolidate contact info into primary client if fields are empty
            $fieldsToFill = ['company', 'phone', 'mobile', 'email', 'address', 'city', 'province', 'ntn', 'strn'];
            $updates = [];
            $updateParams = ['id' => $primaryId];

            foreach ($fieldsToFill as $f) {
                if (empty($primaryClient[$f])) {
                    foreach ($dupClients as $dc) {
                        if (!empty($dc[$f])) {
                            $updates[] = "{$f} = :{$f}";
                            $updateParams[$f] = $dc[$f];
                            $primaryClient[$f] = $dc[$f];
                            break;
                        }
                    }
                }
            }

            if (!empty($updates)) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $updPrimary = $db->prepare("UPDATE clients SET " . implode(', ', $updates) . " WHERE id = :id");
                $updPrimary->execute($updateParams);
            }

            // 6. Deactivate duplicate clients with audit trail note
            $auditNote = sprintf(" [Merged into Client #%d (%s) with user permission by %s on %s]", $primaryId, $primaryClient['name'], $user->username ?? 'admin', date('Y-m-d H:i:s'));
            $deactStmt = $db->prepare("
                UPDATE clients 
                SET is_active = 0, 
                    notes = COALESCE(notes || ' ', '') || ?, 
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id IN ({$placeholders})
            ");
            $deactStmt->execute(array_merge([$auditNote], $dupIds));

            $db->commit();

            json_response([
                'message' => "Successfully merged " . count($dupClients) . " duplicate client(s) into {$primaryClient['name']}.",
                'primary_client' => $primaryClient,
                'invoices_moved' => $invoicesMoved,
                'estimates_moved' => $estimatesMoved,
                'projects_moved' => $projectsMoved,
                'merged_client_ids' => $dupIds
            ]);
        } catch (\Throwable $e) {
            $db->rollBack();
            json_response(['detail' => 'Failed to merge duplicate clients: ' . $e->getMessage()], 500);
        }
    }

    if ($method === 'GET' && $path === '/api/clients') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'view');

        $search = trim((string) ($_GET['search'] ?? ''));
        if ($search !== '') {
            $stmt = $db->prepare('
                SELECT * FROM clients 
                WHERE is_active = 1 
                  AND (name LIKE :q OR company LIKE :q OR phone LIKE :q OR mobile LIKE :q OR email LIKE :q OR city LIKE :q)
                ORDER BY name ASC, id DESC
            ');
            $stmt->execute(['q' => "%{$search}%"]);
        } else {
            $stmt = $db->query('SELECT * FROM clients WHERE is_active = 1 ORDER BY name ASC, id DESC');
        }
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($method === 'POST' && $path === '/api/clients') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'clients', 'create');
        $body = request_json();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'name is required'], 422);
        }
        $company = trim((string) ($body['company'] ?? ''));

        // Prevent Duplicate Client Name or Company Name
        $dupStmt = $db->prepare('
            SELECT id, name, company 
            FROM clients 
            WHERE is_active = 1 
              AND (
                LOWER(TRIM(name)) = LOWER(:name)
                OR (
                  :comp != "" 
                  AND company IS NOT NULL 
                  AND TRIM(company) != "" 
                  AND LOWER(TRIM(company)) = LOWER(:comp)
                )
              )
            LIMIT 1
        ');
        $dupStmt->execute(['name' => $name, 'comp' => $company]);
        $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $isNameMatch = (strcasecmp(trim((string)$existing['name']), $name) === 0);
            $field = $isNameMatch ? 'Client Name' : 'Company Name';
            $val = $isNameMatch ? $existing['name'] : $existing['company'];
            json_response([
                'detail' => "Duplicate not allowed: A client with {$field} '{$val}' already exists (#{$existing['id']}: {$existing['name']}" . (!empty($existing['company']) ? " - {$existing['company']}" : "") . "). Please merge with permission or use the existing client.",
                'duplicate' => [
                    'id' => (int) $existing['id'],
                    'name' => $existing['name'],
                    'company' => $existing['company'],
                    'matched_field' => $field
                ]
            ], 409);
        }

        $columns = ['name', 'company', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'ntn', 'strn', 'notes', 'is_active'];
        $values = ['name' => $name];
        foreach (array_slice($columns, 1) as $col) {
            if (array_key_exists($col, $body)) {
                $values[$col] = $col === 'is_active' ? (int) (bool) $body[$col] : $body[$col];
            }
        }
        $fields = array_keys($values);
        $stmt = $db->prepare('INSERT INTO clients (' . implode(',', $fields) . ', created_at, updated_at) VALUES (:' . implode(',:', $fields) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute($values);
        json_response([
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'company' => $company,
            'message' => 'Client created'
        ], 201);
    }

    if (preg_match('#^/api/clients/([1-9][0-9]*)$#', $path, $match)) {
        $clientId = (int) $match[1];
        if ($method === 'GET') {
            $user = require_authenticated_user($db);
            require_permission_for($db, $user, 'clients', 'view');
            $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                json_response(['detail' => 'Client not found'], 404);
            }
            json_response($client);
        }

        $user = require_authenticated_user($db);
        if ($method === 'DELETE') {
            require_permission_for($db, $user, 'clients', 'delete');
            $stmt = $db->prepare('UPDATE clients SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute(['id' => $clientId]);
            json_response(['message' => 'Client deactivated']);
        }

        if ($method === 'PUT') {
            require_permission_for($db, $user, 'clients', 'edit');
            $body = request_json();

            // Check current client
            $currStmt = $db->prepare('SELECT id, name, company FROM clients WHERE id = :id LIMIT 1');
            $currStmt->execute(['id' => $clientId]);
            $currentClient = $currStmt->fetch(PDO::FETCH_ASSOC);
            if (!$currentClient) {
                json_response(['detail' => 'Client not found'], 404);
            }

            $checkName = array_key_exists('name', $body) ? trim((string)$body['name']) : trim((string)$currentClient['name']);
            $checkCompany = array_key_exists('company', $body) ? trim((string)$body['company']) : trim((string)($currentClient['company'] ?? ''));

            if ($checkName === '') {
                json_response(['detail' => 'name cannot be empty'], 422);
            }

            // Prevent duplicate on update
            $dupStmt = $db->prepare('
                SELECT id, name, company 
                FROM clients 
                WHERE is_active = 1 
                  AND id != :id
                  AND (
                    LOWER(TRIM(name)) = LOWER(:name)
                    OR (
                      :comp != "" 
                      AND company IS NOT NULL 
                      AND TRIM(company) != "" 
                      AND LOWER(TRIM(company)) = LOWER(:comp)
                    )
                  )
                LIMIT 1
            ');
            $dupStmt->execute(['id' => $clientId, 'name' => $checkName, 'comp' => $checkCompany]);
            $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $isNameMatch = (strcasecmp(trim((string)$existing['name']), $checkName) === 0);
                $field = $isNameMatch ? 'Client Name' : 'Company Name';
                $val = $isNameMatch ? $existing['name'] : $existing['company'];
                json_response([
                    'detail' => "Duplicate not allowed: Another client with {$field} '{$val}' already exists (#{$existing['id']}: {$existing['name']}" . (!empty($existing['company']) ? " - {$existing['company']}" : "") . "). Please merge with permission or use distinct names.",
                    'duplicate' => [
                        'id' => (int) $existing['id'],
                        'name' => $existing['name'],
                        'company' => $existing['company'],
                        'matched_field' => $field
                    ]
                ], 409);
            }

            $allowed = ['name', 'company', 'email', 'phone', 'mobile', 'address', 'city', 'province', 'ntn', 'strn', 'notes', 'is_active'];
            $updates = [];
            $values = ['id' => $clientId];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $body)) {
                    $updates[] = "{$col} = :{$col}";
                    $values[$col] = $col === 'is_active' ? (int) (bool) $body[$col] : $body[$col];
                }
            }
            if ($updates === []) {
                json_response(['detail' => 'No fields to update'], 422);
            }
            $updates[] = 'updated_at = CURRENT_TIMESTAMP';
            $stmt = $db->prepare('UPDATE clients SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($values);

            $stmt = $db->prepare('SELECT * FROM clients WHERE id = :id');
            $stmt->execute(['id' => $clientId]);
            json_response($stmt->fetch(PDO::FETCH_ASSOC));
        }
    }

    return false;
}
