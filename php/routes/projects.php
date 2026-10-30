<?php

declare(strict_types=1);

function handle_projects_routes(string $method, string $path, PDO $db): bool
{
    // 1. Fetch available estimates and invoices for a specific client (to be selected/checked for a project)
    if ($method === 'GET' && $path === '/api/projects/client-items') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'projects', 'view');

        $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
        $currentProjectId = !empty($_GET['project_id']) ? (int)$_GET['project_id'] : null;

        if ($clientId <= 0) {
            json_response(['estimates' => [], 'invoices' => []]);
        }

        // Fetch client details
        $cStmt = $db->prepare('SELECT id, name, company, phone, email, ntn FROM clients WHERE id = :id');
        $cStmt->execute(['id' => $clientId]);
        $client = $cStmt->fetch(PDO::FETCH_ASSOC);

        // Fetch all estimates for this client
        $estStmt = $db->prepare('
            SELECT e.id, e.estimate_no, e.estimate_date, e.total_amount, e.status, e.project_id,
                   p.name AS current_project_name
            FROM estimates e
            LEFT JOIN projects p ON p.id = e.project_id
            WHERE e.client_id = :cid
            ORDER BY e.estimate_date DESC, e.id DESC
        ');
        $estStmt->execute(['cid' => $clientId]);
        $rawEstimates = $estStmt->fetchAll(PDO::FETCH_ASSOC);

        $estimates = array_map(function($e) use ($currentProjectId) {
            $isLinked = ($currentProjectId !== null && !empty($e['project_id']) && (int)$e['project_id'] === $currentProjectId);
            return [
                'id' => (int)$e['id'],
                'estimate_no' => (string)$e['estimate_no'],
                'estimate_date' => (string)$e['estimate_date'],
                'total_amount' => (float)$e['total_amount'],
                'status' => (string)$e['status'],
                'project_id' => $e['project_id'] ? (int)$e['project_id'] : null,
                'is_linked' => $isLinked,
                'linked_to_other' => (!empty($e['project_id']) && (int)$e['project_id'] !== $currentProjectId),
                'current_project_name' => (string)($e['current_project_name'] ?? ''),
            ];
        }, $rawEstimates);

        // Fetch all invoices for this client
        $invStmt = $db->prepare('
            SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total_amount, i.amount_paid, i.balance_due, i.status,
                   i.project_id, p.name AS current_project_name
            FROM invoices i
            LEFT JOIN projects p ON p.id = i.project_id
            WHERE i.client_id = :cid AND i.status != \'cancelled\'
            ORDER BY i.invoice_date DESC, i.id DESC
        ');
        $invStmt->execute(['cid' => $clientId]);
        $rawInvoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

        $invoices = array_map(function($i) use ($currentProjectId) {
            $isLinked = ($currentProjectId !== null && !empty($i['project_id']) && (int)$i['project_id'] === $currentProjectId);
            return [
                'id' => (int)$i['id'],
                'invoice_no' => (string)$i['invoice_no'],
                'invoice_date' => (string)$i['invoice_date'],
                'due_date' => (string)($i['due_date'] ?? ''),
                'total_amount' => (float)$i['total_amount'],
                'amount_paid' => (float)$i['amount_paid'],
                'balance_due' => (float)$i['balance_due'],
                'status' => (string)$i['status'],
                'project_id' => $i['project_id'] ? (int)$i['project_id'] : null,
                'is_linked' => $isLinked,
                'linked_to_other' => (!empty($i['project_id']) && (int)$i['project_id'] !== $currentProjectId),
                'current_project_name' => (string)($i['current_project_name'] ?? ''),
            ];
        }, $rawInvoices);

        json_response([
            'client' => $client,
            'estimates' => $estimates,
            'invoices' => $invoices,
        ]);
    }

    // 2. List Projects (optionally filtered by client_id)
    if ($method === 'GET' && $path === '/api/projects') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'projects', 'view');

        $clientId = !empty($_GET['client_id']) ? (int)$_GET['client_id'] : null;
        $sql = '
            SELECT pr.*, c.name AS client_name, c.company AS client_company, u.full_name AS created_by_name,
                   (SELECT COUNT(*) FROM estimates e WHERE e.project_id = pr.id) AS estimate_count,
                   (SELECT COALESCE(SUM(e.total_amount), 0) FROM estimates e WHERE e.project_id = pr.id) AS total_estimated,
                   (SELECT COUNT(*) FROM invoices i WHERE i.project_id = pr.id AND i.status != \'cancelled\') AS invoice_count,
                   (SELECT COALESCE(SUM(i.total_amount), 0) FROM invoices i WHERE i.project_id = pr.id AND i.status != \'cancelled\') AS total_invoiced,
                   (SELECT COALESCE(SUM(i.amount_paid), 0) FROM invoices i WHERE i.project_id = pr.id AND i.status != \'cancelled\') AS total_received,
                   (SELECT COALESCE(SUM(i.balance_due), 0) FROM invoices i WHERE i.project_id = pr.id AND i.status != \'cancelled\') AS total_due
            FROM projects pr
            LEFT JOIN clients c ON c.id = pr.client_id
            LEFT JOIN users u ON u.id = pr.created_by
        ';
        $params = [];
        if ($clientId) {
            $sql .= ' WHERE pr.client_id = :cid';
            $params['cid'] = $clientId;
        }
        $sql .= ' ORDER BY pr.created_at DESC, pr.id DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // 3. Create Project (must be assigned to an existing client, with optional checked estimates/invoices)
    if ($method === 'POST' && $path === '/api/projects') {
        $user = require_authenticated_user($db);
        require_permission_for($db, $user, 'projects', 'create');
        $body = request_json();

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            json_response(['detail' => 'Project name is required.'], 422);
        }

        $clientId = !empty($body['client_id']) ? (int)$body['client_id'] : null;
        if (!$clientId) {
            json_response(['detail' => 'Please select an existing client for this project.'], 422);
        }

        // Verify client exists
        $cCheck = $db->prepare('SELECT id FROM clients WHERE id = :id');
        $cCheck->execute(['id' => $clientId]);
        if (!$cCheck->fetchColumn()) {
            json_response(['detail' => 'Selected client not found in the system.'], 422);
        }

        $stmt = $db->prepare('
            INSERT INTO projects (
                name, description, client_id, start_date, end_date, status, budget, notes, created_by, created_at, updated_at
            ) VALUES (
                :name, :desc, :cid, :sdate, :edate, :status, :budget, :notes, :uid, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ');
        $stmt->execute([
            'name' => $name,
            'desc' => $body['description'] ?? null,
            'cid' => $clientId,
            'sdate' => $body['start_date'] ?? null,
            'edate' => $body['end_date'] ?? null,
            'status' => $body['status'] ?? 'planning',
            'budget' => (float) ($body['budget'] ?? 0),
            'notes' => $body['notes'] ?? null,
            'uid' => $user->id,
        ]);

        $projectId = (int) $db->lastInsertId();

        // Link checked estimates belonging to this client
        if (isset($body['estimate_ids']) && is_array($body['estimate_ids'])) {
            $estIds = array_filter(array_map('intval', $body['estimate_ids']), fn($id) => $id > 0);
            if (!empty($estIds)) {
                $placeholders = implode(',', array_fill(0, count($estIds), '?'));
                $linkEst = $db->prepare("UPDATE estimates SET project_id = ? WHERE id IN ($placeholders) AND client_id = ?");
                $linkEst->execute(array_merge([$projectId], $estIds, [$clientId]));
            }
        }

        // Link checked invoices belonging to this client
        if (isset($body['invoice_ids']) && is_array($body['invoice_ids'])) {
            $invIds = array_filter(array_map('intval', $body['invoice_ids']), fn($id) => $id > 0);
            if (!empty($invIds)) {
                $placeholders = implode(',', array_fill(0, count($invIds), '?'));
                $linkInv = $db->prepare("UPDATE invoices SET project_id = ? WHERE id IN ($placeholders) AND client_id = ?");
                $linkInv->execute(array_merge([$projectId], $invIds, [$clientId]));
            }
        }

        json_response([
            'id' => $projectId,
            'message' => 'Project created successfully with linked client items.',
        ], 201);
    }

    // 4. Project Single Item Routes
    if (preg_match('#^/api/projects/([1-9][0-9]*)$#', $path, $m)) {
        $projectId = (int) $m[1];

        if ($method === 'GET') {
            $user = require_authenticated_user($db);
            require_permission_for($db, $user, 'projects', 'view');
            $stmt = $db->prepare('
                SELECT pr.*, c.name AS client_name, c.company AS client_company, c.phone AS client_phone, c.email AS client_email,
                       u.full_name AS created_by_name
                FROM projects pr
                LEFT JOIN clients c ON c.id = pr.client_id
                LEFT JOIN users u ON u.id = pr.created_by
                WHERE pr.id = :id
            ');
            $stmt->execute(['id' => $projectId]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                json_response(['detail' => 'Project not found'], 404);
            }

            // Linked estimates
            $estStmt = $db->prepare('
                SELECT id, estimate_no, estimate_date, status, total_amount, subtotal, tax_amount
                FROM estimates
                WHERE project_id = :pid
                ORDER BY estimate_date DESC, id DESC
            ');
            $estStmt->execute(['pid' => $projectId]);
            $p['estimates'] = $estStmt->fetchAll(PDO::FETCH_ASSOC);

            // Linked invoices
            $invStmt = $db->prepare('
                SELECT id, invoice_no, invoice_date, due_date, status, total_amount, amount_paid, balance_due, fbr_status, fbr_invoice_no
                FROM invoices
                WHERE project_id = :pid AND status != \'cancelled\'
                ORDER BY invoice_date DESC, id DESC
            ');
            $invStmt->execute(['pid' => $projectId]);
            $p['invoices'] = $invStmt->fetchAll(PDO::FETCH_ASSOC);

            // Summary metrics
            $p['total_estimated'] = round(array_sum(array_column($p['estimates'], 'total_amount')), 2);
            $p['total_invoiced'] = round(array_sum(array_column($p['invoices'], 'total_amount')), 2);
            $p['total_received'] = round(array_sum(array_column($p['invoices'], 'amount_paid')), 2);
            $p['total_due'] = round(array_sum(array_column($p['invoices'], 'balance_due')), 2);

            json_response($p);
        }

        if ($method === 'DELETE') {
            $user = require_authenticated_user($db);
            require_permission_for($db, $user, 'projects', 'delete');

            // Unlink any estimates and invoices before deleting project
            $db->prepare('UPDATE estimates SET project_id = NULL WHERE project_id = :id')->execute(['id' => $projectId]);
            $db->prepare('UPDATE invoices SET project_id = NULL WHERE project_id = :id')->execute(['id' => $projectId]);

            $stmt = $db->prepare('DELETE FROM projects WHERE id = :id');
            $stmt->execute(['id' => $projectId]);
            json_response(['message' => 'Project deleted and linked records unlinked']);
        }

        if ($method === 'PUT') {
            $user = require_authenticated_user($db);
            require_permission_for($db, $user, 'projects', 'edit');
            $body = request_json();

            // Fetch current project to check client
            $curStmt = $db->prepare('SELECT client_id FROM projects WHERE id = :id');
            $curStmt->execute(['id' => $projectId]);
            $curClientId = $curStmt->fetchColumn();

            $clientId = array_key_exists('client_id', $body) && !empty($body['client_id']) ? (int)$body['client_id'] : $curClientId;

            $allowed = ['name', 'description', 'client_id', 'start_date', 'end_date', 'status', 'budget', 'notes'];
            $updates = [];
            $values = ['id' => $projectId];
            foreach ($allowed as $f) {
                if (array_key_exists($f, $body)) {
                    $updates[] = "{$f} = :{$f}";
                    $values[$f] = $f === 'client_id' && empty($body[$f]) ? null : $body[$f];
                }
            }
            if ($updates !== []) {
                $updates[] = 'updated_at = CURRENT_TIMESTAMP';
                $stmt = $db->prepare('UPDATE projects SET ' . implode(', ', $updates) . ' WHERE id = :id');
                $stmt->execute($values);
            }

            // Sync checked estimates for this client
            if (isset($body['estimate_ids']) && is_array($body['estimate_ids'])) {
                $estIds = array_filter(array_map('intval', $body['estimate_ids']), fn($id) => $id > 0);
                // Unlink all current
                $db->prepare('UPDATE estimates SET project_id = NULL WHERE project_id = ?')->execute([$projectId]);
                // Link selected
                if (!empty($estIds)) {
                    $placeholders = implode(',', array_fill(0, count($estIds), '?'));
                    $db->prepare("UPDATE estimates SET project_id = ? WHERE id IN ($placeholders) AND client_id = ?")
                       ->execute(array_merge([$projectId], $estIds, [$clientId]));
                }
            }

            // Sync checked invoices for this client
            if (isset($body['invoice_ids']) && is_array($body['invoice_ids'])) {
                $invIds = array_filter(array_map('intval', $body['invoice_ids']), fn($id) => $id > 0);
                // Unlink all current
                $db->prepare('UPDATE invoices SET project_id = NULL WHERE project_id = ?')->execute([$projectId]);
                // Link selected
                if (!empty($invIds)) {
                    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
                    $db->prepare("UPDATE invoices SET project_id = ? WHERE id IN ($placeholders) AND client_id = ?")
                       ->execute(array_merge([$projectId], $invIds, [$clientId]));
                }
            }

            json_response(['message' => 'Project and linked items updated successfully.']);
        }
    }

    return false;
}
